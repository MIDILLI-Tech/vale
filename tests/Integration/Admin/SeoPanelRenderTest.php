<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;

/**
 * The SEO panel must render the friendly visibility checkboxes
 * (and no longer the technical robots dropdown) on edit forms.
 */
final class SeoPanelRenderTest extends TestCase
{
    private App $app;
    private static string $testDbPath;
    private static ?DbalConnection $db = null;

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vl_seopanel_' . bin2hex(random_bytes(8)) . '.sqlite';

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
        $_SESSION['_csrf_tokens'] = 'seopanel-test-csrf-token';
        unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['_flash']);

        /** @var App $app */
        $app       = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $this->app = $app;

        if (self::$db === null) {
            self::$db = $app->getContainer()->get(DbalConnection::class);
        }
    }

    public function testPageEditFormRendersVisibilityCheckboxes(): void
    {
        self::$db->insert(VL_DB_PREFIX . 'posts', [
            'author_id'    => 1,
            'title'        => 'SEO Panel Page',
            'slug'         => 'seo-panel-page',
            'content_type' => 'page',
            'status'       => 'published',
        ]);
        $pageId = (int) self::$db->lastInsertId();

        $request  = (new RequestFactory())->createRequest('GET', '/admin/pages/' . $pageId . '/edit');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        self::assertStringContainsString('seo[allow_index]', $body);
        self::assertStringContainsString('seo[allow_follow]', $body);
        self::assertStringContainsString('seo[show_sitemap]', $body);
        self::assertStringContainsString('Show this page in search results', $body);
        self::assertStringNotContainsString('seo[robots]', $body);
        self::assertStringNotContainsString('No Index, No Follow', $body);
    }
}
