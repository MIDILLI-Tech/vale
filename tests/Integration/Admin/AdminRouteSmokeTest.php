<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;

#[CoversNothing]
final class AdminRouteSmokeTest extends TestCase
{
    private App $app;
    private static string $testDbPath;
    private static ?DbalConnection $db = null;

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vl_smoke_test_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['APP_KEY']   = bin2hex(random_bytes(32));
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = self::$testDbPath;

        putenv('APP_KEY=' . $_ENV['APP_KEY']);
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . self::$testDbPath);

        $phinxApp = new \Phinx\Console\PhinxApplication();
        $wrapper  = new \Phinx\Wrapper\TextWrapper($phinxApp, [
            'configuration' => dirname(__DIR__, 3) . '/phinx.php',
            'environment'   => 'default',
        ]);
        $wrapper->getMigrate();
        $wrapper->getSeed();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                self::$db->close();
            } catch (\Throwable) {
            }
            self::$db = null;
        }

        gc_collect_cycles();
        clearstatcache();

        @unlink(self::$testDbPath);
        @unlink(self::$testDbPath . '-wal');
        @unlink(self::$testDbPath . '-shm');
    }

    protected function setUp(): void
    {
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                fwrite(STDERR, "\n[FATAL] {$e['message']} at {$e['file']}:{$e['line']}\n");
            }
        });

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION['user_id']      = 1;
        $_SESSION['user_role']    = 'super_admin';
        $_SESSION['user']         = [
            'id'       => 1,
            'email'    => 'admin@example.com',
            'role'     => 'super_admin',
            'username' => 'admin',
        ];
        $_SESSION['_csrf_tokens'] = 'smoke-test-csrf-token';

        /** @var App $app */
        $app       = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $this->app = $app;

        if (self::$db === null) {
            self::$db = $app->getContainer()->get(DbalConnection::class);
        }
    }

    public function test_all_admin_get_routes_return_valid_status_codes(): void
    {
        $requestFactory = new RequestFactory();
        $routes         = $this->app->getRouteCollector()->getRoutes();

        $testedRoutes = 0;

        foreach ($routes as $route) {
            $methods = $route->getMethods();
            if (!in_array('GET', $methods, true)) {
                continue;
            }

            $pattern = $route->getPattern();

            // Test admin panel routes
            if (!str_starts_with($pattern, '/admin')) {
                continue;
            }

            // Skip routes that cannot render without request-specific input:
            // - emergency-reset requires a valid token in ENV
            // - analytics/list-rows is an AJAX endpoint that answers 400 without ?list=
            if (str_contains($pattern, 'emergency-reset') || str_contains($pattern, 'analytics/list-rows')) {
                continue;
            }

            // Replace path placeholders like {id}, {slug}, {path:.+} with dummy values
            $testPath = preg_replace('/\{[a-zA-Z0-9_]+(?::[^\}]+)?\}/', '1', $pattern);

            $_SESSION['user_id']      = 1;
            $_SESSION['user_role']    = 'super_admin';
            $_SESSION['user']         = [
                'id'       => 1,
                'email'    => 'admin@example.com',
                'role'     => 'super_admin',
                'username' => 'admin',
            ];
            $_SESSION['_csrf_tokens'] = 'smoke-test-csrf-token';
            unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['_flash']);

            $request  = $requestFactory->createRequest('GET', $testPath);
            $response = $this->app->handle($request);

            $status = $response->getStatusCode();

            $isParameterized = preg_match('/\{[a-zA-Z0-9_]+(?::[^\}]+)?\}/', $pattern) === 1;

            if ($isParameterized) {
                // Parameterized routes (e.g. /posts/1/edit) may return 404 if entity #1 is not seeded, but must not fail with 500 or 405
                $this->assertLessThan(
                    500,
                    $status,
                    sprintf('Route "GET %s" returned HTTP %d server error.', $testPath, $status)
                );
                $this->assertNotEquals(
                    405,
                    $status,
                    sprintf('Route "GET %s" returned HTTP 405 Method Not Allowed.', $testPath)
                );
            } else {
                // Static GET endpoints must return 2xx or 3xx status
                $this->assertLessThan(
                    400,
                    $status,
                    sprintf('Static route "GET %s" returned HTTP %d error.', $testPath, $status)
                );
            }

            $testedRoutes++;
        }

        $this->assertGreaterThan(10, $testedRoutes, 'Expected to test at least 10 admin GET routes.');
    }

    public function test_specific_admin_routes_and_methods(): void
    {
        $requestFactory = new RequestFactory();
        $csrfToken      = $_SESSION['_csrf_tokens'];

        // 1. GET /admin/mail should redirect (302) to settings
        $req      = $requestFactory->createRequest('GET', '/admin/mail');
        $res      = $this->app->handle($req);
        $status   = $res->getStatusCode();
        $location = $res->getHeaderLine('Location');

        $this->assertLessThan(400, $status, sprintf('GET /admin/mail returned HTTP %d', $status));
        $this->assertStringContainsString('/settings?tab=mail', $location);

        // 2. POST /admin/exchange-rates/refresh should not return 405/404/500
        $reqPost = $requestFactory->createRequest('POST', '/admin/exchange-rates/refresh')
            ->withHeader('X-CSRF-Token', $csrfToken);
        $resPost = $this->app->handle($reqPost);
        $this->assertLessThan(400, $resPost->getStatusCode(), sprintf('POST /admin/exchange-rates/refresh returned HTTP %d', $resPost->getStatusCode()));

        // 3. PUT /admin/exchange-rates/refresh should also accept PUT cleanly
        $reqPut = $requestFactory->createRequest('PUT', '/admin/exchange-rates/refresh')
            ->withHeader('X-CSRF-Token', $csrfToken);
        $resPut = $this->app->handle($reqPut);
        $this->assertLessThan(400, $resPut->getStatusCode(), sprintf('PUT /admin/exchange-rates/refresh returned HTTP %d', $resPut->getStatusCode()));
    }

    public function test_all_admin_post_and_put_routes_do_not_return_404_or_405_or_500(): void
    {
        $requestFactory = new RequestFactory();
        $routes         = $this->app->getRouteCollector()->getRoutes();
        $csrfToken      = $_SESSION['_csrf_tokens'];

        $testedRoutes = 0;

        // Route patterns that trigger outbound network calls (HTTP, SMTP, DNS)
        // and must be skipped to avoid test timeouts.
        $skipPatterns = [
            '/exchange-rates/refresh',
            '/update/check',
            '/update/apply',
            '/update/rollback',
            '/update/backup',
            '/update/backup/delete',
            '/analytics/download-geo-db',
            '/mail/test',
            '/mail/template/save',
            '/mail/template/reset',
            '/webhooks/test-preview',
            '/cron/run-now',
            '/newsletter/campaigns/',
            '/newsletter/automations/process',
            '/settings/import',
            '/settings/export',
            '/settings/verify-captcha',
        ];

        foreach ($routes as $route) {
            $methods = $route->getMethods();
            $pattern = $route->getPattern();

            if (!str_starts_with($pattern, '/admin')) {
                continue;
            }

            if (str_contains($pattern, 'emergency-reset')) {
                continue;
            }

            // Skip routes that trigger outbound network calls.
            $shouldSkip = false;
            foreach ($skipPatterns as $skip) {
                if (str_contains($pattern, $skip)) {
                    $shouldSkip = true;
                    break;
                }
            }
            if ($shouldSkip) {
                continue;
            }

            foreach (['POST', 'PUT'] as $method) {
                if (!in_array($method, $methods, true)) {
                    continue;
                }

                $testPath = preg_replace('/\{[a-zA-Z0-9_]+(?::[^\}]+)?\}/', '1', $pattern);

                $_SESSION['user_id']      = 1;
                $_SESSION['user_role']    = 'super_admin';
                $_SESSION['user']         = [
                    'id'       => 1,
                    'email'    => 'admin@example.com',
                    'role'     => 'super_admin',
                    'username' => 'admin',
                ];
                $_SESSION['_csrf_tokens'] = $csrfToken;
                unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['_flash']);

                $request  = $requestFactory->createRequest($method, $testPath)
                    ->withHeader('X-CSRF-Token', $csrfToken);

                // Suppress array to string conversion PHP warnings triggered when re-rendering Twig forms with empty synthetic POST arrays
                $oldHandler = set_error_handler(static function (int $errno, string $errstr): bool {
                    if ($errno === E_WARNING && str_contains($errstr, 'Array to string conversion')) {
                        return true;
                    }
                    return false;
                });

                try {
                    $response = $this->app->handle($request);
                } finally {
                    restore_error_handler();
                }

                $status = $response->getStatusCode();

                $isParameterized = preg_match('/\{[a-zA-Z0-9_]+(?::[^\}]+)?\}/', $pattern) === 1;

                if (!$isParameterized) {
                    $this->assertNotEquals(
                        404,
                        $status,
                        sprintf('Static route "%s %s" returned HTTP 404 Not Found.', $method, $testPath)
                    );
                }

                $this->assertNotEquals(
                    405,
                    $status,
                    sprintf('Route "%s %s" returned HTTP 405 Method Not Allowed.', $method, $testPath)
                );

                $this->assertLessThan(
                    500,
                    $status,
                    sprintf('Route "%s %s" returned HTTP %d server error.', $method, $testPath, $status)
                );

                $testedRoutes++;
            }
        }

        $this->assertGreaterThan(10, $testedRoutes, 'Expected to test at least 10 admin POST/PUT routes.');
    }
}
