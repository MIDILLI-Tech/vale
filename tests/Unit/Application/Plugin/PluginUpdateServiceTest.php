<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use ArrayObject;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Plugin\InvalidPluginException;
use ValeApp\Application\Plugin\PluginCapabilitiesChangedException;
use ValeApp\Application\Plugin\PluginManager;
use ValeApp\Application\Plugin\PluginMigrator;
use ValeApp\Application\Plugin\PluginUpdateService;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;

final class PluginUpdateServiceTest extends TestCase
{
    private string $root;

    private string $pluginsDir;

    private string $workDir;

    private string $backupDir;

    private InMemorySettingsRepository $settings;

    private PluginManager $manager;

    private PluginMigrator $migrator;

    private Connection $db;

    /** @var ArrayObject<int, string> */
    private ArrayObject $requests;

    protected function setUp(): void
    {
        $this->root       = sys_get_temp_dir() . '/vl-plugin-update-' . bin2hex(random_bytes(4));
        $this->pluginsDir = $this->root . '/plugins';
        $this->workDir    = $this->root . '/work';
        $this->backupDir  = $this->root . '/backups';

        mkdir($this->pluginsDir, 0777, true);
        mkdir($this->workDir, 0777, true);
        mkdir($this->backupDir, 0777, true);

        $this->settings = new InMemorySettingsRepository();
        $this->manager  = new PluginManager($this->settings, $this->pluginsDir, '0.77.3');
        $this->db       = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->migrator = new PluginMigrator($this->manager, $this->db, $this->settings);
        $this->requests = new ArrayObject();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCheckStoresAvailableReleaseAndRequirements(): void
    {
        $this->writePlugin('demo', '1.0.0');
        $zip = $this->buildPackage('1.1.0');

        $service = $this->service([
            $this->feedUrl          => $this->jsonFeed('1.1.0', $zip, [
                'requires_vale' => '^0.77',
                'requires_php'  => '>=8.3',
            ]),
            $this->downloadUrl      => $zip,
        ]);

        $status = $service->checkOne('demo');

        self::assertTrue($status['available']);
        self::assertSame('1.1.0', $status['version']);
        self::assertSame('^0.77', $status['requires_vale']);
        self::assertSame('>=8.3', $status['requires_php']);
        self::assertSame($status, $service->status()['demo']);
    }

    public function testCheckReportsACompatibilityErrorWithoutUpdating(): void
    {
        $this->writePlugin('demo', '1.0.0');
        $zip = $this->buildPackage('1.1.0');

        $service = $this->service([
            $this->feedUrl     => $this->jsonFeed('1.1.0', $zip, ['requires_php' => '>=99']),
            $this->downloadUrl => $zip,
        ]);

        $status = $service->checkOne('demo');

        self::assertFalse($status['available']);
        self::assertStringContainsString('Requires PHP >=99', (string) $status['error']);
    }

    public function testCheckReportsAMissingPluginDependency(): void
    {
        $this->writePlugin('demo', '1.0.0');
        $zip = $this->buildPackage('1.1.0');

        $service = $this->service([
            $this->feedUrl     => $this->jsonFeed('1.1.0', $zip, [
                'requires_plugin' => ['acme-core' => '^1.0'],
            ]),
            $this->downloadUrl => $zip,
        ]);

        $status = $service->checkOne('demo');

        self::assertFalse($status['available']);
        self::assertSame('Requires plugin "acme-core", which is not installed.', $status['error']);
    }

    public function testUpdateReplacesThePluginAndRunsNewMigrations(): void
    {
        $this->writePlugin('demo', '1.0.0');
        $this->manager->enable('demo');

        $zip = $this->buildPackage('1.1.0', [
            '20260101000000_create_updated_table.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                return static function (\Doctrine\DBAL\Connection $db): void {
                    $db->executeStatement('CREATE TABLE plugin_update_ran (id INTEGER PRIMARY KEY)');
                };
                PHP,
        ]);

        $service = $this->service([
            $this->feedUrl     => $this->jsonFeed('1.1.0', $zip),
            $this->downloadUrl => $zip,
        ]);

        $result = $service->update('demo');

        self::assertSame('1.0.0', $result['from']);
        self::assertSame('1.1.0', $result['to']);
        self::assertSame(1, $result['migrations']);
        self::assertFileExists($result['backup'] . '/backup.json');
        self::assertStringContainsString("'version' => '1.1.0'", (string) file_get_contents($this->pluginsDir . '/demo/plugin.php'));
        self::assertTrue($this->db->createSchemaManager()->tablesExist(['plugin_update_ran']));
        self::assertFalse($service->status()['demo']['available']);
    }

    public function testUpdateRejectsAHashMismatchAndKeepsTheOldFiles(): void
    {
        $this->writePlugin('demo', '1.0.0');
        $zip = $this->buildPackage('1.1.0');

        $service = $this->service([
            $this->feedUrl     => $this->jsonFeed('1.1.0', $zip, ['sha256' => str_repeat('0', 64)]),
            $this->downloadUrl => $zip,
        ]);

        try {
            $service->update('demo');
            self::fail('A package with the wrong hash must not be installed.');
        } catch (InvalidPluginException $e) {
            self::assertStringContainsString('SHA-256', $e->getMessage());
        }

        self::assertStringContainsString("'version' => '1.0.0'", (string) file_get_contents($this->pluginsDir . '/demo/plugin.php'));
    }

    public function testUpdateRejectsAnUnsafeZipPath(): void
    {
        $this->writePlugin('demo', '1.0.0');
        $zip = $this->buildUnsafePackage();

        $service = $this->service([
            $this->feedUrl     => $this->jsonFeed('1.1.0', $zip),
            $this->downloadUrl => $zip,
        ]);

        try {
            $service->update('demo');
            self::fail('A traversal entry must be rejected.');
        } catch (InvalidPluginException $e) {
            self::assertStringContainsString('unsafe path', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->root . '/evil.php');
        self::assertStringContainsString("'version' => '1.0.0'", (string) file_get_contents($this->pluginsDir . '/demo/plugin.php'));
    }

    public function testInstallFromArchiveAddsThePluginDisabled(): void
    {
        $zipPath = $this->root . '/install.zip';
        file_put_contents($zipPath, $this->buildPackage('1.0.0'));

        $result = $this->service([])->installFromArchive($zipPath, 'demo.zip');

        self::assertSame('demo', $result['slug']);
        self::assertSame('Demo', $result['name']);
        self::assertSame('1.0.0', $result['version']);
        self::assertDirectoryExists($this->pluginsDir . '/demo');
        self::assertStringContainsString(
            "'version' => '1.0.0'",
            (string) file_get_contents($this->pluginsDir . '/demo/plugin.php'),
        );
        self::assertFalse($this->manager->isEnabled('demo'));
        self::assertNotNull($this->manager->lifecycle('demo')['installed_at']);
        self::assertNotNull($this->manager->lifecycle('demo')['updated_at']);
    }

    public function testInstallRejectsAMustUsePackageWithoutAManifest(): void
    {
        $zipPath = $this->root . '/mu-plugin.zip';
        $zip     = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('mu-plugins/always-on.php', '<?php return static function (): void {};');
        $zip->close();

        try {
            $this->service([])->installFromArchive($zipPath, 'always-on.zip');
            self::fail('A mu-plugin package without plugin.php must be rejected.');
        } catch (InvalidPluginException $e) {
            self::assertStringContainsString('regular plugin manifest', $e->getMessage());
            self::assertStringContainsString('Must-use (mu) plugins', $e->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->pluginsDir . '/mu-plugins');
    }

    public function testInstallRefusesToOverwriteAnExistingPlugin(): void
    {
        $this->writePlugin('demo', '1.0.0');
        $zipPath = $this->root . '/install.zip';
        file_put_contents($zipPath, $this->buildPackage('1.1.0'));

        try {
            $this->service([])->installFromArchive($zipPath, 'demo.zip');
            self::fail('An existing plugin directory must not be overwritten.');
        } catch (InvalidPluginException $e) {
            self::assertStringContainsString('already installed', $e->getMessage());
        }

        self::assertStringContainsString(
            "'version' => '1.0.0'",
            (string) file_get_contents($this->pluginsDir . '/demo/plugin.php'),
        );
    }

    public function testInstallRejectsAnUnsafeZipPath(): void
    {
        $zipPath = $this->root . '/install-unsafe.zip';
        file_put_contents($zipPath, $this->buildUnsafePackage());

        try {
            $this->service([])->installFromArchive($zipPath, 'unsafe.zip');
            self::fail('A traversal entry must be rejected.');
        } catch (InvalidPluginException $e) {
            self::assertStringContainsString('unsafe path', $e->getMessage());
        }

        self::assertFileDoesNotExist($this->root . '/evil.php');
        self::assertDirectoryDoesNotExist($this->pluginsDir . '/demo');
    }

    public function testAutomaticPluginChecksAreThrottledAfterARun(): void
    {
        $this->writePlugin('demo', '1.0.0');
        $zip     = $this->buildPackage('1.1.0');
        $service = $this->service([
            $this->feedUrl     => $this->jsonFeed('1.1.0', $zip),
            $this->downloadUrl => $zip,
        ]);

        self::assertTrue($service->isDueForCheck());
        $service->checkAll();
        self::assertFalse($service->isDueForCheck());
        self::assertArrayHasKey('plugin_update_last_check', $this->settings->values);
    }

    private const FEED_URL = 'https://1.1.1.1/demo/updates.json';

    private const DOWNLOAD_URL = 'https://1.1.1.1/demo/demo.zip';

    private string $feedUrl = self::FEED_URL;

    private string $downloadUrl = self::DOWNLOAD_URL;

    private function service(array $responses): PluginUpdateService
    {
        $fetcher = new SafeRemoteFetcher(function (string $url, string $userAgent, int $timeout) use ($responses): array {
            $this->requests->append($url);

            $body = $responses[$url] ?? null;
            if ($body === null) {
                return [
                    'status'   => 404,
                    'location' => null,
                    'headers'  => ['HTTP/1.1 404 Not Found'],
                    'stream'   => null,
                ];
            }

            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $body);
            rewind($stream);

            return [
                'status'   => 200,
                'location' => null,
                'headers'  => ['HTTP/1.1 200 OK'],
                'stream'   => $stream,
            ];
        });

        return new PluginUpdateService(
            $this->manager,
            $this->migrator,
            $this->settings,
            $fetcher,
            $this->workDir,
            $this->backupDir,
        );
    }

    private function jsonFeed(string $version, string $zip, array $overrides = []): string
    {
        $data = array_merge([
            'version'      => $version,
            'download_url' => $this->downloadUrl,
            'sha256'       => hash('sha256', $zip),
            'changelog'    => ['Updated the demo plugin'],
        ], $overrides);

        return (string) json_encode($data, JSON_THROW_ON_ERROR);
    }

    private function writePlugin(string $slug, string $version, array $capabilities = []): void
    {
        $dir = $this->pluginsDir . '/' . $slug;
        mkdir($dir, 0777, true);

        file_put_contents($dir . '/plugin.php', $this->manifest($version, $capabilities));
    }

    private function buildPackage(string $version, array $migrations = [], array $capabilities = []): string
    {
        $source = $this->root . '/package-' . bin2hex(random_bytes(4));
        mkdir($source . '/demo', 0777, true);
        file_put_contents($source . '/demo/plugin.php', $this->manifest($version, $capabilities));

        foreach ($migrations as $file => $contents) {
            mkdir($source . '/demo/migrations', 0777, true);
            file_put_contents($source . '/demo/migrations/' . $file, $contents);
        }

        $zipPath = $this->root . '/demo-' . $version . '.zip';
        $zip     = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source . '/demo', \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source . '/demo') + 1));
            $zip->addFile($file->getPathname(), 'demo/' . $relative);
        }

        $zip->close();

        return (string) file_get_contents($zipPath);
    }

    private function buildUnsafePackage(): string
    {
        $zipPath = $this->root . '/unsafe.zip';
        $zip     = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('../evil.php', '<?php echo "evil";');
        $zip->addFromString('demo/plugin.php', $this->manifest('1.1.0'));
        $zip->close();

        return (string) file_get_contents($zipPath);
    }

    private function manifest(string $version, array $capabilities = []): string
    {
        $capabilityLine = $capabilities === []
            ? ''
            : "    'capabilities' => [" . implode(', ', array_map(
                static fn (string $capability): string => var_export($capability, true),
                $capabilities,
            )) . "],\n";

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
            . "    'name' => 'Demo',\n"
            . "    'version' => " . var_export($version, true) . ",\n"
            . "    'updates' => " . var_export($this->feedUrl, true) . ",\n"
            . $capabilityLine
            . "    'register' => static function (): void {},\n"
            . "];\n";
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
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    public function testUpdateStopsWhenTheReleaseAddsCapabilities(): void
    {
        $this->writePlugin('demo', '1.0.0', ['filesystem_read']);
        $zip = $this->buildPackage('1.1.0', [], ['filesystem_read', 'network']);

        $service = $this->service([
            $this->feedUrl     => $this->jsonFeed('1.1.0', $zip),
            $this->downloadUrl => $zip,
        ]);

        try {
            $service->update('demo');
            self::fail('A release that adds capabilities must ask for approval first.');
        } catch (PluginCapabilitiesChangedException $e) {
            self::assertSame('demo', $e->slug);
            self::assertSame('1.1.0', $e->version);
            self::assertSame(['network'], $e->capabilities);
        }

        self::assertStringContainsString(
            "'version' => '1.0.0'",
            (string) file_get_contents($this->pluginsDir . '/demo/plugin.php'),
        );
        self::assertSame([], $service->status());
    }

    public function testUpdateAppliesApprovedCapabilities(): void
    {
        $this->writePlugin('demo', '1.0.0', ['filesystem_read']);
        $zip = $this->buildPackage('1.1.0', [], ['filesystem_read', 'network']);

        $service = $this->service([
            $this->feedUrl     => $this->jsonFeed('1.1.0', $zip),
            $this->downloadUrl => $zip,
        ]);

        $result = $service->update('demo', '1.1.0');

        self::assertSame('1.1.0', $result['to']);
        self::assertStringContainsString(
            "'network'",
            (string) file_get_contents($this->pluginsDir . '/demo/plugin.php'),
        );
    }

    public function testApprovalDoesNotCarryOverToAnotherVersion(): void
    {
        $this->writePlugin('demo', '1.0.0');
        $zip = $this->buildPackage('1.1.0', [], ['network']);

        $service = $this->service([
            $this->feedUrl     => $this->jsonFeed('1.1.0', $zip),
            $this->downloadUrl => $zip,
        ]);

        $this->expectException(PluginCapabilitiesChangedException::class);
        $service->update('demo', '1.0.9');
    }
}
