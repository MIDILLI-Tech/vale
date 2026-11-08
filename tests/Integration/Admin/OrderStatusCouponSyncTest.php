<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;

/**
 * Regression: admin status changes must keep coupon usage_count in sync and
 * must not fatal (syncCouponUsageForStatus was called without existing).
 */
#[CoversNothing]
final class OrderStatusCouponSyncTest extends TestCase
{
    private App $app;
    private static string $testDbPath;
    private static ?DbalConnection $db = null;
    private static int $orderId;
    private static string $couponCode = 'SYNC10';

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vl_order_status_sync_' . bin2hex(random_bytes(8)) . '.sqlite';

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
        $_SESSION['user_id']       = 1;
        $_SESSION['user_role']     = 'super_admin';
        $_SESSION['_csrf_tokens']  = 'sync-test-csrf';

        /** @var App $app */
        $app       = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $this->app = $app;

        if (self::$db === null) {
            self::$db = $app->getContainer()->get(DbalConnection::class);
            $this->seedOrderWithCoupon();
        }
    }

    private function seedOrderWithCoupon(): void
    {
        $p   = VL_DB_PREFIX;
        $now = date('Y-m-d H:i:s');

        self::$db->insert($p . 'coupons', [
            'code'       => self::$couponCode, 'type' => 'percent', 'amount' => 10,
            'uses_count' => 3, 'enabled' => 1, 'created_at' => $now,
        ]);

        self::$db->insert($p . 'orders', [
            'order_number'   => 'ORD-SYNC-1',
            'status'         => 'processing',
            'currency'       => 'USD',
            'total'          => 100,
            'coupon_code'    => self::$couponCode,
            'payment_method' => 'stripe',
            'paid_at'        => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        self::$orderId = (int) self::$db->lastInsertId();

        self::$db->insert($p . 'order_items', [
            'order_id' => self::$orderId, 'name' => 'Item', 'quantity' => 1,
            'price'    => 100, 'subtotal' => 100, 'total' => 100,
        ]);
    }

    private function putStatus(string $status): int
    {
        $request = (new RequestFactory())
            ->createRequest('POST', '/admin/orders/' . self::$orderId . '/status')
            ->withHeader('X-CSRF-Token', 'sync-test-csrf')
            ->withParsedBody(['status' => $status]);

        return $this->app->handle($request)->getStatusCode();
    }

    private function couponUses(): int
    {
        $row = self::$db->fetchAssociative(
            'SELECT uses_count FROM ' . VL_DB_PREFIX . 'coupons WHERE code = ?',
            [self::$couponCode]
        );
        return (int) ($row['uses_count'] ?? -1);
    }

    public function test_status_change_does_not_fatal_and_returns_coupon(): void
    {
        $status = $this->putStatus('cancelled');
        self::assertSame(302, $status);

        // Leaving paid state must return the coupon use.
        self::assertSame(2, $this->couponUses());

        $row = self::$db->fetchAssociative(
            'SELECT status, inventory_restored FROM ' . VL_DB_PREFIX . 'orders WHERE id = ?',
            [self::$orderId]
        );
        self::assertSame('cancelled', $row['status']);
        // Reversal listener claimed restoration.
        self::assertSame(1, (int) $row['inventory_restored']);
    }

    public function test_returned_order_can_reenter_paid_and_reconsume(): void
    {
        $this->putStatus('cancelled');
        self::assertSame(2, $this->couponUses());

        $status = $this->putStatus('processing');
        self::assertSame(302, $status);

        // Re-entering paid from a returned state consumes again.
        self::assertSame(3, $this->couponUses());
    }

    private function seedNeverPaidOrder(string $couponCode, int $usesCount): int
    {
        $p   = VL_DB_PREFIX;
        $now = date('Y-m-d H:i:s');

        self::$db->insert($p . 'coupons', [
            'code'       => $couponCode, 'type' => 'percent', 'amount' => 10,
            'uses_count' => $usesCount, 'enabled' => 1, 'created_at' => $now,
        ]);

        self::$db->insert($p . 'orders', [
            'order_number'   => 'ORD-NEVER-' . $couponCode,
            'status'         => 'pending',
            'currency'       => 'USD',
            'total'          => 100,
            'coupon_code'    => $couponCode,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $orderId = (int) self::$db->lastInsertId();

        self::$db->insert($p . 'order_items', [
            'order_id' => $orderId, 'name' => 'Item', 'quantity' => 1,
            'price'    => 100, 'subtotal' => 100, 'total' => 100,
        ]);

        return $orderId;
    }

    public function testNeverPaidOrderCancelledByAdminReturnsCoupon(): void
    {
        $orderId = $this->seedNeverPaidOrder('NEVER10', 1);

        $request = (new RequestFactory())
            ->createRequest('POST', '/admin/orders/' . $orderId . '/status')
            ->withHeader('X-CSRF-Token', 'sync-test-csrf')
            ->withParsedBody(['status' => 'cancelled']);

        self::assertSame(302, $this->app->handle($request)->getStatusCode());

        $uses = (int) self::$db->fetchOne(
            'SELECT uses_count FROM ' . VL_DB_PREFIX . 'coupons WHERE code = ?',
            ['NEVER10']
        );
        self::assertSame(0, $uses, 'A never-paid order moving to cancelled must return the reserved use.');

        $row = self::$db->fetchAssociative(
            'SELECT coupon_returned FROM ' . VL_DB_PREFIX . 'orders WHERE id = ?',
            [$orderId]
        );
        self::assertSame(1, (int) $row['coupon_returned']);
    }

    public function testNeverPaidCancelledOrderDeleteDoesNotDoubleReturn(): void
    {
        $orderId = $this->seedNeverPaidOrder('NEVER11', 1);

        // Cancel first (returns the use).
        $this->app->handle(
            (new RequestFactory())
                ->createRequest('POST', '/admin/orders/' . $orderId . '/status')
                ->withHeader('X-CSRF-Token', 'sync-test-csrf')
                ->withParsedBody(['status' => 'cancelled'])
        );

        // Then delete — must NOT return a second time.
        $this->app->handle(
            (new RequestFactory())
                ->createRequest('POST', '/admin/orders/' . $orderId . '/delete')
                ->withHeader('X-CSRF-Token', 'sync-test-csrf')
        );

        $uses = (int) self::$db->fetchOne(
            'SELECT uses_count FROM ' . VL_DB_PREFIX . 'coupons WHERE code = ?',
            ['NEVER11']
        );
        self::assertSame(0, $uses, 'Deleting an already-returned never-paid order must not double-return the use.');
    }

    public function testNeverPaidPendingOrderDeleteReturnsCoupon(): void
    {
        $orderId = $this->seedNeverPaidOrder('NEVER12', 1);

        // No status change — straight delete must return the reserved use.
        $this->app->handle(
            (new RequestFactory())
                ->createRequest('POST', '/admin/orders/' . $orderId . '/delete')
                ->withHeader('X-CSRF-Token', 'sync-test-csrf')
        );

        $uses = (int) self::$db->fetchOne(
            'SELECT uses_count FROM ' . VL_DB_PREFIX . 'coupons WHERE code = ?',
            ['NEVER12']
        );
        self::assertSame(0, $uses, 'Deleting a never-paid pending order must return the reserved use.');
    }
}
