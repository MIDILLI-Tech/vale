<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Infrastructure\Persistence\DbalProductRepository;

final class DbalProductGoPublicTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = sys_get_temp_dir() . '/vl_productgopublic_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . $_ENV['DB_NAME']);

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        self::$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $config                                = Config::fromPhp(dirname(__DIR__, 3) . '/phinx.php');
        $environments                          = $config['environments'];
        $environments['default']['connection'] = self::$db->getNativeConnection();
        $config['environments']                = $environments;

        $manager = new Manager($config, new ArrayInput([]), new NullOutput());
        $manager->migrate('default');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db)) {
            try {
                self::$db->close();
            } catch (\Throwable) {
            }
        }
    }

    protected function setUp(): void
    {
        self::$db->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            self::$db->rollBack();
        } catch (\Throwable) {
        }
    }

    private function repo(): DbalProductRepository
    {
        return new DbalProductRepository(self::$db);
    }

    private function makeProduct(
        string $name,
        string $slug,
        string $publishedAt,
        bool $publishScheduled,
    ): Product {
        $product = new Product($name, new Slug($slug));
        $product->setStatus('unlisted');
        $product->setPublishedAt(new \DateTimeImmutable($publishedAt));
        $product->setPublishScheduled($publishScheduled);

        return $this->repo()->save($product);
    }

    public function testFindDueForGoPublicRequiresExplicitSchedule(): void
    {
        $normal    = $this->makeProduct('Normal', 'normal-product', '-1 hour', false);
        $scheduled = $this->makeProduct('Scheduled', 'scheduled-product', '-1 hour', true);
        $this->makeProduct('Future', 'future-product', '+1 hour', true);

        $ids = array_map(
            static fn (Product $product): int => (int) $product->getId(),
            $this->repo()->findDueForGoPublic(),
        );

        self::assertSame([$scheduled->getId()], $ids);
        self::assertFalse($this->repo()->findById((int) $normal->getId())->isPublishScheduled());
        self::assertTrue($this->repo()->findById((int) $scheduled->getId())->isPublishScheduled());
    }
}
