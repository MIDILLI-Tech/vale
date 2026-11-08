<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Theme;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Theme\ThemeManager;

final class ThemeManagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        // Create a temporary themes directory structure for testing
        $this->tmpDir = sys_get_temp_dir() . '/vale_test_themes_' . uniqid();
        mkdir($this->tmpDir . '/default/templates', 0o777, true);
        mkdir($this->tmpDir . '/dark/templates', 0o777, true);
        mkdir($this->tmpDir . '/child/templates', 0o777, true);

        file_put_contents($this->tmpDir . '/default/theme.json', json_encode([
            'name'        => 'Default Theme',
            'version'     => '1.0.0',
            'description' => 'The default Vale theme',
            'author'      => 'Vale',
        ]));

        file_put_contents($this->tmpDir . '/dark/theme.json', json_encode([
            'name'    => 'Dark Theme',
            'version' => '2.0.0',
            'author'  => 'Community',
        ]));

        file_put_contents($this->tmpDir . '/child/theme.json', json_encode([
            'name'   => 'Child Theme',
            'parent' => 'default',
        ]));

        // Add a real template to dark theme so it registers in buildTemplatePaths
        file_put_contents($this->tmpDir . '/dark/templates/layout.twig', '<html>dark</html>');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testBundledThemeDetection(): void
    {
        $manager = new ThemeManager($this->tmpDir, 'default');

        self::assertTrue($manager->isBundledTheme('default'));
        self::assertTrue($manager->isBundledTheme('nova'));
        self::assertFalse($manager->isBundledTheme('child'));
        self::assertFalse($manager->isBundledTheme(''));
    }

    public function testEveryBundledThemeSlugExistsInTheRelease(): void
    {
        $rootDir = dirname(__DIR__, 4);

        foreach (ThemeManager::BUNDLED_THEMES as $slug) {
            self::assertFileExists(
                $rootDir . '/themes/' . $slug . '/theme.json',
                sprintf('Bundled theme "%s" is missing from themes/.', $slug),
            );
        }
    }

    public function testGetActiveThemeReturnsCorrectSlug(): void
    {
        $manager = new ThemeManager($this->tmpDir, 'default');
        self::assertSame('default', $manager->getActiveTheme());
    }

    public function testGetTablePaletteReturnsThemeValues(): void
    {
        file_put_contents($this->tmpDir . '/dark/theme.json', json_encode([
            'name'   => 'Dark Theme',
            'tables' => ['border' => '#475569', 'header_background' => '#1e293b'],
        ]));

        $manager = new ThemeManager($this->tmpDir, 'dark');

        self::assertSame(
            ['border' => '#475569', 'header_background' => '#1e293b'],
            $manager->getTablePalette('dark'),
        );
    }

    public function testGetTablePaletteFallsBackForMissingOrInvalidValues(): void
    {
        file_put_contents($this->tmpDir . '/dark/theme.json', json_encode([
            'name'   => 'Dark Theme',
            'tables' => ['border' => 'red; background: url(x)', 'header_background' => '#1e293b'],
        ]));

        $manager = new ThemeManager($this->tmpDir, 'dark');

        self::assertSame(
            ['border' => '#d1d5db', 'header_background' => '#1e293b'],
            $manager->getTablePalette('dark'),
        );
        self::assertSame(
            ['border' => '#d1d5db', 'header_background' => '#f3f4f6'],
            $manager->getTablePalette('missing'),
        );
    }

    public function testDiscoverThemesFindsAllThemesWithJson(): void
    {
        $manager = new ThemeManager($this->tmpDir, 'default');
        $themes  = $manager->discoverThemes();

        self::assertArrayHasKey('default', $themes);
        self::assertArrayHasKey('dark', $themes);
        self::assertArrayHasKey('child', $themes);
        self::assertSame('Default Theme', $themes['default']['name']);
        self::assertSame('default', $themes['default']['slug']);
    }

    public function testGetThemeMetaReturnsActiveThemeMeta(): void
    {
        $manager = new ThemeManager($this->tmpDir, 'dark');
        $meta    = $manager->getThemeMeta();

        self::assertSame('Dark Theme', $meta['name']);
        self::assertSame('2.0.0', $meta['version']);
        self::assertSame('dark', $meta['slug']);
    }

    public function testGetThemeMetaReturnsFallbackForMissingTheme(): void
    {
        $manager = new ThemeManager($this->tmpDir, 'nonexistent');
        $meta    = $manager->getThemeMeta();

        // Should still return the slug even if no theme.json found
        self::assertSame('nonexistent', $meta['slug']);
    }

    public function testBuildTemplatePathsIncludesCoreViewsAsLast(): void
    {
        $coreViews = '/var/www/resources/views';
        $manager   = new ThemeManager($this->tmpDir, 'default');
        $paths     = $manager->buildTemplatePaths($coreViews);

        self::assertContains($coreViews, $paths);
        self::assertSame($coreViews, end($paths));
    }

    public function testBuildTemplatePathsPrependsActiveThemeWhenTemplatesExist(): void
    {
        $coreViews = '/var/www/resources/views';
        $manager   = new ThemeManager($this->tmpDir, 'dark'); // dark has templates
        $paths     = $manager->buildTemplatePaths($coreViews);

        self::assertSame($this->tmpDir . '/dark/templates', $paths[0]);
    }

    public function testBuildTemplatePathsIncludesParentThemeForChildTheme(): void
    {
        // Add template to child theme so it gets prepended
        file_put_contents($this->tmpDir . '/child/templates/home.twig', '<html>child</html>');

        $coreViews = '/var/www/resources/views';
        $manager   = new ThemeManager($this->tmpDir, 'child');
        $paths     = $manager->buildTemplatePaths($coreViews);

        // child templates should come first, then parent (default) templates if they exist
        self::assertSame($this->tmpDir . '/child/templates', $paths[0]);
        self::assertContains($coreViews, $paths);
    }

    public function testHookSystemRegistersAndExecutesCallables(): void
    {
        $manager = new ThemeManager($this->tmpDir, 'default');
        $manager->addHook('header_scripts', fn () => '<script src="app.js"></script>');
        $manager->addHook('header_scripts', fn () => '<style>body{}</style>');

        $output = $manager->doHook('header_scripts');

        self::assertStringContainsString('<script src="app.js"></script>', $output);
        self::assertStringContainsString('<style>body{}</style>', $output);
    }

    public function testHookSystemRespectsCallablePriority(): void
    {
        $manager = new ThemeManager($this->tmpDir, 'default');
        $result  = [];

        $manager->addHook('sidebar', function () use (&$result) {
            $result[] = 'second';
            return 'second';
        }, 20);
        $manager->addHook('sidebar', function () use (&$result) {
            $result[] = 'first';
            return 'first';
        }, 5);

        $manager->doHook('sidebar');

        self::assertSame(['first', 'second'], $result);
    }

    public function testDoHookReturnsEmptyStringForUnregisteredHook(): void
    {
        $manager = new ThemeManager($this->tmpDir, 'default');
        self::assertSame('', $manager->doHook('nonexistent_hook'));
    }

    public function testGetDefinedMenusReturnsMenusFromThemeJson(): void
    {
        file_put_contents($this->tmpDir . '/default/theme.json', json_encode([
            'name'  => 'Default Theme',
            'menus' => [
                'primary' => 'Primary Nav',
                'footer'  => 'Footer Links',
            ],
        ]));

        $manager = new ThemeManager($this->tmpDir, 'default');
        $menus   = $manager->getDefinedMenus();

        self::assertCount(2, $menus);
        self::assertSame('Primary Nav', $menus['primary']);
        self::assertSame('Footer Links', $menus['footer']);
    }

    public function testGetDefinedMenusReturnsEmptyArrayWhenNoMenusDefined(): void
    {
        file_put_contents($this->tmpDir . '/default/theme.json', json_encode([
            'name' => 'Default Theme',
        ]));

        $manager = new ThemeManager($this->tmpDir, 'default');
        $menus   = $manager->getDefinedMenus();

        self::assertIsArray($menus);
        self::assertEmpty($menus);
    }

    public function testGetColorVariablesSchemaReturnsThemeVariables(): void
    {
        file_put_contents($this->tmpDir . '/default/theme.json', json_encode([
            'name'            => 'Default Theme',
            'color_variables' => [
                '--theme-primary' => ['label' => 'Primary', 'default' => '#123456'],
            ],
        ]));

        $manager = new ThemeManager($this->tmpDir, 'default');
        $schema  = $manager->getColorVariablesSchema('default');

        self::assertArrayHasKey('--theme-primary', $schema);
        self::assertSame('#123456', $schema['--theme-primary']['default']);
    }

    public function testGetColorVariablesSchemaReturnsFallbackWhenNotDefined(): void
    {
        file_put_contents($this->tmpDir . '/default/theme.json', json_encode([
            'name' => 'Default Theme',
        ]));

        $manager = new ThemeManager($this->tmpDir, 'default');
        $schema  = $manager->getColorVariablesSchema('default');

        self::assertArrayHasKey('--theme-primary', $schema);
        self::assertArrayHasKey('--theme-secondary', $schema);
    }

    public function testResolveColorVariablesMergesSchemaWithSavedOverrides(): void
    {
        file_put_contents($this->tmpDir . '/default/theme.json', json_encode([
            'name'            => 'Default Theme',
            'color_variables' => [
                '--theme-primary' => ['label' => 'Primary Color', 'default' => '#111827'],
                '--theme-accent'  => ['label' => 'Accent', 'default' => '#2563eb'],
            ],
        ]));

        $manager  = new ThemeManager($this->tmpDir, 'default');
        $resolved = $manager->resolveColorVariables('default', ['--theme-primary' => '#ff0000']);

        self::assertSame(['label' => 'Primary Color', 'value' => '#ff0000'], $resolved['--theme-primary']);
        self::assertSame(['label' => 'Accent', 'value' => '#2563eb'], $resolved['--theme-accent']);
    }

    public function testResolveColorVariablesSkipsInvalidValuesAndNonVariableKeys(): void
    {
        file_put_contents($this->tmpDir . '/default/theme.json', json_encode([
            'name'            => 'Default Theme',
            'color_variables' => [
                '--theme-primary' => ['label' => 'Primary Color', 'default' => '#111827'],
                '--theme-bad'     => ['label' => 'Bad', 'default' => 'red'],
                'not-a-variable'  => ['label' => 'Nope', 'default' => '#123456'],
            ],
        ]));

        $manager  = new ThemeManager($this->tmpDir, 'default');
        $resolved = $manager->resolveColorVariables('default', ['--theme-primary' => 'javascript:alert(1)']);

        self::assertArrayHasKey('--theme-primary', $resolved);
        self::assertSame('#111827', $resolved['--theme-primary']['value']);
        self::assertArrayNotHasKey('--theme-bad', $resolved);
        self::assertArrayNotHasKey('not-a-variable', $resolved);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
