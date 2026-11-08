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
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Persistence\DbalProductRepository;

/**
 * Product search matches name, SKU and — since the Categories column is a
 * first-class listing column — category names. Sorting covers the language
 * and total_sold columns the admin headers link to.
 */
final class DbalProductRepositorySearchTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = sys_get_temp_dir() . '/vl_productsearch_' . bin2hex(random_bytes(8)) . '.sqlite';
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

    private function insertProduct(string $name, string $slug, array $overrides = []): int
    {
        self::$db->insert(VL_DB_PREFIX . 'products', array_merge([
            'name' => $name,
            'slug' => $slug,
        ], $overrides));

        return (int) self::$db->lastInsertId();
    }

    private function insertCategory(string $name, string $slug): int
    {
        self::$db->insert(VL_DB_PREFIX . 'product_categories', [
            'name' => $name,
            'slug' => $slug,
        ]);

        return (int) self::$db->lastInsertId();
    }

    private function assignCategory(int $productId, int $categoryId): void
    {
        self::$db->insert(VL_DB_PREFIX . 'product_to_categories', [
            'product_id'  => $productId,
            'category_id' => $categoryId,
        ]);
    }

    public function testSearchMatchesNameAndSku(): void
    {
        $this->insertProduct('Kamelya Çayı', 'plain-tea', ['sku' => 'TEA-001']);
        $this->insertProduct('Papatya Çayı', 'other-tea', ['sku' => 'KAM-999']);

        self::assertSame(2, $this->repo()->count(['search' => 'kam']));
        $items = $this->repo()->findAll(new Pagination(1, 20), ['search' => 'kamelya']);
        self::assertCount(1, $items);
        self::assertSame('Kamelya Çayı', $items[0]->getName());
    }

    public function testSearchIgnoresDescriptionsByDefault(): void
    {
        $this->insertProduct('Plain Tea', 'plain-tea', ['description' => 'Kamelya aromalı', 'short_description' => 'Kamelya kokulu']);

        self::assertSame(0, $this->repo()->count(['search' => 'kamelya']));
    }

    public function testSearchBodyIncludesDescriptionsWhenFlagSet(): void
    {
        $this->insertProduct('Plain Tea', 'plain-tea', ['description' => 'Kamelya aromalı']);
        $this->insertProduct('Other Tea', 'other-tea', ['short_description' => 'Kamelya kokulu']);

        self::assertSame(2, $this->repo()->count(['search' => 'kamelya', 'search_body' => true]));
    }

    public function testSearchMatchesCategoryName(): void
    {
        $categoryId = $this->insertCategory('Kamelya Çayları', 'kamelya-caylari');
        $tea        = $this->insertProduct('Yeşil Çay', 'green-tea');
        $this->assignCategory($tea, $categoryId);
        $this->insertProduct('Papatya Çayı', 'other-tea');

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
        $items = $this->repo()->findAll(new Pagination(1, 20), ['search' => 'kamelya']);
        self::assertCount(1, $items);
        self::assertSame('Yeşil Çay', $items[0]->getName());
    }

    public function testSearchMatchesCategoryNameCaseInsensitively(): void
    {
        $categoryId = $this->insertCategory('Herbal Blends', 'herbal-blends');
        $tea        = $this->insertProduct('Mint Tea', 'mint-tea');
        $this->assignCategory($tea, $categoryId);

        self::assertSame(1, $this->repo()->count(['search' => 'HERBAL']));
    }

    public function testSearchMatchesCategoryAmongMultipleProducts(): void
    {
        $categoryId = $this->insertCategory('Kahvaltılık', 'breakfast');
        foreach (['peynir', 'zeytin', 'bal'] as $index => $slug) {
            $this->assignCategory($this->insertProduct(ucfirst($slug), $slug), $categoryId);
        }

        self::assertSame(3, $this->repo()->count(['search' => 'kahvaltılık']));
    }

    public function testCategorySearchCombinesWithOtherFilters(): void
    {
        $categoryId = $this->insertCategory('Sale Category', 'sale-cat');
        $this->assignCategory($this->insertProduct('Peynir', 'cheese', ['status' => 'published']), $categoryId);
        $this->assignCategory($this->insertProduct('Zeytin', 'olives', ['status' => 'draft']), $categoryId);

        self::assertSame(1, $this->repo()->count(['search' => 'sale', 'status' => 'published']));
    }

    public function testSearchWithoutMatchesReturnsEmpty(): void
    {
        $categoryId = $this->insertCategory('Kamelya Çayları', 'kamelya-caylari');
        $this->assignCategory($this->insertProduct('Yeşil Çay', 'green-tea'), $categoryId);

        self::assertSame(0, $this->repo()->count(['search' => 'inception']));
        self::assertSame([], $this->repo()->findAll(new Pagination(1, 20), ['search' => 'inception']));
    }

    public function testFindAllSortsByCategoriesAscending(): void
    {
        $zebra = $this->insertCategory('Zebra Kategorisi', 'zebra-cat');
        $apple = $this->insertCategory('Apple Kategorisi', 'apple-cat');

        $this->assignCategory($this->insertProduct('Zebra Ürün', 'zebra-product'), $zebra);
        $this->assignCategory($this->insertProduct('Apple Ürün', 'apple-product'), $apple);
        $this->insertProduct('Kategorisiz', 'no-category');

        $items = $this->repo()->findAll(new Pagination(1, 20, 'categories', 'asc'));

        self::assertCount(3, $items);
        self::assertSame('Kategorisiz', $items[0]->getName());
        self::assertSame('Apple Ürün', $items[1]->getName());
        self::assertSame('Zebra Ürün', $items[2]->getName());
    }

    public function testFindAllSortsByCategoriesDescending(): void
    {
        $zebra = $this->insertCategory('Zebra Kategorisi', 'zebra-cat');
        $apple = $this->insertCategory('Apple Kategorisi', 'apple-cat');

        $this->assignCategory($this->insertProduct('Zebra Ürün', 'zebra-product'), $zebra);
        $this->assignCategory($this->insertProduct('Apple Ürün', 'apple-product'), $apple);

        $items = $this->repo()->findAll(new Pagination(1, 20, 'categories', 'desc'));

        self::assertSame(
            ['Zebra Ürün', 'Apple Ürün'],
            array_map(fn ($product) => $product->getName(), $items),
        );
    }

    public function testFindAllSortsByFirstCategoryWhenMultiple(): void
    {
        $apple  = $this->insertCategory('Apple Kategorisi', 'apple-cat');
        $mango  = $this->insertCategory('Mango Kategorisi', 'mango-cat');
        $banana = $this->insertCategory('Banana Kategorisi', 'banana-cat');

        $multi = $this->insertProduct('Çoklu Kategori', 'multi-category');
        $this->assignCategory($multi, $apple);
        $this->assignCategory($multi, $mango);
        $this->assignCategory($this->insertProduct('Tekli Kategori', 'single-category'), $banana);

        $items = $this->repo()->findAll(new Pagination(1, 20, 'categories', 'asc'));

        self::assertSame(
            ['Çoklu Kategori', 'Tekli Kategori'],
            array_map(fn ($product) => $product->getName(), $items),
        );
    }

    public function testFindAllSortsByLanguage(): void
    {
        $this->insertProduct('İngilizce Ürün', 'en-product', ['language' => 'en']);
        $this->insertProduct('Türkçe Ürün', 'tr-product', ['language' => 'tr']);

        $items = $this->repo()->findAll(new Pagination(1, 20, 'language', 'asc'));

        self::assertSame(
            ['İngilizce Ürün', 'Türkçe Ürün'],
            array_map(fn ($product) => $product->getName(), $items),
        );
    }

    public function testFindAllSortsByTotalSold(): void
    {
        $this->insertProduct('Az Satan', 'few-sold', ['total_sold' => 2]);
        $this->insertProduct('Çok Satan', 'many-sold', ['total_sold' => 99]);

        $items = $this->repo()->findAll(new Pagination(1, 20, 'total_sold', 'asc'));

        self::assertSame(
            ['Az Satan', 'Çok Satan'],
            array_map(fn ($product) => $product->getName(), $items),
        );
    }

    public function testFindAllSortsByCreatedAt(): void
    {
        $this->insertProduct('Eski Ürün', 'old-product', ['created_at' => '2024-01-15 10:00:00']);
        $this->insertProduct('Yeni Ürün', 'new-product', ['created_at' => '2025-06-20 10:00:00']);

        $items = $this->repo()->findAll(new Pagination(1, 20, 'created_at', 'asc'));

        self::assertSame(
            ['Eski Ürün', 'Yeni Ürün'],
            array_map(fn ($product) => $product->getName(), $items),
        );
    }

    public function testFindAllSortsByUpdatedAt(): void
    {
        $this->insertProduct('Eski Güncelleme', 'old-updated', ['updated_at' => '2024-03-10 10:00:00']);
        $this->insertProduct('Yeni Güncelleme', 'new-updated', ['updated_at' => '2025-09-01 10:00:00']);

        $items = $this->repo()->findAll(new Pagination(1, 20, 'updated_at', 'desc'));

        self::assertSame(
            ['Yeni Güncelleme', 'Eski Güncelleme'],
            array_map(fn ($product) => $product->getName(), $items),
        );
    }

    public function testFindAllFallsBackForUnknownSortColumn(): void
    {
        $this->insertProduct('Zebra Ürün', 'zebra-product');
        $this->insertProduct('Apple Ürün', 'apple-product');

        $items = $this->repo()->findAll(new Pagination(1, 20, 'not_a_column', 'asc'));

        self::assertCount(2, $items);
        self::assertSame(
            ['Apple Ürün', 'Zebra Ürün'],
            array_map(fn ($product) => $product->getName(), $items),
        );
    }
}
