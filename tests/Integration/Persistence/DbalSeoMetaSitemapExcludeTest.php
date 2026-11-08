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
use ValeApp\Application\Seo\SaveSeoMetaUseCase;
use ValeApp\Infrastructure\Persistence\DbalSeoMetaRepository;

/**
 * The sitemap flag is authoritative for sitemap generation: unchecking
 * "Show this page in the sitemap" must keep the URL out of
 * findIndexableEntries() even when the page stays index,follow.
 * The robots noindex filter remains as a backstop.
 */
final class DbalSeoMetaSitemapExcludeTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $dbFile = sys_get_temp_dir() . '/vl_seo_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = $dbFile;
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . $dbFile);

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

    private function insertPost(string $slug, string $contentType = 'post'): int
    {
        self::$db->insert(VL_DB_PREFIX . 'posts', [
            'author_id'    => 1,
            'title'        => $slug,
            'slug'         => $slug,
            'content_type' => $contentType,
            'status'       => 'published',
        ]);

        return (int) self::$db->lastInsertId();
    }

    private function insertProduct(string $slug): int
    {
        self::$db->insert(VL_DB_PREFIX . 'products', [
            'name'   => $slug,
            'slug'   => $slug,
            'status' => 'published',
        ]);

        return (int) self::$db->lastInsertId();
    }

    public function testSitemapFlagExcludesIndexedPage(): void
    {
        $repo    = new DbalSeoMetaRepository(self::$db);
        $saveSeo = new SaveSeoMetaUseCase($repo);

        $this->insertPost('plain-post');
        $hiddenId   = $this->insertPost('hidden-post');
        $unlistedId = $this->insertPost('unlisted-post');
        $this->insertProduct('plain-product');
        $hiddenProductId = $this->insertProduct('hidden-product');

        $saveSeo->execute('post', $hiddenId, ['allow_index' => '0']);
        $saveSeo->execute('post', $unlistedId, ['allow_index' => '1', 'show_sitemap' => '0']);
        $saveSeo->execute('product', $hiddenProductId, ['sitemap_exclude' => '1']);

        $slugs = array_column($repo->findIndexableEntries(), 'slug');

        self::assertContains('plain-post', $slugs);
        self::assertContains('plain-product', $slugs);
        self::assertNotContains('hidden-post', $slugs);
        self::assertNotContains('unlisted-post', $slugs);
        self::assertNotContains('hidden-product', $slugs);
    }

    public function testNoindexBackstopExcludesEvenWithoutFlag(): void
    {
        $repo = new DbalSeoMetaRepository(self::$db);

        $postId = $this->insertPost('desynced-post');
        self::$db->insert(VL_DB_PREFIX . 'seo_meta', [
            'entity_type'     => 'post',
            'entity_id'       => $postId,
            'robots'          => 'noindex,follow',
            'sitemap_exclude' => 0,
        ]);

        $slugs = array_column($repo->findIndexableEntries(), 'slug');

        self::assertNotContains('desynced-post', $slugs);
    }
}
