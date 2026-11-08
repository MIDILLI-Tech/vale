<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Plugin\InvalidPluginException;
use ValeApp\Application\Plugin\PluginManager;

final class PluginManagerTest extends TestCase
{
    private string $root;
    private string $pluginsDir;
    private InMemorySettingsRepository $settings;

    protected function setUp(): void
    {
        $this->root       = sys_get_temp_dir() . '/vl-plugins-' . bin2hex(random_bytes(4));
        $this->pluginsDir = $this->root . '/plugins';
        mkdir($this->pluginsDir, 0777, true);

        $this->settings = new InMemorySettingsRepository();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testEnabledSlugsIgnoreInvalidAndDuplicateEntries(): void
    {
        $this->settings->values[PluginManager::SETTINGS_KEY] = json_encode([
            'real-plugin',
            '../escape',
            'Bad Slug',
            'real-plugin',
            42,
        ]);

        $manager = $this->manager();

        self::assertSame(['real-plugin'], $manager->getEnabledSlugs());
    }

    public function testEnabledSlugsSurviveCorruptJson(): void
    {
        $this->settings->values[PluginManager::SETTINGS_KEY] = 'not-json';

        self::assertSame([], $this->manager()->getEnabledSlugs());
    }

    public function testEnablePersistsSlugAndIsIdempotent(): void
    {
        $this->createPlugin('hello-world');
        $manager = $this->manager();

        $manager->enable('hello-world');
        $manager->enable('hello-world');

        self::assertSame(['hello-world'], $manager->getEnabledSlugs());
        self::assertSame('["hello-world"]', $this->settings->values[PluginManager::SETTINGS_KEY]);
    }

    public function testEnableDryRunWritesNothing(): void
    {
        $this->createPlugin('hello-world');
        $manager = $this->manager();

        $manager->enable('hello-world', true);

        self::assertSame([], $manager->getEnabledSlugs());
        self::assertSame(0, $this->settings->writes);
    }

    public function testEnableRejectsUnknownPlugin(): void
    {
        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('not installed');

        $this->manager()->enable('ghost');
    }

    public function testEnableRejectsIncompatiblePlugin(): void
    {
        $this->createPlugin('future', "['requires_vale' => '^9.0', 'register' => static fn () => null]");

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('Requires Vale ^9.0');

        $this->manager()->enable('future');
    }

    public function testEnableRefusesMissingPluginDependency(): void
    {
        $this->createPlugin('needs-core', "['requires_plugin' => ['acme-core' => '^1.0'], 'register' => static fn () => null]");

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('not installed');

        $this->manager()->enable('needs-core');
    }

    public function testEnableRefusesDisabledPluginDependency(): void
    {
        $this->createPlugin('acme-core', "['version' => '1.2.0', 'register' => static fn () => null]");
        $this->createPlugin('needs-core', "['requires_plugin' => ['acme-core' => '^1.0'], 'register' => static fn () => null]");

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('to be enabled first');

        $this->manager()->enable('needs-core');
    }

    public function testEnableAcceptsSatisfiedPluginDependency(): void
    {
        $this->createPlugin('acme-core', "['version' => '1.2.0', 'register' => static fn () => null]");
        $this->createPlugin('needs-core', "['requires_plugin' => ['acme-core' => '^1.0'], 'register' => static fn () => null]");
        $manager = $this->manager();

        $manager->enable('acme-core');
        $manager->enable('needs-core');

        self::assertSame(['acme-core', 'needs-core'], $manager->getEnabledSlugs());
    }

    public function testEnableRefusesWrongDependencyVersion(): void
    {
        $this->createPlugin('acme-core', "['version' => '1.0.0', 'register' => static fn () => null]");
        $this->createPlugin('needs-core', "['requires_plugin' => ['acme-core' => '^2.0'], 'register' => static fn () => null]");
        $manager = $this->manager();
        $manager->enable('acme-core');

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('Requires plugin "acme-core" ^2.0 (running 1.0.0)');

        $manager->enable('needs-core');
    }

    public function testFindAllReportsDependencyProblem(): void
    {
        $this->createPlugin('acme-core', "['version' => '1.0.0', 'register' => static fn () => null]");
        $this->createPlugin('needs-core', "['requires_plugin' => ['acme-core' => '^1.0'], 'register' => static fn () => null]");

        $rows = array_column($this->manager()->findAll(), null, 'slug');

        self::assertNotNull($rows['needs-core']['dependency_problem']);
        self::assertNull($rows['acme-core']['dependency_problem']);
        self::assertSame(['acme-core' => '^1.0'], $rows['needs-core']['requires_plugin']);
    }

    public function testDisableRemovesSlug(): void
    {
        $this->createPlugin('hello-world');
        $this->createPlugin('second-plugin');
        $manager = $this->manager();

        $manager->enable('hello-world');
        $manager->enable('second-plugin');
        $manager->disable('hello-world');

        self::assertSame(['second-plugin'], $manager->getEnabledSlugs());
    }

    public function testDeleteFilesRemovesOnlyTheRequestedPlugin(): void
    {
        $this->createPlugin('hello-world');
        $this->createPlugin('second-plugin');
        mkdir($this->pluginsDir . '/hello-world/assets', 0777, true);
        file_put_contents($this->pluginsDir . '/hello-world/assets/app.js', 'console.log(1);');

        $this->manager()->deleteFiles('hello-world');

        self::assertDirectoryDoesNotExist($this->pluginsDir . '/hello-world');
        self::assertDirectoryExists($this->pluginsDir . '/second-plugin');
    }

    public function testDeleteFilesRejectsUnknownPlugin(): void
    {
        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('not installed');

        $this->manager()->deleteFiles('ghost');
    }

    public function testOperatorKillSwitchViaMarkerFile(): void
    {
        touch($this->pluginsDir . '/.disabled');

        self::assertTrue($this->manager()->isDisabledByOperator());
    }

    public function testOperatorKillSwitchViaConstructor(): void
    {
        self::assertTrue($this->manager(operatorDisabled: true)->isDisabledByOperator());
        self::assertFalse($this->manager()->isDisabledByOperator());
    }

    public function testGlobalSwitchDefaultsToEnabled(): void
    {
        self::assertTrue($this->manager()->isGloballyEnabled());
    }

    public function testGlobalSwitchPersistsAndCanBeReenabled(): void
    {
        $manager = $this->manager();

        $manager->setGloballyEnabled(false);
        self::assertFalse($manager->isGloballyEnabled());
        self::assertSame('0', $this->settings->values[PluginManager::GLOBAL_KEY]);

        $manager->setGloballyEnabled(true);
        self::assertTrue($manager->isGloballyEnabled());
        self::assertSame('1', $this->settings->values[PluginManager::GLOBAL_KEY]);
    }

    public function testGlobalSwitchDryRunWritesNothing(): void
    {
        $this->manager()->setGloballyEnabled(false, true);

        self::assertTrue($this->manager()->isGloballyEnabled());
        self::assertSame(0, $this->settings->writes);
    }

    public function testResolveDirectoryRejectsTraversalAndMissingDirs(): void
    {
        $this->createPlugin('hello-world');
        $manager = $this->manager();

        self::assertNotNull($manager->resolveDirectory('hello-world'));
        self::assertNull($manager->resolveDirectory('../plugins/hello-world'));
        self::assertNull($manager->resolveDirectory('/etc'));
        self::assertNull($manager->resolveDirectory('ghost'));
    }

    public function testFindAllReportsInvalidManifestsWithoutAborting(): void
    {
        $this->createPlugin('good-plugin');
        $this->createPlugin('broken-plugin', 'null');
        $this->createPlugin('ignored-dir');
        file_put_contents($this->pluginsDir . '/ignored-dir/plugin.php', '<?php return ');

        $manager = $this->manager();
        $manager->enable('good-plugin');

        $rows = $manager->findAll();

        self::assertSame(['broken-plugin', 'good-plugin', 'ignored-dir'], array_column($rows, 'slug'));

        $bySlug = array_column($rows, null, 'slug');
        self::assertTrue($bySlug['good-plugin']['enabled']);
        self::assertNull($bySlug['good-plugin']['error']);
        self::assertNotNull($bySlug['broken-plugin']['error']);
        self::assertNotNull($bySlug['ignored-dir']['error']);
        self::assertFalse($bySlug['broken-plugin']['enabled']);
    }

    public function testFindAllExposesTheUpdateFeedUrl(): void
    {
        $this->createPlugin('updatable', "['updates' => 'https://example.test/updates.json', 'register' => static fn () => null]");

        $rows = $this->manager()->findAll();

        self::assertSame('https://example.test/updates.json', $rows[0]['updates']);
    }

    public function testFindAllSkipsDotAndUnsafeDirectories(): void
    {
        $this->createPlugin('good-plugin');
        mkdir($this->pluginsDir . '/Bad_Name', 0777, true);
        file_put_contents($this->pluginsDir . '/Bad_Name/plugin.php', "<?php return ['register' => static fn () => null];");

        $rows = $this->manager()->findAll();

        self::assertSame(['good-plugin'], array_column($rows, 'slug'));
    }

    public function testRecordFailureIsThrottledUntilMessageChanges(): void
    {
        $manager = $this->manager();

        $manager->recordFailure('broken', 'Boom');
        self::assertSame(1, $this->settings->writes);

        $manager->recordFailure('broken', 'Boom');
        self::assertSame(1, $this->settings->writes);

        $manager->recordFailure('broken', 'Boom: other reason');
        self::assertSame(2, $this->settings->writes);

        $failures = $manager->failures();
        self::assertSame('Boom: other reason', $failures['broken']['message']);
        self::assertGreaterThan(0, $failures['broken']['at']);
    }

    public function testClearFailureRemovesEntry(): void
    {
        $manager = $this->manager();
        $manager->recordFailure('broken', 'Boom');

        $manager->clearFailure('broken');

        self::assertSame([], $manager->failures());
    }

    public function testLifecycleDatesAreExposedAndForgotten(): void
    {
        $this->createPlugin('hello-world');
        $manager = $this->manager();

        $manager->recordInstalled('hello-world');
        $row = $manager->findAll()[0];

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $row['installed_at']);
        self::assertSame($row['installed_at'], $row['updated_at']);

        $manager->recordUpdated('hello-world', '2020-01-01 00:00:00');
        $row = $manager->findAll()[0];

        self::assertSame('2020-01-01 00:00:00', $row['installed_at']);
        self::assertNotSame('2020-01-01 00:00:00', $row['updated_at']);

        $manager->deleteFiles('hello-world');

        self::assertNull($manager->lifecycle('hello-world')['installed_at']);
        self::assertNull($manager->lifecycle('hello-world')['updated_at']);
    }

    public function testFindAllExposesAdminMenu(): void
    {
        $this->createPlugin(
            'nav-plugin',
            "['admin' => ['label' => 'Nav', 'href' => '/admin/nav'], 'register' => static fn () => null]",
        );

        $row = $this->manager()->findAll()[0];

        self::assertSame('/admin/nav', $row['admin']['href']);
    }

    public function testHiddenNavSlugsPersistAndCanBeRestored(): void
    {
        $manager = $this->manager();

        $manager->setNavHidden('hello-world', true);
        self::assertSame(['hello-world'], $manager->getHiddenNavSlugs());
        self::assertSame('["hello-world"]', $this->settings->values[PluginManager::NAV_HIDDEN_KEY]);

        $manager->setNavHidden('hello-world', false);
        self::assertSame([], $manager->getHiddenNavSlugs());
    }

    public function testHiddenNavSlugsIgnoreInvalidEntries(): void
    {
        $this->settings->values[PluginManager::NAV_HIDDEN_KEY] = '["ok-plugin","../bad","ok-plugin",42]';

        self::assertSame(['ok-plugin'], $this->manager()->getHiddenNavSlugs());
    }

    private function manager(bool $operatorDisabled = false): PluginManager
    {
        return new PluginManager($this->settings, $this->pluginsDir, '0.77.3', $operatorDisabled);
    }

    private function createPlugin(string $slug, string $manifestBody = "['name' => 'Plugin', 'register' => static fn () => null]"): void
    {
        $dir = $this->pluginsDir . '/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir . '/plugin.php', "<?php\n\nreturn " . $manifestBody . ";\n");
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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
