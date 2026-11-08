<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;

#[CoversNothing]
final class PaymentStripeMethodsEndpointTest extends TestCase
{
    private App $app;
    private static string $testDbPath;
    private static ?DbalConnection $db = null;

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vl_stripe_methods_test_' . bin2hex(random_bytes(8)) . '.sqlite';

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

    public function testFallsBackToCatalogWhenNoSecretKeyIsConfigured(): void
    {
        $this->setSetting('stripe_secret_key', '');
        $this->setUp();

        $res = $this->app->handle(
            (new RequestFactory())->createRequest('GET', '/admin/payment/stripe/methods')
        );

        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $res->getBody(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['ok']);
        self::assertSame('catalog', $payload['source']);

        $byKey   = array_column($payload['methods'], 'label', 'key');
        $catalog = (require dirname(__DIR__, 3) . '/config/reference-data.php')['stripe_payment_methods'];

        self::assertSame(array_keys($catalog), array_keys($byKey));
        self::assertSame($catalog['ideal'], $byKey['ideal']);
    }

    public function testServesCachedMethodsForTheConfiguredSecretKey(): void
    {
        $secretKey = 'sk_test_stripe_methods_endpoint';
        $this->setSetting('stripe_secret_key', $secretKey);
        $this->setUp();

        $cache    = $this->app->getContainer()->get(CacheInterface::class);
        $cacheKey = 'stripe_available_methods_' . sha1($secretKey);
        $cache->set($cacheKey, ['card', 'blik', 'paypal'], 60);

        try {
            $res = $this->app->handle(
                (new RequestFactory())->createRequest('GET', '/admin/payment/stripe/methods')
            );

            self::assertSame(200, $res->getStatusCode());

            $payload = json_decode((string) $res->getBody(), true);
            self::assertIsArray($payload);
            self::assertTrue($payload['ok']);
            self::assertSame('stripe', $payload['source']);

            $byKey   = array_column($payload['methods'], 'label', 'key');
            $catalog = (require dirname(__DIR__, 3) . '/config/reference-data.php')['stripe_payment_methods'];

            self::assertSame(['card', 'blik', 'paypal'], array_keys($byKey));
            self::assertSame($catalog['card'], $byKey['card']);
            self::assertSame('Blik', $byKey['blik'], 'Unknown methods fall back to a humanised label.');
        } finally {
            $cache->delete($cacheKey);
            $this->setSetting('stripe_secret_key', '');
        }
    }

    private function setSetting(string $key, string $value): void
    {
        $keyCol = self::$db->quoteIdentifier('key');
        $table  = VL_DB_PREFIX . 'settings';

        $exists = (int) self::$db->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE {$keyCol} = ?",
            [$key]
        );

        if ($exists > 0) {
            self::$db->update($table, ['value' => $value], [$keyCol => $key]);
            return;
        }

        self::$db->insert($table, [
            'group_name'  => 'payment',
            'key'         => $key,
            'value'       => $value,
            'type'        => 'string',
            'is_autoload' => 1,
        ]);
    }
}
