<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Silently drop common scanner/probe requests before they reach routing.
 *
 * Bot noise (WordPress xmlrpc probes, router/IoT exploits, config-file
 * guesses) used to travel the entire middleware stack, generate a 404/405,
 * and dump a full Slim exception stack into the error log. This middleware
 * sits OUTSIDE the error middleware so matched paths never touch routing,
 * sessions, the database, or the log — they get a bare, uncacheable 404.
 */
final class ProbeBlockMiddleware implements MiddlewareInterface
{
    /**
     * Exact file names (matched case-insensitively against the last segment).
     *
     * @var list<string>
     */
    private const BLOCKED_EXACT = [
        'xmlrpc.php',          // WordPress pingback RCE/SSRF probes
        'xmlrpc-cgi.php',
        'wp-login.php',
        'wp-cron.php',
        'wp-config.php',
        'wp-config.php.bak',
        'wlwmanifest.xml',     // WordPress discovery
        'readme.html',         // WordPress version fingerprint
        'phpinfo.php',
        'info.php',
        'php.ini',
        'composer.json',
        'composer.lock',
        'config.inc.php',
        '.htpasswd',
        'id_rsa',
        '.git-credentials',
        'web.config',          // IIS probes
    ];

    /**
     * Path prefixes (matched against the lowercased path).
     *
     * @var list<string>
     */
    private const BLOCKED_PREFIXES = [
        '.env',                // dotenv files (.env, .env.bak, .env.local, …)
        '/.env',
        '/.git/',              // exposed git repository
        '/.svn/',
        '/.hg/',
        '/.idea/',
        '/.aws/',
        '/wp-admin/',          // WordPress admin (real admin lives at /admin)
        '/wp-content/',
        '/wp-includes/',
        '/cgi-bin/',
        '/boaform/',
        '/actuator/',          // Spring Boot actuators
        '/vendor/',            // composer deps leaking to web root
        '/storage/',           // app storage must never be web-reachable
        '/server-status',      // Apache status
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = strtolower($request->getUri()->getPath());

        // Strip the app base path (e.g. /vale) if installed in a subdirectory.
        $basePath = strtolower(rtrim(__app_base_path(), '/'));
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim($path, '/');

        // ACME challenges must pass through — certbot renewal depends on them.
        if (str_starts_with($path, '/.well-known/acme-challenge/')) {
            return $handler->handle($request);
        }

        $blocked = false;

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $blocked = true;
                break;
            }
        }

        if (!$blocked) {
            $segments = explode('/', $path);
            $last     = (string) end($segments);
            if (in_array($last, self::BLOCKED_EXACT, true)) {
                $blocked = true;
            }
        }

        if (!$blocked && preg_match('/\.(php\d*|phtml|phar)$/i', $path)) {
            // No real PHP entry point lives at the web root (Apache serves
            // existing files directly, so anything reaching PHP with a script
            // extension is a phantom file). Whitelist the genuine entry scripts.
            $last    = basename($path);
            $blocked = !in_array(strtolower($last), ['index.php', 'install.php', 'router.php'], true);
        }

        if (!$blocked) {
            return $handler->handle($request);
        }

        // Bare 404: no body, no stack trace, no session, no DB.
        $response = new Response(404);
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
