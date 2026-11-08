<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Shop;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Infrastructure\Database\Connection;
use ValeApp\Infrastructure\Persistence\DbalCouponRepository;

final class CouponRepositoryTest extends TestCase
{
    private static string $testDbPath;
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vl_coupon_repo_test_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['APP_KEY']   = bin2hex(random_bytes(32));
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = self::$testDbPath;
        putenv('APP_KEY=' . $_ENV['APP_KEY']);
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . self::$testDbPath);

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        $phinxApp = new \Phinx\Console\PhinxApplication();
        $wrapper  = new \Phinx\Wrapper\TextWrapper($phinxApp, [
            'configuration' => dirname(__DIR__, 3) . '/phinx.php',
            'environment'   => 'default',
        ]);
        $wrapper->getMigrate();

        self::$db = Connection::createFromEnv();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db)) {
            try {
                self::$db->close();
            } catch (\Throwable) {
            }
        }
        gc_collect_cycles();
        clearstatcache();
        @unlink(self::$testDbPath);
        @unlink(self::$testDbPath . '-wal');
        @unlink(self::$testDbPath . '-shm');
    }

    private function repository(): DbalCouponRepository
    {
        return new DbalCouponRepository(self::$db);
    }

    private function makeCoupon(string $code, ?int $maxUses, int $maxPerEmail = 0): Coupon
    {
        $coupon = new Coupon($code, 'percent', 10.0);
        $coupon->setMaxUses($maxUses);
        $coupon->setMaxUsesPerEmail($maxPerEmail);
        return $this->repository()->save($coupon);
    }

    private function insertUser(string $email): int
    {
        self::$db->insert(VL_DB_PREFIX . 'users', [
            'email'      => $email,
            'name'       => 'u_' . md5($email),
            'password'   => 'x',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) self::$db->lastInsertId();
    }

    private function insertOrder(string $couponCode, ?int $userId, ?string $billingEmail): int
    {
        $billing = $billingEmail !== null
            ? json_encode(['email' => $billingEmail])
            : null;

        self::$db->insert(VL_DB_PREFIX . 'orders', [
            'order_number' => 'ORD-' . uniqid(),
            'status'       => 'completed',
            'total'        => 100,
            'coupon_code'  => $couponCode,
            'user_id'      => $userId,
            'billing'      => $billing,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        return (int) self::$db->lastInsertId();
    }

    public function testIncrementUsesIfAvailableRespectsMaxUses(): void
    {
        $repo   = $this->repository();
        $coupon = $this->makeCoupon('MAX2', 2);

        self::assertTrue($repo->incrementUsesIfAvailable((int) $coupon->getId()));
        self::assertTrue($repo->incrementUsesIfAvailable((int) $coupon->getId()));

        $reloaded = $repo->findByCode('MAX2');
        self::assertSame(2, $reloaded?->getUsesCount());
        self::assertFalse($repo->incrementUsesIfAvailable((int) $coupon->getId()));
        self::assertSame(2, $repo->findByCode('MAX2')?->getUsesCount());
    }

    public function testIncrementUsesIfAvailableWithoutMaxAlwaysSucceeds(): void
    {
        $repo   = $this->repository();
        $coupon = $this->makeCoupon('NOLIMIT', null);

        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($repo->incrementUsesIfAvailable((int) $coupon->getId()));
        }
        self::assertSame(5, $repo->findByCode('NOLIMIT')?->getUsesCount());
    }

    public function testDecrementUsesFloorsAtZeroAndRollsBack(): void
    {
        $repo   = $this->repository();
        $coupon = $this->makeCoupon('ROLLBACK', 5);

        self::assertTrue($repo->incrementUsesIfAvailable((int) $coupon->getId()));
        self::assertSame(1, $repo->findByCode('ROLLBACK')?->getUsesCount());

        $repo->decrementUses((int) $coupon->getId());
        self::assertSame(0, $repo->findByCode('ROLLBACK')?->getUsesCount());

        $repo->decrementUses((int) $coupon->getId());
        self::assertSame(0, $repo->findByCode('ROLLBACK')?->getUsesCount(), 'Decrement must not go below zero.');
    }

    public function testCountUsesByEmailMatchesBillingAndUserEmails(): void
    {
        $repo = $this->repository();
        $this->makeCoupon('PERMAIL', null);

        $userA = $this->insertUser('a@example.com');
        $userB = $this->insertUser('b@example.com');

        $this->insertOrder('PERMAIL', null, 'guest@example.com');
        $this->insertOrder('PERMAIL', $userA, null);
        $this->insertOrder('PERMAIL', $userB, 'other@example.com');
        $this->insertOrder('OTHERCOUPON', null, 'guest@example.com');

        self::assertSame(1, $repo->countUsesByEmail('permail', 'guest@example.com'));
        self::assertSame(1, $repo->countUsesByEmail('PERMAIL', 'A@Example.com'));
        self::assertSame(1, $repo->countUsesByEmail('PERMAIL', 'b@example.com'));
        self::assertSame(0, $repo->countUsesByEmail('PERMAIL', 'unknown@example.com'));
        self::assertSame(0, $repo->countUsesByEmail('', 'guest@example.com'));
    }
}
