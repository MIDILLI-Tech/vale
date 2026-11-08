<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Plugin\InvalidPluginException;
use ValeApp\Application\Plugin\PluginManager;
use ValeApp\Application\Plugin\PluginMigrator;

final class PluginMigratorTest extends TestCase
{
    private string $pluginsDir;

    private InMemorySettingsRepository $settings;

    private PluginManager $manager;

    private Connection $db;

    private PluginMigrator $migrator;

    protected function setUp(): void
    {
        $this->pluginsDir = sys_get_temp_dir() . '/vl-plugin-migrations-' . bin2hex(random_bytes(6));
        $this->settings   = new InMemorySettingsRepository();
        $this->manager    = new PluginManager($this->settings, $this->pluginsDir, '1.0.0', false);
        $this->db         = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrator   = new PluginMigrator($this->manager, $this->db, $this->settings);

        $this->writePlugin('acme', [
            '20260101000000_create_notes.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'up' => static function (\Doctrine\DBAL\Connection $db): void {
                        $db->executeStatement('CREATE TABLE acme_notes (id INTEGER PRIMARY KEY, label VARCHAR(32))');
                    },
                ];
                PHP,
            '20260102000000_add_body.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                return static function (\Doctrine\DBAL\Connection $db): void {
                    $db->executeStatement('ALTER TABLE acme_notes ADD COLUMN body VARCHAR(64)');
                };
                PHP,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginsDir);
    }

    public function testAppliesPendingMigrationsOnceAndRecordsThem(): void
    {
        self::assertCount(2, $this->migrator->pending('acme'));

        $report = $this->migrator->migrate('acme');

        self::assertSame([
            '20260101000000_create_notes.php',
            '20260102000000_add_body.php',
        ], $report['applied']);
        self::assertTrue($this->db->createSchemaManager()->tablesExist(['acme_notes']));
        self::assertSame($report['applied'], $this->migrator->applied('acme'));
        self::assertSame([], $this->migrator->pending('acme'));

        $second = $this->migrator->migrate('acme');
        self::assertSame([], $second['applied']);
        self::assertSame([], $second['pending']);
    }

    public function testDryRunDoesNotTouchTheDatabaseOrTheSettings(): void
    {
        $report = $this->migrator->migrate('acme', true);

        self::assertCount(2, $report['pending']);
        self::assertSame([], $report['applied']);
        self::assertFalse($this->db->createSchemaManager()->tablesExist(['acme_notes']));
        self::assertNull($this->settings->get(PluginMigrator::SETTINGS_KEY));
    }

    public function testRejectsMigrationFilesThatBreakTheNamingContract(): void
    {
        $this->writeMigration('acme', 'create_notes.php', '<?php return static fn () => null;');

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('YYYYMMDDHHMMSS_snake_name.php');

        $this->migrator->migrate('acme');
    }

    public function testFailingMigrationIsReportedAndNotRecorded(): void
    {
        $this->writePlugin('broken', [
            '20260101000000_fail.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                return static function (): void {
                    throw new \RuntimeException('boom');
                };
                PHP,
        ]);

        try {
            $this->migrator->migrate('broken');
            self::fail('A failing migration must throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('20260101000000_fail.php', $e->getMessage());
            self::assertStringContainsString('boom', $e->getMessage());
        }

        self::assertSame([], $this->migrator->applied('broken'));
        self::assertCount(1, $this->migrator->pending('broken'));
    }

    public function testMigrationMustReturnSomethingRunnable(): void
    {
        $this->writePlugin('empty', [
            '20260101000000_nothing.php' => '<?php return 42;',
        ]);

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('must return a callable');

        $this->migrator->migrate('empty');
    }

    public function testMigrateEnabledSkipsDisabledPlugins(): void
    {
        self::assertSame([], $this->migrator->migrateEnabled());

        $this->settings->values[PluginManager::SETTINGS_KEY] = '["acme"]';

        $reports = $this->migrator->migrateEnabled();
        self::assertCount(1, $reports);
        self::assertSame('acme', $reports[0]['plugin']);
        self::assertNull($reports[0]['error']);
        self::assertCount(2, $reports[0]['applied']);
    }

    public function testMigrateEnabledKeepsGoingWhenOnePluginFails(): void
    {
        $this->writePlugin('broken', [
            '20260101000000_fail.php' => '<?php return static fn (): never => throw new \RuntimeException("boom");',
        ]);
        $this->settings->values[PluginManager::SETTINGS_KEY] = '["broken","acme"]';

        $reports = $this->migrator->migrateEnabled();

        self::assertCount(2, $reports);
        self::assertNotNull($reports[0]['error']);
        self::assertNull($reports[1]['error']);
        self::assertCount(2, $reports[1]['applied']);
    }

    public function testMissingPluginDirectoryThrows(): void
    {
        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('directory is missing');

        $this->migrator->migrate('gone');
    }

    public function testPluginsWithoutMigrationsDirectoryAreClean(): void
    {
        $this->writePlugin('plain', []);

        self::assertSame([], $this->migrator->pending('plain'));
        self::assertSame([], $this->migrator->migrate('plain')['applied']);
    }

    public function testRollbackRevertsTheNewestMigrationOnly(): void
    {
        $this->writeReversiblePlugin();
        $this->migrator->migrate('reversible');

        $report = $this->migrator->rollback('reversible');

        self::assertSame(['20260202000000_create_second.php'], $report['reverted']);
        self::assertSame(['20260201000000_create_first.php'], $report['remaining']);
        self::assertTrue($this->db->createSchemaManager()->tablesExist(['rev_first']));
        self::assertFalse($this->db->createSchemaManager()->tablesExist(['rev_second']));
        self::assertSame(['20260201000000_create_first.php'], $this->migrator->applied('reversible'));
    }

    public function testRollbackAllRevertsEverythingNewestFirst(): void
    {
        $this->writeReversiblePlugin();
        $this->migrator->migrate('reversible');

        $report = $this->migrator->rollback('reversible', false, true);

        self::assertSame([
            '20260202000000_create_second.php',
            '20260201000000_create_first.php',
        ], $report['reverted']);
        self::assertSame([], $report['remaining']);
        self::assertSame([], $this->migrator->applied('reversible'));
        self::assertFalse($this->db->createSchemaManager()->tablesExist(['rev_first']));
        self::assertFalse($this->db->createSchemaManager()->tablesExist(['rev_second']));
    }

    public function testRollbackDryRunChangesNothing(): void
    {
        $this->writeReversiblePlugin();
        $this->migrator->migrate('reversible');

        $report = $this->migrator->rollback('reversible', true);

        self::assertSame(['20260202000000_create_second.php'], $report['reverted']);
        self::assertTrue($this->db->createSchemaManager()->tablesExist(['rev_second']));
        self::assertCount(2, $this->migrator->applied('reversible'));
    }

    public function testRollbackRefusesAMigrationWithoutDown(): void
    {
        $this->migrator->migrate('acme');

        try {
            $this->migrator->rollback('acme');
            self::fail('A migration without a down callable must not be rolled back.');
        } catch (InvalidPluginException $e) {
            self::assertStringContainsString('no "down" callable', $e->getMessage());
        }

        self::assertCount(2, $this->migrator->applied('acme'));
        self::assertTrue($this->db->createSchemaManager()->tablesExist(['acme_notes']));
    }

    public function testRollbackWithNothingAppliedIsANoOp(): void
    {
        $this->writeReversiblePlugin();

        $report = $this->migrator->rollback('reversible', false, true);

        self::assertSame([], $report['reverted']);
        self::assertSame([], $report['remaining']);
    }

    public function testRollbackReportsARecordedFileMissingFromDisk(): void
    {
        $this->writeReversiblePlugin();
        $this->migrator->migrate('reversible');

        unlink($this->pluginsDir . '/reversible/migrations/20260202000000_create_second.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing from disk');

        $this->migrator->rollback('reversible');
    }

    public function testPendingCountsOnlyUnappliedFiles(): void
    {
        self::assertCount(2, $this->migrator->pending('acme'));

        $this->migrator->migrate('acme');

        self::assertSame([], $this->migrator->pending('acme'));
    }

    public function testLastResultIsNullBeforeTheFirstRun(): void
    {
        self::assertNull($this->migrator->lastResult('acme'));
    }

    public function testLastResultRecordsAnApplyRun(): void
    {
        $this->migrator->migrate('acme');

        $result = $this->migrator->lastResult('acme');

        self::assertNotNull($result);
        self::assertSame('ok', $result['status']);
        self::assertSame('apply', $result['action']);
        self::assertSame(2, $result['count']);
        self::assertNull($result['message']);
        self::assertNotSame('', $result['at']);
    }

    public function testLastResultRecordsARollbackRun(): void
    {
        $this->writeReversiblePlugin();
        $this->migrator->migrate('reversible');

        $this->migrator->rollback('reversible');

        $result = $this->migrator->lastResult('reversible');

        self::assertNotNull($result);
        self::assertSame('ok', $result['status']);
        self::assertSame('rollback', $result['action']);
        self::assertSame(1, $result['count']);
    }

    public function testLastResultRecordsAFailedMigration(): void
    {
        $this->writePlugin('broken-mig', [
            '20260301000000_explode.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'up' => static function (\Doctrine\DBAL\Connection $db): void {
                        throw new \RuntimeException('boom');
                    },
                ];
                PHP,
        ]);

        try {
            $this->migrator->migrate('broken-mig');
            self::fail('A throwing migration must surface as an error.');
        } catch (\RuntimeException) {
        }

        $result = $this->migrator->lastResult('broken-mig');

        self::assertNotNull($result);
        self::assertSame('error', $result['status']);
        self::assertSame(0, $result['count']);
        self::assertStringContainsString('boom', (string) $result['message']);
    }

    private function writeReversiblePlugin(): void
    {
        $this->writePlugin('reversible', [
            '20260201000000_create_first.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'up' => static function (\Doctrine\DBAL\Connection $db): void {
                        $db->executeStatement('CREATE TABLE rev_first (id INTEGER PRIMARY KEY)');
                    },
                    'down' => static function (\Doctrine\DBAL\Connection $db): void {
                        $db->executeStatement('DROP TABLE rev_first');
                    },
                ];
                PHP,
            '20260202000000_create_second.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                return [
                    'up' => static function (\Doctrine\DBAL\Connection $db): void {
                        $db->executeStatement('CREATE TABLE rev_second (id INTEGER PRIMARY KEY)');
                    },
                    'down' => static function (\Doctrine\DBAL\Connection $db): void {
                        $db->executeStatement('DROP TABLE rev_second');
                    },
                ];
                PHP,
        ]);
    }

    /**
     * @param array<string, string> $migrations file name => contents
     */
    private function writePlugin(string $slug, array $migrations): void
    {
        mkdir($this->pluginsDir . '/' . $slug, 0777, true);
        file_put_contents(
            $this->pluginsDir . '/' . $slug . '/plugin.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ['name' => 'Acme', 'register' => static function (): void {}];\n",
        );

        foreach ($migrations as $file => $contents) {
            $this->writeMigration($slug, $file, $contents);
        }
    }

    private function writeMigration(string $slug, string $file, string $contents): void
    {
        $dir = $this->pluginsDir . '/' . $slug . '/migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir . '/' . $file, $contents);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
