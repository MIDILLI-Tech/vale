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
use ValeApp\Infrastructure\Persistence\DbalCategoryRepository;
use ValeApp\Infrastructure\Persistence\DbalProductCategoryRepository;
use ValeApp\Infrastructure\Persistence\DbalTagRepository;

final class DbalTranslationOfPotentialOriginalsTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $dbFile = sys_get_temp_dir() . '/vl_transof_' . bin2hex(random_bytes(8)) . '.sqlite';

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

    public function testCategoryFindPotentialOriginalsMarksTranslatedStatus(): void
    {
        $repo = new DbalCategoryRepository(self::$db);

        // Insert English original
        self::$db->insert(VL_DB_PREFIX . 'categories', [
            'name'         => 'News',
            'slug'         => 'news',
            'content_type' => 'post',
            'language'     => 'en',
        ]);
        $origId = (int) self::$db->lastInsertId();

        // Before TR translation: isTranslatedInTargetLanguage should be false
        $originals = $repo->findPotentialOriginals('post', language: 'tr');
        self::assertCount(1, $originals);
        self::assertFalse($originals[0]->isTranslatedInTargetLanguage());

        // Insert TR translation
        self::$db->insert(VL_DB_PREFIX . 'categories', [
            'name'           => 'Haberler',
            'slug'           => 'haberler',
            'content_type'   => 'post',
            'language'       => 'tr',
            'translation_of' => $origId,
        ]);
        $transId = (int) self::$db->lastInsertId();

        // After TR translation exists: isTranslatedInTargetLanguage should be true
        $originals = $repo->findPotentialOriginals('post', language: 'tr');
        self::assertCount(1, $originals);
        self::assertTrue($originals[0]->isTranslatedInTargetLanguage());

        // When editing existing translation $transId, $origId is not excluded as translated
        $originalsForEdit = $repo->findPotentialOriginals(
            'post',
            excludeId: $transId,
            language: 'tr',
        );
        self::assertCount(1, $originalsForEdit);
        self::assertFalse($originalsForEdit[0]->isTranslatedInTargetLanguage());
    }

    public function testProductCategoryFindPotentialOriginalsMarksTranslatedStatus(): void
    {
        $repo = new DbalProductCategoryRepository(self::$db);

        self::$db->insert(VL_DB_PREFIX . 'product_categories', [
            'name'     => 'Electronics',
            'slug'     => 'electronics',
            'language' => 'en',
        ]);
        $origId = (int) self::$db->lastInsertId();

        $originals = $repo->findPotentialOriginals(language: 'tr');
        self::assertCount(1, $originals);
        self::assertFalse($originals[0]->isTranslatedInTargetLanguage());

        self::$db->insert(VL_DB_PREFIX . 'product_categories', [
            'name'           => 'Elektronik',
            'slug'           => 'elektronik',
            'language'       => 'tr',
            'translation_of' => $origId,
        ]);

        $originals = $repo->findPotentialOriginals(language: 'tr');
        self::assertCount(1, $originals);
        self::assertTrue($originals[0]->isTranslatedInTargetLanguage());
    }

    public function testTagFindPotentialOriginalsMarksTranslatedStatus(): void
    {
        $repo = new DbalTagRepository(self::$db);

        self::$db->insert(VL_DB_PREFIX . 'tags', [
            'name'         => 'Tech',
            'slug'         => 'tech',
            'content_type' => 'post',
            'language'     => 'en',
        ]);
        $origId = (int) self::$db->lastInsertId();

        $originals = $repo->findPotentialOriginals('post', language: 'tr');
        self::assertCount(1, $originals);
        self::assertFalse($originals[0]->isTranslatedInTargetLanguage());

        self::$db->insert(VL_DB_PREFIX . 'tags', [
            'name'           => 'Teknoloji',
            'slug'           => 'teknoloji',
            'content_type'   => 'post',
            'language'       => 'tr',
            'translation_of' => $origId,
        ]);

        $originals = $repo->findPotentialOriginals('post', language: 'tr');
        self::assertCount(1, $originals);
        self::assertTrue($originals[0]->isTranslatedInTargetLanguage());
    }
}
