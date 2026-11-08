<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;

#[CoversNothing]
final class SettingsControllerTest extends TestCase
{
    private App $app;
    private static string $testDbPath;
    private static ?DbalConnection $db = null;

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vl_settings_test_' . bin2hex(random_bytes(8)) . '.sqlite';

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
        $_SESSION['_csrf_tokens'] = 'test-token';

        /** @var App $app */
        $app       = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $this->app = $app;

        if (self::$db === null) {
            self::$db = $app->getContainer()->get(DbalConnection::class);
        }
    }

    public function testOptimizeAllRoute(): void
    {
        $requestFactory = new RequestFactory();
        $req            = $requestFactory->createRequest('POST', '/admin/optimize')
            ->withHeader('X-CSRF-Token', 'test-token');

        $res = $this->app->handle($req);

        $this->assertSame(302, $res->getStatusCode());
    }

    public function testClearAllCacheRoute(): void
    {
        $requestFactory = new RequestFactory();
        $req            = $requestFactory->createRequest('POST', '/admin/cache/clear')
            ->withHeader('X-CSRF-Token', 'test-token');

        $res = $this->app->handle($req);

        $this->assertSame(302, $res->getStatusCode());
    }

    public function testDbOptimizeRoute(): void
    {
        $requestFactory = new RequestFactory();
        $req            = $requestFactory->createRequest('POST', '/admin/maintenance/db-optimize')
            ->withHeader('X-CSRF-Token', 'test-token');

        $res = $this->app->handle($req);

        $this->assertSame(302, $res->getStatusCode());
    }

    public function testTimezoneSavePersistsValidValue(): void
    {
        $req = (new RequestFactory())->createRequest('PUT', '/admin/settings')
            ->withHeader('X-CSRF-Token', 'test-token')
            ->withParsedBody([
                '_group'    => 'general',
                'site_name' => 'Vale',
                'timezone'  => 'Europe/Istanbul',
            ]);

        $res = $this->app->handle($req);

        $this->assertSame(302, $res->getStatusCode());
        $stored = self::$db->fetchOne(
            'SELECT ' . self::$db->quoteIdentifier('value') . ' FROM ' . VL_DB_PREFIX . 'settings WHERE ' . self::$db->quoteIdentifier('key') . ' = ?',
            ['timezone'],
        );
        $this->assertSame('Europe/Istanbul', $stored);
    }

    public function testTimezoneSaveFallsBackToUtcForInvalidValue(): void
    {
        $req = (new RequestFactory())->createRequest('PUT', '/admin/settings')
            ->withHeader('X-CSRF-Token', 'test-token')
            ->withParsedBody([
                '_group'    => 'general',
                'site_name' => 'Vale',
                'timezone'  => 'Mars/Olympus_Mons',
            ]);

        $res = $this->app->handle($req);

        $this->assertSame(302, $res->getStatusCode());
        $stored = self::$db->fetchOne(
            'SELECT ' . self::$db->quoteIdentifier('value') . ' FROM ' . VL_DB_PREFIX . 'settings WHERE ' . self::$db->quoteIdentifier('key') . ' = ?',
            ['timezone'],
        );
        $this->assertSame('UTC', $stored);
    }

    public function testRootCategoryPrefixIsRejectedAndNothingIsSaved(): void
    {
        $keyCol = self::$db->quoteIdentifier('key');
        $valCol = self::$db->quoteIdentifier('value');
        $before = self::$db->fetchOne(
            "SELECT {$valCol} FROM " . VL_DB_PREFIX . "settings WHERE {$keyCol} = ?",
            ['routing_category_prefix'],
        );

        $req = (new RequestFactory())->createRequest('PUT', '/admin/settings')
            ->withHeader('X-CSRF-Token', 'test-token')
            ->withParsedBody([
                '_group'                  => 'seo',
                '_subtab'                 => 'redirects',
                'routing_category_prefix' => '/',
            ]);

        $res = $this->app->handle($req);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('/settings', $res->getHeaderLine('Location'));

        $after = self::$db->fetchOne(
            "SELECT {$valCol} FROM " . VL_DB_PREFIX . "settings WHERE {$keyCol} = ?",
            ['routing_category_prefix'],
        );
        $this->assertSame($before, $after);
        $this->assertNotEmpty($_SESSION['routing_prefix_errors'] ?? []);
    }

    public function testValidPrefixChangeIsSaved(): void
    {
        $keyCol = self::$db->quoteIdentifier('key');
        $valCol = self::$db->quoteIdentifier('value');

        $req = (new RequestFactory())->createRequest('PUT', '/admin/settings')
            ->withHeader('X-CSRF-Token', 'test-token')
            ->withParsedBody([
                '_group'             => 'seo',
                '_subtab'            => 'redirects',
                'routing_tag_prefix' => '/etiketler',
            ]);

        $res = $this->app->handle($req);

        $this->assertSame(302, $res->getStatusCode());
        $stored = self::$db->fetchOne(
            "SELECT {$valCol} FROM " . VL_DB_PREFIX . "settings WHERE {$keyCol} = ?",
            ['routing_tag_prefix'],
        );
        $this->assertSame('/etiketler', $stored);

        // Restore the seeded default so later tests see a clean state.
        self::$db->update(VL_DB_PREFIX . 'settings', ['value' => '/tag'], [$keyCol => 'routing_tag_prefix']);
    }

    public function testEnablingMultiLanguageIsBlockedByLocalePrefix(): void
    {
        $keyCol = self::$db->quoteIdentifier('key');
        $valCol = self::$db->quoteIdentifier('value');

        $multiBefore = self::$db->fetchOne(
            "SELECT {$valCol} FROM " . VL_DB_PREFIX . "settings WHERE {$keyCol} = ?",
            ['multi_language_content'],
        );
        // Plant a locale-colliding prefix directly, bypassing validation
        // (routing keys are not seeded, so insert rather than update),
        // and switch multi-language off so the enable path is exercised.
        // The app container is rebuilt afterwards so its settings cache
        // picks up the planted values (mirrors a fresh request).
        self::$db->insert(VL_DB_PREFIX . 'settings', [
            'group_name'  => 'ecommerce',
            'key'         => 'routing_shop_prefix',
            'value'       => '/tr',
            'type'        => 'string',
            'is_autoload' => 1,
        ]);
        self::$db->update(VL_DB_PREFIX . 'settings', ['value' => '0'], [$keyCol => 'multi_language_content']);
        $this->setUp();

        try {
            $req = (new RequestFactory())->createRequest('PUT', '/admin/settings')
                ->withHeader('X-CSRF-Token', 'test-token')
                ->withParsedBody([
                    '_group'                 => 'content',
                    'multi_language_content' => '1',
                ]);

            $res = $this->app->handle($req);

            $this->assertSame(302, $res->getStatusCode());
            $stored = self::$db->fetchOne(
                "SELECT {$valCol} FROM " . VL_DB_PREFIX . "settings WHERE {$keyCol} = ?",
                ['multi_language_content'],
            );
            $this->assertNotSame('1', (string) $stored);
        } finally {
            self::$db->delete(VL_DB_PREFIX . 'settings', [$keyCol => 'routing_shop_prefix']);
            self::$db->update(VL_DB_PREFIX . 'settings', ['value' => $multiBefore], [$keyCol => 'multi_language_content']);
        }
    }
}
