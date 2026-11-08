<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Infrastructure\Persistence\DbalOrderRepository;
use ValeApp\Infrastructure\Security\Encrypter;

/**
 * Order PII encryption at rest: free-text billing/shipping fields are stored
 * encrypted, but email/location stay plaintext so LIKE lookups still work, and
 * reads return plaintext to the domain.
 */
#[CoversNothing]
final class DbalOrderRepositoryEncryptionTest extends TestCase
{
    private static string $testDbPath;
    private static array $billing = [
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
        'email'      => 'customer@example.com',
        'phone'      => '+49 30 1234567',
        'address'    => 'Main St 123',
        'address_2'  => 'Apt 4',
        'city'       => 'Berlin',
        'state'      => 'BE',
        'postcode'   => '10115',
        'country'    => 'DE',
    ];

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vl_order_enc_' . bin2hex(random_bytes(8)) . '.sqlite';

        $appKey            = bin2hex(random_bytes(32));
        $_ENV['APP_KEY']   = $appKey;
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = self::$testDbPath;
        putenv('APP_KEY=' . $appKey);
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . self::$testDbPath);

        $phinxApp = new \Phinx\Console\PhinxApplication();
        $wrapper  = new \Phinx\Wrapper\TextWrapper($phinxApp, [
            'configuration' => dirname(__DIR__, 3) . '/phinx.php',
            'environment'   => 'default',
        ]);
        $wrapper->getMigrate();
    }

    public static function tearDownAfterClass(): void
    {
        gc_collect_cycles();
        clearstatcache();
        @unlink(self::$testDbPath);
        @unlink(self::$testDbPath . '-wal');
        @unlink(self::$testDbPath . '-shm');
    }

    private function db(): DbalConnection
    {
        $app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        return $app->getContainer()->get(DbalConnection::class);
    }

    private function encrypter(): Encrypter
    {
        return new Encrypter((string) getenv('APP_KEY'));
    }

    public function testSaveStoresPiiEncryptedButEmailPlaintext(): void
    {
        $db   = $this->db();
        $repo = new DbalOrderRepository($db, $this->encrypter());

        $order = new Order('ORD-ENC-1', self::$billing);
        $order->setShippingAddress(['first_name' => 'John', 'last_name' => 'Doe', 'address' => 'Second St 9']);
        $order->setStatus('completed');
        $order->setPaymentMethod('stripe');
        $order->setTotal(99.0);

        $repo->save($order);
        self::assertNotNull($order->getId());

        $raw = $db->fetchAssociative('SELECT billing FROM ' . VL_DB_PREFIX . 'orders WHERE id = ?', [$order->getId()]);
        self::assertNotFalse($raw);

        $stored = json_decode((string) $raw['billing'], true);
        self::assertIsArray($stored);
        self::assertStringStartsWith('encrypted::v1::', $stored['first_name']);
        self::assertStringStartsWith('encrypted::v1::', $stored['address']);
        self::assertSame('customer@example.com', $stored['email']);
        self::assertSame('Berlin', $stored['city']);
        self::assertSame('DE', $stored['country']);
        self::assertArrayNotHasKey('address_1', $stored);
    }

    public function testFindByIdReturnsPlaintext(): void
    {
        $db   = $this->db();
        $repo = new DbalOrderRepository($db, $this->encrypter());

        $order = new Order('ORD-ENC-2', self::$billing);
        $order->setStatus('pending');
        $order->setPaymentMethod('stripe');
        $repo->save($order);

        $loaded = $repo->findById((int) $order->getId());
        self::assertNotNull($loaded);
        $billing = $loaded->getBilling();

        self::assertSame('Jane', $billing['first_name']);
        self::assertSame('Doe', $billing['last_name']);
        self::assertSame('Main St 123', $billing['address']);
        self::assertSame('+49 30 1234567', $billing['phone']);
        self::assertSame('customer@example.com', $billing['email']);
    }

    public function testLegacyPlaintextReadsAsIs(): void
    {
        $db   = $this->db();
        $repo = new DbalOrderRepository($db, $this->encrypter());

        $plain = [
            'first_name' => 'Old',
            'last_name'  => 'Row',
            'email'      => 'old@example.com',
            'address'    => 'Legacy Ave 1',
            'city'       => 'Leipzig',
            'country'    => 'DE',
        ];
        $db->insert(VL_DB_PREFIX . 'orders', [
            'order_number'   => 'ORD-ENC-3',
            'status'         => 'completed',
            'currency'       => 'EUR',
            'total'          => 10.0,
            'billing'        => json_encode($plain),
            'payment_method' => 'stripe',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $loaded = $repo->findByOrderNumber('ORD-ENC-3');
        self::assertNotNull($loaded);
        self::assertSame('Old', $loaded->getBilling()['first_name']);
        self::assertSame('Legacy Ave 1', $loaded->getBilling()['address']);
    }

    public function testNullEncrypterStoresAndReadsPlaintext(): void
    {
        $db   = $this->db();
        $repo = new DbalOrderRepository($db, null);

        $order = new Order('ORD-ENC-4', self::$billing);
        $order->setStatus('completed');
        $order->setPaymentMethod('stripe');
        $repo->save($order);

        $raw = $db->fetchAssociative('SELECT billing FROM ' . VL_DB_PREFIX . 'orders WHERE id = ?', [$order->getId()]);
        self::assertNotFalse($raw);
        $stored = json_decode((string) $raw['billing'], true);
        self::assertIsArray($stored);
        self::assertSame('Jane', $stored['first_name']);
        self::assertSame('Main St 123', $stored['address']);

        $loaded = $repo->findById((int) $order->getId());
        self::assertNotNull($loaded);
        self::assertSame('Jane', $loaded->getBilling()['first_name']);
    }

    public function testPlaintextRowIsEncryptedOnNextSave(): void
    {
        $db   = $this->db();
        $repo = new DbalOrderRepository($db, $this->encrypter());

        $plain = [
            'first_name' => 'Lazy',
            'last_name'  => 'Upgrade',
            'email'      => 'lazy@example.com',
            'address'    => 'Once St 5',
            'city'       => 'Cologne',
            'country'    => 'DE',
        ];
        $db->insert(VL_DB_PREFIX . 'orders', [
            'order_number'   => 'ORD-ENC-5',
            'status'         => 'pending',
            'currency'       => 'EUR',
            'total'          => 5.0,
            'billing'        => json_encode($plain),
            'payment_method' => 'stripe',
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->fetchOne('SELECT MAX(id) FROM ' . VL_DB_PREFIX . 'orders');

        $order = $repo->findById($id);
        self::assertNotNull($order);
        self::assertSame('Lazy', $order->getBilling()['first_name']);

        $order->setTotal(6.0);
        $repo->save($order);

        $raw = $db->fetchAssociative('SELECT billing FROM ' . VL_DB_PREFIX . 'orders WHERE id = ?', [$id]);
        self::assertNotFalse($raw);
        $stored = json_decode((string) $raw['billing'], true);
        self::assertIsArray($stored);
        self::assertStringStartsWith('encrypted::v1::', $stored['first_name']);
        self::assertStringStartsWith('encrypted::v1::', $stored['address']);
        self::assertSame('lazy@example.com', $stored['email']);
    }
}
