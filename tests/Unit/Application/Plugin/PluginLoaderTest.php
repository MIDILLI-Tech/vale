<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use ValeApp\Application\Plugin\PluginLoader;
use ValeApp\Application\Plugin\PluginManager;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Event\EventDispatcher;

final class PluginLoaderTest extends TestCase
{
    private string $root;
    private string $pluginsDir;
    private string $muDir;
    private InMemorySettingsRepository $settings;

    protected function setUp(): void
    {
        $this->root       = sys_get_temp_dir() . '/vl-loader-' . bin2hex(random_bytes(4));
        $this->pluginsDir = $this->root . '/plugins';
        $this->muDir      = $this->root . '/mu-plugins';
        mkdir($this->pluginsDir, 0777, true);
        mkdir($this->muDir, 0777, true);

        $this->settings = new InMemorySettingsRepository();

        $GLOBALS['vl_test_calls'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['vl_test_calls'], $GLOBALS['vl_test_app'], $GLOBALS['vl_test_container']);
        $this->removeDirectory($this->root);
    }

    public function testLoadsMuPluginsWithoutActivation(): void
    {
        $this->writeMuPlugin('always-on', 'mu-ran');

        $report = $this->loader()->load();

        self::assertContains('mu-always-on', $report['loaded']);
        self::assertSame(['mu-ran'], $GLOBALS['vl_test_calls']);
        self::assertFalse($report['skipped']);
    }

    public function testPassesAppAndContainerToRegister(): void
    {
        $this->createPlugin('demo', <<<'PHP'
            [
                'register' => static function ($app, $container): void {
                    $GLOBALS['vl_test_calls'][] = 'registered';
                    $GLOBALS['vl_test_same_app'] = $app === $GLOBALS['vl_test_app'];
                    $GLOBALS['vl_test_same_container'] = $container === $GLOBALS['vl_test_container'];
                },
            ]
            PHP);
        $this->enable('demo');

        $app                          = AppFactory::create();
        $container                    = new Container();
        $GLOBALS['vl_test_app']       = $app;
        $GLOBALS['vl_test_container'] = $container;

        $report = $this->loader($app, $container)->load();

        self::assertSame(['demo'], $report['loaded']);
        self::assertSame(['registered'], $GLOBALS['vl_test_calls']);
        self::assertTrue($GLOBALS['vl_test_same_app']);
        self::assertTrue($GLOBALS['vl_test_same_container']);
    }

    public function testBrokenPluginDoesNotStopTheOthers(): void
    {
        $this->createPlugin('aaa-throws', <<<'PHP'
            ['priority' => 1, 'register' => static function (): void {
                throw new \RuntimeException('boom');
            }]
            PHP);
        $this->createPlugin('zzz-works', <<<'PHP'
            ['priority' => 10, 'register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = 'zzz';
            }]
            PHP);
        $this->enable('aaa-throws');
        $this->enable('zzz-works');

        $report = $this->loader()->load();

        self::assertSame(['zzz-works'], $report['loaded']);
        self::assertStringContainsString('boom', $report['failed']['aaa-throws']);
        self::assertSame(['zzz'], $GLOBALS['vl_test_calls']);

        // The failure is persisted for the admin UI, once.
        self::assertArrayHasKey('aaa-throws', $this->manager()->failures());
    }

    public function testPriorityOrdersRegistration(): void
    {
        $this->createPlugin('second', <<<'PHP'
            ['priority' => 20, 'register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = 'second';
            }]
            PHP);
        $this->createPlugin('first', <<<'PHP'
            ['priority' => 5, 'register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = 'first';
            }]
            PHP);
        $this->enable('second');
        $this->enable('first');

        $this->loader()->load();

        self::assertSame(['first', 'second'], $GLOBALS['vl_test_calls']);
    }

    public function testIncompatiblePluginIsSkippedWithoutRunningCode(): void
    {
        $this->createPlugin('too-new', <<<'PHP'
            ['requires_vale' => '^9.0', 'register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = 'should-not-run';
            }]
            PHP);
        // A stale activation entry (e.g. after a downgrade): enable() refuses it,
        // but the loader must still skip it instead of running plugin code.
        $this->settings->values[PluginManager::SETTINGS_KEY] = '["too-new"]';

        $report = $this->loader()->load();

        self::assertSame([], $report['loaded']);
        self::assertStringContainsString('Requires Vale ^9.0', $report['failed']['too-new']);
        self::assertSame([], $GLOBALS['vl_test_calls']);
    }

    public function testMissingPluginDirectoryIsReported(): void
    {
        $this->settings->values[PluginManager::SETTINGS_KEY] = '["deleted-plugin"]';

        $report = $this->loader()->load();

        self::assertSame(['deleted-plugin' => 'Plugin directory is missing.'], $report['failed']);
    }

    public function testOperatorKillSwitchSkipsEverything(): void
    {
        $this->writeMuPlugin('always-on', 'mu-ran');
        $this->createPlugin('demo', <<<'PHP'
            ['register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = 'demo';
            }]
            PHP);
        $this->enable('demo');

        $report = $this->loader(operatorDisabled: true)->load();

        self::assertTrue($report['skipped']);
        self::assertSame([], $report['loaded']);
        self::assertSame([], $GLOBALS['vl_test_calls']);
    }

    public function testGlobalSwitchSkipsRegularAndMuPlugins(): void
    {
        $this->writeMuPlugin('always-on', 'mu-ran');
        $this->createPlugin('demo', <<<'PHP'
            ['register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = 'demo';
            }]
            PHP);
        $this->enable('demo');
        $this->settings->values[PluginManager::GLOBAL_KEY] = '0';

        $report = $this->loader()->load();

        self::assertTrue($report['skipped']);
        self::assertSame([], $report['loaded']);
        self::assertSame([], $GLOBALS['vl_test_calls']);
    }

    public function testRegistersPsr4AutoloaderForPluginClasses(): void
    {
        $this->createPlugin('autoload-demo', <<<'PHP'
            ['register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = class_exists('ValeApp\\Plugins\\AutoloadDemo\\Widget') ? 'loaded' : 'missing';
            }]
            PHP);
        mkdir($this->pluginsDir . '/autoload-demo/src', 0777, true);
        file_put_contents(
            $this->pluginsDir . '/autoload-demo/src/Widget.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace ValeApp\\Plugins\\AutoloadDemo;\n\nfinal class Widget\n{\n}\n",
        );
        $this->enable('autoload-demo');

        $this->loader()->load();

        self::assertSame(['loaded'], $GLOBALS['vl_test_calls']);
    }

    public function testRegistersTwigNamespaceForPluginTemplates(): void
    {
        $this->createPlugin('twig-demo');
        mkdir($this->pluginsDir . '/twig-demo/templates', 0777, true);
        file_put_contents($this->pluginsDir . '/twig-demo/templates/card.twig', 'card');
        $this->enable('twig-demo');

        $twig = Twig::create([], ['cache' => false]);
        $this->loader(twig: $twig)->load();

        $loader = $twig->getEnvironment()->getLoader();
        self::assertInstanceOf(\Twig\Loader\FilesystemLoader::class, $loader);
        self::assertContains(
            str_replace('\\', '/', $this->pluginsDir . '/twig-demo/templates'),
            array_map(static fn (string $path): string => str_replace('\\', '/', $path), $loader->getPaths('twig-demo')),
        );
    }

    public function testManifestAdminEntryIsInjectedIntoItsGroup(): void
    {
        $this->createPlugin('with-menu', <<<'PHP'
            [
                'admin' => [
                    'label'      => 'Acme Tax',
                    'href'       => '/admin/acme-tax',
                    'group'      => 'group_content',
                    'permission' => 'settings.view',
                ],
                'register' => static function (): void {},
            ]
            PHP);
        $this->enable('with-menu');

        $container = new Container();
        $events    = new EventDispatcher();
        $container->set(EventDispatcherInterface::class, $events);

        $this->loader(container: $container)->load();

        $groups = $events->applyFilter('admin_nav_groups', [
            ['id' => 'group_content', 'type' => 'group', 'label' => 'Content', 'children' => []],
        ]);

        self::assertSame('Acme Tax', $groups[0]['children'][0]['label']);
        self::assertSame('/admin/acme-tax', $groups[0]['children'][0]['href']);
        self::assertSame('settings.view', $groups[0]['children'][0]['permission']);
        self::assertSame('plugin-with-menu', $groups[0]['children'][0]['id']);
    }

    public function testManifestAdminEntryFallsBackToTopLevelForUnknownGroup(): void
    {
        $this->createPlugin('solo-menu', <<<'PHP'
            [
                'admin' => ['label' => 'Solo', 'href' => '/admin/solo', 'group' => 'group_does_not_exist'],
                'register' => static function (): void {},
            ]
            PHP);
        $this->enable('solo-menu');

        $container = new Container();
        $events    = new EventDispatcher();
        $container->set(EventDispatcherInterface::class, $events);

        $this->loader(container: $container)->load();

        $groups = $events->applyFilter('admin_nav_groups', [
            ['id' => 'group_content', 'type' => 'group', 'label' => 'Content', 'children' => []],
        ]);

        self::assertCount(2, $groups);
        self::assertSame('link', $groups[1]['type']);
        self::assertSame('Solo', $groups[1]['label']);
    }

    public function testSavedOrderPositionsADeclarativeAdminEntry(): void
    {
        $this->createPlugin('sorted-menu', <<<'PHP'
            [
                'admin' => ['label' => 'Sorted', 'href' => '/admin/sorted'],
                'register' => static function (): void {},
            ]
            PHP);
        $this->enable('sorted-menu');
        $this->settings->values['admin_nav_order'] = '["dashboard","plugin-sorted-menu","group_content"]';

        $container = new Container();
        $events    = new EventDispatcher();
        $container->set(EventDispatcherInterface::class, $events);
        $container->set(SettingsRepositoryInterface::class, $this->settings);

        $this->loader(container: $container)->load();

        $groups = $events->applyFilter('admin_nav_groups', [
            ['id' => 'dashboard', 'type' => 'link', 'label' => 'Dashboard', 'href' => '/admin', 'icon' => ''],
            ['id' => 'group_content', 'type' => 'group', 'label' => 'Content', 'children' => []],
        ]);

        self::assertSame(
            ['dashboard', 'plugin-sorted-menu', 'group_content'],
            array_column($groups, 'id'),
        );
    }

    public function testPluginWithMissingPluginDependencyIsSkipped(): void
    {
        $this->createPlugin('needs-core', <<<'PHP'
            ['requires_plugin' => ['acme-core' => '^1.0'], 'register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = 'should-not-run';
            }]
            PHP);
        $this->settings->values[PluginManager::SETTINGS_KEY] = '["needs-core"]';

        $report = $this->loader()->load();

        self::assertSame([], $report['loaded']);
        self::assertStringContainsString('Requires plugin "acme-core"', $report['failed']['needs-core']);
        self::assertSame([], $GLOBALS['vl_test_calls']);
    }

    public function testPluginWithSatisfiedDependencyLoadsAfterIt(): void
    {
        $this->createPlugin('acme-core', <<<'PHP'
            ['version' => '1.2.0', 'priority' => 5, 'register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = 'core';
            }]
            PHP);
        $this->createPlugin('needs-core', <<<'PHP'
            ['version' => '1.0.0', 'priority' => 20, 'requires_plugin' => ['acme-core' => '^1.0'],
                'register' => static function (): void {
                    $GLOBALS['vl_test_calls'][] = 'dependent';
                }]
            PHP);
        $this->settings->values[PluginManager::SETTINGS_KEY] = '["needs-core","acme-core"]';

        $report = $this->loader()->load();

        self::assertSame(['acme-core', 'needs-core'], $report['loaded']);
        self::assertSame(['core', 'dependent'], $GLOBALS['vl_test_calls']);
    }

    public function testPluginWithMissingPhpExtensionIsSkipped(): void
    {
        $this->createPlugin('needs-ext', <<<'PHP'
            ['requires_ext' => ['vl_missing_ext'], 'register' => static function (): void {
                $GLOBALS['vl_test_calls'][] = 'should-not-run';
            }]
            PHP);
        $this->settings->values[PluginManager::SETTINGS_KEY] = '["needs-ext"]';

        $report = $this->loader()->load();

        self::assertSame([], $report['loaded']);
        self::assertStringContainsString('Requires PHP extension(s): vl_missing_ext', $report['failed']['needs-ext']);
        self::assertSame([], $GLOBALS['vl_test_calls']);
    }

    private function loader(
        ?\Slim\App $app = null,
        ?Container $container = null,
        bool $operatorDisabled = false,
        ?Twig $twig = null,
    ): PluginLoader {
        return new PluginLoader(
            $this->manager($operatorDisabled),
            $this->muDir,
            $app       ?? AppFactory::create(),
            $container ?? new Container(),
            $twig,
        );
    }

    private function manager(bool $operatorDisabled = false): PluginManager
    {
        return new PluginManager($this->settings, $this->pluginsDir, '0.77.3', $operatorDisabled);
    }

    private function enable(string $slug): void
    {
        $this->manager()->enable($slug);
    }

    private function writeMuPlugin(string $name, string $marker): void
    {
        file_put_contents(
            $this->muDir . '/' . $name . '.php',
            "<?php\n\nreturn static function (\$app, \$container) {\n    \$GLOBALS['vl_test_calls'][] = '" . $marker . "';\n};\n",
        );
    }

    private function createPlugin(string $slug, string $manifestBody = "['register' => static fn () => null]"): void
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
