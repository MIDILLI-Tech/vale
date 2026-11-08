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
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;

/**
 * set() must be able to save any setting without tripping the
 * UNIQUE(group_name, key) index. Regression: a seeder row and an admin-saved
 * row could hold the same key in two groups, and updating by key moved every
 * duplicate into the target group, failing the whole settings save with a 500.
 */
final class DbalSettingsRepositoryTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $dbFile = sys_get_temp_dir() . '/vl_settings_' . bin2hex(random_bytes(8)) . '.sqlite';

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

    private function repo(): DbalSettingsRepository
    {
        return new DbalSettingsRepository(
            self::$db,
            new Encrypter(str_repeat('ab', 32)),
        );
    }

    private function insertSetting(string $group, string $key, string $value): int
    {
        self::$db->insert(VL_DB_PREFIX . 'settings', [
            'group_name'  => $group,
            'key'         => $key,
            'value'       => $value,
            'type'        => 'string',
            'is_autoload' => 1,
            'created_at'  => '2026-01-01 00:00:00',
        ]);

        return (int) self::$db->lastInsertId();
    }

    /**
     * @return array<int, array{group_name: string, key: string, value: string}>
     */
    private function rowsForKey(string $key): array
    {
        return self::$db->fetchAllAssociative(
            'SELECT group_name, ' . self::$db->quoteIdentifier('key') . ', ' . self::$db->quoteIdentifier('value')
            . ' FROM ' . VL_DB_PREFIX . 'settings WHERE ' . self::$db->quoteIdentifier('key') . ' = ?',
            [$key],
        );
    }

    public function testSetHealsDuplicateRowsAcrossGroups(): void
    {
        $this->insertSetting('content', 'multi_language_content', '1');
        $this->insertSetting('general', 'multi_language_content', '1');

        $this->repo()->set('multi_language_content', '0', 'general');

        $rows = $this->rowsForKey('multi_language_content');
        self::assertCount(1, $rows);
        self::assertSame('general', $rows[0]['group_name']);
        self::assertSame('0', $rows[0]['value']);
    }

    public function testSetKeepsNewestRowWhenHealing(): void
    {
        $this->insertSetting('content', 'site_tagline', 'old');
        $this->insertSetting('general', 'site_tagline', 'newer');

        $this->repo()->set('site_tagline', 'saved', 'general');

        $rows = $this->rowsForKey('site_tagline');
        self::assertCount(1, $rows);
        self::assertSame('general', $rows[0]['group_name']);
        self::assertSame('saved', $rows[0]['value']);
    }

    public function testSetInsertsNewKey(): void
    {
        $this->repo()->set('brand_new_key', 'yes', 'general');

        $rows = $this->rowsForKey('brand_new_key');
        self::assertCount(1, $rows);
        self::assertSame('general', $rows[0]['group_name']);
        self::assertSame('yes', $rows[0]['value']);
    }

    public function testSetUpdatesExistingSingleRow(): void
    {
        $this->insertSetting('general', 'existing_key', 'before');

        $this->repo()->set('existing_key', 'after', 'general');

        $rows = $this->rowsForKey('existing_key');
        self::assertCount(1, $rows);
        self::assertSame('after', $rows[0]['value']);
    }

    public function testSetManySavesEveryKey(): void
    {
        $this->insertSetting('content', 'multi_language_content', '1');

        $this->repo()->setMany([
            'multi_language_content' => '0',
            'fresh_setting'          => '1',
        ], 'general');

        $healed = $this->rowsForKey('multi_language_content');
        self::assertCount(1, $healed);
        self::assertSame('0', $healed[0]['value']);

        $fresh = $this->rowsForKey('fresh_setting');
        self::assertCount(1, $fresh);
        self::assertSame('1', $fresh[0]['value']);
    }
}
