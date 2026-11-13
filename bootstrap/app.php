<?php

declare(strict_types=1);
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use ValeApp\App\Controllers\Front\PostController;
use ValeApp\App\Middleware\BasePathMiddleware;
use ValeApp\App\Middleware\CartExpireMiddleware;
use ValeApp\App\Middleware\CsrfMiddleware;
use ValeApp\App\Middleware\FlashMiddleware;
use ValeApp\App\Middleware\LocaleMiddleware;
use ValeApp\App\Middleware\MaintenanceMiddleware;
use ValeApp\App\Middleware\PageCacheMiddleware;
use ValeApp\App\Middleware\PageviewMiddleware;
use ValeApp\App\Middleware\ProbeBlockMiddleware;
use ValeApp\App\Middleware\ProbeRateLimitMiddleware;
use ValeApp\App\Middleware\PseudoCronMiddleware;
use ValeApp\App\Middleware\RedirectMiddleware;
use ValeApp\App\Middleware\SecurityHeadersMiddleware;
use ValeApp\App\Middleware\TrailingSlashMiddleware;
use ValeApp\App\Middleware\TwoFactorForcedMiddleware;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Plugin\PluginLoader;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
/**
 * Vale — Application Bootstrap.
 *
 * Loads environment, builds DI container, creates Slim app,
 * registers middleware and routes.
 */

use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\Redirect302Exception;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

// ── Global helpers (env(), base_path(), site_url(), etc.) ─────────────
require_once dirname(__DIR__) . '/app/helpers.php';

// 1) Environment -----------------------------------------------------------
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// Mandatory configuration — return a generic 503 instead of a raw 500 when
// APP_URL or APP_KEY is missing/invalid. Details go to the server log only.
// APP_URL keeps canonical links stable (reset links, SSO callbacks,
// confirmations) and APP_KEY decrypts settings.
$configErrors = [];
try {
    __require_app_url();
} catch (\RuntimeException $e) {
    $configErrors[] = $e->getMessage();
}
try {
    __require_app_key();
} catch (\RuntimeException $e) {
    $configErrors[] = $e->getMessage();
}
if ($configErrors !== []) {
    vl_respond_config_error($configErrors);
}

// OPcache dev / debug revalidation
if (env('APP_ENV', 'production') !== 'production' || env('APP_DEBUG', false)) {
    if (function_exists('ini_set')) {
        ini_set('opcache.revalidate_freq', '0');
        ini_set('opcache.validate_timestamps', '1');
    }
}

// Baseline timezone — overridden from settings (below) once the container is built
date_default_timezone_set('UTC');

// Never print notices/warnings into the HTTP response body: stray output
// (deprecations, warnings) corrupts JSON endpoints. Errors are still logged
// (error_log / errors.log) and exceptions reach the client through Slim's
// ErrorMiddleware — display_errors is unrelated to that path.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

// Database table prefix — used globally by repositories and raw SQL
if (!defined('VL_DB_PREFIX')) {
    define('VL_DB_PREFIX', env('DB_PREFIX', 'vl_'));
}

// Session cookie ini defaults — applied before any middleware can call session_start()
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_lifetime', ((int) env('SESSION_LIFETIME', 120)) * 60);
    ini_set('session.cookie_httponly', env('SESSION_HTTPONLY', true) ? '1' : '0');
    ini_set('session.cookie_samesite', env('SESSION_SAMESITE', 'Lax'));
    if (env('SESSION_SECURE', !in_array(env('APP_ENV', 'production'), ['local', 'dev'], true))) {
        ini_set('session.cookie_secure', '1');
    }
}


// 2) DI Container ----------------------------------------------------------
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(dirname(__DIR__) . '/config/services.php');

if (env('APP_ENV', 'production') === 'production') {
    // Key the compiled class by the definitions file hash so definition
    // changes invalidate the cache automatically instead of causing
    // stale-definition runtime errors after deploys.
    $definitionsFile = dirname(__DIR__) . '/config/services.php';
    $containerBuilder->enableCompilation(
        dirname(__DIR__) . '/storage/cache/container',
        'CompiledContainer' . substr((string) md5_file($definitionsFile), 0, 16)
    );
}

$container = $containerBuilder->build();

// Apply configured timezone from settings (fallback: UTC).
try {
    $configuredTz = $container->get(DbalSettingsRepository::class)
        ->get('timezone', 'UTC');
    if (in_array($configuredTz, \DateTimeZone::listIdentifiers(), true)) {
        date_default_timezone_set($configuredTz);
    }
} catch (\Throwable) {
    // Database unavailable or APP_KEY invalid — keep UTC baseline
}

// Wire thumbnail resolver so Post entity can lazily resolve featured images without a circular dependency
Post::setThumbnailResolver(function (?int $mediaId, ?string $uploadsBase) use ($container): ?array {
    if ($mediaId === null || $mediaId === 0) {
        return null;
    }
    $mediaRepo = $container->get(MediaRepositoryInterface::class);
    $m         = $mediaRepo->findById($mediaId);
    if ($m === null) {
        return null;
    }
    $base = $uploadsBase ?? site_url('/uploads');
    return [
        // Prefer WebP renditions when they exist; fall back to the original.
        'url'          => $m->getThumbnailUrl('webp', $base) ?? $m->getUrl($base),
        'original_url' => $m->getUrl($base),
        // Never null when a record exists: degrade to the full-size URL rather
        // than rendering an empty <img src=""> when no variant was generated
        // (e.g. source image smaller than every configured size).
        'thumbnail'    => $m->getThumbnailUrl('webp_medium', $base)
            ?? $m->getThumbnailUrl('medium', $base)
            ?? $m->getUrl($base),
    ];
});

// 3) Slim App --------------------------------------------------------------
AppFactory::setContainer($container);
$app = AppFactory::create();
$container->set(App::class, $app);

// 4) Middleware (LIFO — last added runs first) -----------------------------
$isDebug = env('APP_DEBUG', false);
$isDev   = env('APP_ENV', 'production') === 'dev';

// Security headers (X-Frame-Options, X-Content-Type-Options, etc.) — runs on every response
$app->add(new SecurityHeadersMiddleware());

// Base path fixer — prefixes /admin redirects with APP_BASE_PATH (outermost — runs last on response)
$app->add(new BasePathMiddleware());

// Twig view rendering
$twig = $container->get(Twig::class);
$app->add(TwigMiddleware::create($app, $twig));

// 2FA Forced setup gate — must run after FlashMiddleware so session is populated
$app->add($container->get(TwoFactorForcedMiddleware::class));

// Flash messages & user info injection into Twig
$app->add($container->get(FlashMiddleware::class));

// CSRF protection
$app->add($container->get(CsrfMiddleware::class));

// Page cache (outermost — serves cached HTML before anything else)
$app->add($container->get(PageCacheMiddleware::class));

// Maintenance mode — runs before page cache so cached pages aren't served during maintenance
$app->add($container->get(MaintenanceMiddleware::class));

// Page-load pseudo-cron (mode B) — runs due jobs after response is sent
$app->add($container->get(PseudoCronMiddleware::class));
$app->add($container->get(CartExpireMiddleware::class));

// ── Request-method resolution trio (LIFO — last added = outermost = runs first)
// Execution order: BodyParsing -> MethodOverride -> Routing

// Routing (innermost of trio — sees overridden method)
$app->addRoutingMiddleware();

// Method override (PUT/DELETE via _METHOD form field)
$app->add(new MethodOverrideMiddleware());

// Body parsing (outermost of trio — parses form/JSON body first)
$app->addBodyParsingMiddleware();

// Locale detection — runs AFTER routing trio, so it's OUTER (executes BEFORE routing)
// This ensures the locale prefix is stripped from the URI BEFORE route matching
try {
    $lmw = $container->get(LocaleMiddleware::class);
    $app->add($lmw);
} catch (\Throwable $e) {
    if (env('APP_DEBUG', false)) {
        error_log('Locale middleware initialization failed: ' . $e->getMessage());
    }
}

// Redirects — outermost after locale detection, catches routing 404s (changed prefix paths etc.)
$app->add($container->get(RedirectMiddleware::class));

// Trailing-slash canonicalization — runs before locale detection so the
// locale prefix (e.g. /en/sayfa/) is preserved verbatim in the 301 target.
// No Vale route ends with "/", so stripping can never shadow a real route.
$app->add(new TrailingSlashMiddleware());

// Error handling — outermost, catches ALL exceptions including routing
$showDetail      = $isDev && $isDebug;
$errorMiddleware = $app->addErrorMiddleware($showDetail, true, true);

// ── Probe/scanner throttling (OUTERMOST — added last, runs first) ────────
// Sitting outside ErrorMiddleware means matched probes and throttled repeat
// offenders never reach routing/DB/sessions and never dump an exception
// stack into the error log. See ProbeBlockMiddleware for the path list.
$app->add($container->get(ProbeBlockMiddleware::class));
$app->add($container->get(ProbeRateLimitMiddleware::class));

if ($showDetail && class_exists(\Whoops\Run::class)) {
    // Dev: Whoops pretty errors — override Slim's default renderer
    $whoops = new \Whoops\Run();
    $whoops->allowQuit(false);
    $whoops->writeToOutput(false);
    $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler());

    $errorMiddleware->setDefaultErrorHandler(function (
        ServerRequestInterface $request,
        \Throwable $exception,
    ) use (
        $whoops,
        $app
    ) {
        $redirectEx = $exception instanceof Redirect302Exception
            ? $exception
            : ($exception->getPrevious() instanceof Redirect302Exception ? $exception->getPrevious() : null);

        if ($redirectEx !== null) {
            $response = $app->getResponseFactory()->createResponse(302);
            return $response->withHeader('Location', $redirectEx->getUrl());
        }

        $errorId = bin2hex(random_bytes(16));

        $logLine = '[' . $errorId . '] ' . date('[Y-m-d H:i:s] ') . $request->getMethod() . ' ' . $request->getUri()->getPath()
            . ' [500] ' . $exception::class . ': ' . $exception->getMessage() . "\n"
            . $exception->getTraceAsString() . "\n\n";

        @file_put_contents(
            dirname(__DIR__) . '/storage/logs/errors.log',
            $logLine,
            FILE_APPEND
        );

        error_log('[Whoops] ' . $request->getMethod() . ' ' . $request->getUri()->getPath() . ' :: ' . $exception::class . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
        $output   = $whoops->handleException($exception);
        $response = $app->getResponseFactory()->createResponse(500);
        $response->getBody()->write($output);
        return $response->withHeader('Content-Type', 'text/html');
    });
}

// Production error handler: render clean HTML pages instead of stack traces
if (!$showDetail) {
    $errorMiddleware->setDefaultErrorHandler(
        function (
            ServerRequestInterface $request,
            \Throwable $exception,
            bool $displayErrorDetails,
            bool $logErrors,
            bool $logErrorDetails
        ) use (
            $app,
            $twig,
            $isDebug
        ): ResponseInterface {
            $redirectEx = $exception instanceof Redirect302Exception
                ? $exception
                : ($exception->getPrevious() instanceof Redirect302Exception ? $exception->getPrevious() : null);

            if ($redirectEx !== null) {
                $response = $app->getResponseFactory()->createResponse(302);
                return $response->withHeader('Location', $redirectEx->getUrl());
            }

            $code = $exception instanceof HttpException ? $exception->getCode() : 500;
            if ($code < 400 || $code > 599) {
                $code = 500;
            }

            $errorId = bin2hex(random_bytes(16));

            $logLine = '[' . $errorId . '] ' . date('[Y-m-d H:i:s] ') . $request->getMethod() . ' ' . $request->getUri()->getPath()
                . ' [' . $code . '] ' . $exception::class . ': ' . $exception->getMessage() . "\n"
                . $exception->getTraceAsString() . "\n\n";

            // Always persist the full detail to storage/logs/errors.log —
            // production pages show only the error id.
            @file_put_contents(
                dirname(__DIR__) . '/storage/logs/errors.log',
                $logLine,
                FILE_APPEND
            );

            error_log($logLine);

            // Return JSON for cron routes instead of HTML
            $path = $request->getUri()->getPath();
            if (str_contains($path, '/cron/')) {
                $response = $app->getResponseFactory()->createResponse($code);
                $response->getBody()->write((string) json_encode([
                    'ok'    => false,
                    'error' => $exception->getMessage(),
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $templates = [
                'errors/' . $code . '.twig',
                'errors/generic.twig',
            ];
            $template = 'errors/generic.twig';
            foreach ($templates as $t) {
                if ($twig->getLoader()->exists($t)) {
                    $template = $t;
                    break;
                }
            }
            $response     = $app->getResponseFactory()->createResponse($code);
            $templateVars = ['error_code' => $code];
            if (in_array($code, [500, 403], true)) {
                $templateVars['error_id'] = $errorId;
            }
            return $twig->render($response, $template, $templateVars);
        }
    );
}

// Pageview tracking — truly outermost (added AFTER error middleware, LIFO):
// sees final responses including 404/500 errors and redirects so 3xx-5xx
// statuses land in analytics. Excluded prefixes still prevent self-tracking.
$app->add($container->get(PageviewMiddleware::class));

// ── Event listeners ────────────────────────────────────────────────────────
// Outbound webhooks + page-cache invalidation. Extracted so the CLI
// (php vale …) registers the same listeners as the web bootstrap.
(require __DIR__ . '/events.php')($container);

// 4.5) User plugins (content/plugins, content/mu-plugins)
// User-owned code that updates never touch. Loaded before the route files so
// plugin routes register ahead of the /{path:.+} catch-all at the bottom of
// this file. Any loader failure is contained: the site must still boot.
try {
    $container->get(PluginLoader::class)->load();
} catch (\Throwable $e) {
    error_log('[Vale plugins] Loader failed: ' . $e->getMessage());
}

// 5) Routes ----------------------------------------------------------------
$webRoutes   = require dirname(__DIR__) . '/routes/web.php';
$adminRoutes = require dirname(__DIR__) . '/routes/admin.php';
$apiRoutes   = require dirname(__DIR__) . '/routes/api.php';

$webRoutes($app);
$adminRoutes($app);
$apiRoutes($app);

// /{path} catch-all must be registered LAST so it never shadows static/group routes
// Supports nested parent/child pages: /parent-slug/child-slug
$pagePrefix = $container->get(DbalSettingsRepository::class)->get('routing_page_prefix', '');
$pagePrefix = $pagePrefix ? ('/' . ltrim($pagePrefix, '/')) : '';

$app->get($pagePrefix . '/{path:.+}', [PostController::class, 'showPage'])
    ->setName('page.show')
    ->add(function (ServerRequestInterface $req, RequestHandlerInterface $handler) use ($container): ResponseInterface {
        $s = $container->get(DbalSettingsRepository::class);
        if (($s->get('pages_enabled') ?? '1') === '0') {
            $twig = $container->get(Twig::class);
            $r    = new Response(404);
            return $twig->render($r, 'errors/feature-disabled.twig', [
                'feature' => 'pages',
                'title'   => 'Pages Disabled',
            ]);
        }
        return $handler->handle($req);
    });

return $app;
