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
use ValeApp\Infrastructure\Persistence\DbalTagRepository;

final class DbalTagSaveTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $dbFile = sys_get_temp_dir() . '/vl_tag_save_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = $dbFile;
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . $dbFile);

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        self::$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $config                        = Config::fromPhp(dirname(__DIR__, 3) . '/phinx.php');
        $envs                          = $config['environments'];
        $envs['default']['connection'] = self::$db->getNativeConnection();
        $config['environments']        = $envs;

        $manager = new Manager($config, new ArrayInput([]), new NullOutput());
        $manager->migrate('default');
    }

    public static function tearDownAfterClass(): void
    {
        putenv('DB_DRIVER');
        putenv('DB_NAME');
        unset($_ENV['DB_DRIVER'], $_ENV['DB_NAME']);

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

    public function testSaveUpdatesAnExistingTag(): void
    {
        self::$db->insert(VL_DB_PREFIX . 'tags', [
            'name'         => 'Tech',
            'slug'         => 'tech',
            'content_type' => 'post',
            'language'     => 'en',
        ]);
        $id = (int) self::$db->lastInsertId();

        $repo = new DbalTagRepository(self::$db);
        $tag  = $repo->findById($id);
        self::assertNotNull($tag);

        $tag->setName('Technology');
        $tag->setSlug(new Slug('technology'));
        $repo->save($tag);

        self::assertSame(
            ['name' => 'Technology', 'slug' => 'technology'],
            self::$db->fetchAssociative(
                'SELECT name, slug FROM ' . VL_DB_PREFIX . 'tags WHERE id = ?',
                [$id]
            ),
        );
    }
}
