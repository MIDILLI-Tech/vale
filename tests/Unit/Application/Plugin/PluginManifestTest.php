<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Plugin;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Plugin\InvalidPluginException;
use ValeApp\Application\Plugin\PluginManifest;

final class PluginManifestTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vl-manifest-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/plugin.php');
        @rmdir($this->dir);
    }

    public function testBuildsManifestWithDefaults(): void
    {
        $file = $this->writeManifest(
            "['name' => 'Hello World', 'version' => '1.2.3', 'register' => static fn () => null]",
        );

        $manifest = PluginManifest::fromFile($file, 'hello-world');

        self::assertSame('hello-world', $manifest->slug);
        self::assertSame('Hello World', $manifest->name);
        self::assertSame('1.2.3', $manifest->version);
        self::assertSame(10, $manifest->priority);
        self::assertSame('ValeApp\\Plugins\\HelloWorld', $manifest->namespace);
        self::assertSame($this->dir, $manifest->directory);
    }

    public function testReadsOptionalMetadata(): void
    {
        $file = $this->writeManifest(
            "['name' => 'Acme', 'requires_vale' => '^0.77', 'requires_php' => '>=8.3',"
            . " 'priority' => 5, 'namespace' => 'Acme\\\\Plugin', 'register' => static fn () => null]",
        );

        $manifest = PluginManifest::fromFile($file, 'acme');

        self::assertSame('^0.77', $manifest->requiresVale);
        self::assertSame('>=8.3', $manifest->requiresPhp);
        self::assertSame(5, $manifest->priority);
        self::assertSame('Acme\\Plugin', $manifest->namespace);
        self::assertNull($manifest->incompatibilityReason('0.77.3', '8.3.0'));
    }

    public function testRejectsMissingRegisterCallable(): void
    {
        $file = $this->writeManifest("['name' => 'Broken']");

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('callable "register"');

        PluginManifest::fromFile($file, 'broken');
    }

    public function testRejectsManifestThatDoesNotReturnArray(): void
    {
        $file = $this->writeManifest("'not-an-array'");

        $this->expectException(InvalidPluginException::class);

        PluginManifest::fromFile($file, 'scalar');
    }

    public function testRejectsUnsafeSlug(): void
    {
        $file = $this->writeManifest("['register' => static fn () => null]");

        $this->expectException(InvalidPluginException::class);
        $this->expectExceptionMessage('Invalid plugin slug');

        PluginManifest::fromFile($file, '../evil');
    }

    public function testReportsIncompatibleValeVersion(): void
    {
        $file = $this->writeManifest(
            "['requires_vale' => '^9.0', 'register' => static fn () => null]",
        );

        $manifest = PluginManifest::fromFile($file, 'future');

        self::assertSame(
            'Requires Vale ^9.0 (running 0.77.3).',
            $manifest->incompatibilityReason('0.77.3'),
        );
        self::assertNull($manifest->incompatibilityReason('9.1.0'));
    }

    public function testReportsIncompatiblePhpVersion(): void
    {
        $file = $this->writeManifest(
            "['requires_php' => '>=8.4', 'register' => static fn () => null]",
        );

        $manifest = PluginManifest::fromFile($file, 'php-gated');

        self::assertSame(
            'Requires PHP >=8.4 (running 8.3.0).',
            $manifest->incompatibilityReason('0.77.3', '8.3.0'),
        );
        self::assertNull($manifest->incompatibilityReason('0.77.3', '8.4.2'));
    }

    public function testSlugPatternOnlyAllowsFilesystemSafeNames(): void
    {
        self::assertTrue(PluginManifest::isValidSlug('my-plugin'));
        self::assertTrue(PluginManifest::isValidSlug('plugin_2'));
        self::assertFalse(PluginManifest::isValidSlug('../etc'));
        self::assertFalse(PluginManifest::isValidSlug('My-Plugin'));
        self::assertFalse(PluginManifest::isValidSlug('plugin.php'));
        self::assertFalse(PluginManifest::isValidSlug(''));
    }

    public function testNormalizesDeclarativeAdminMenu(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'admin' => ["
            . "'label' => 'Acme Tax', 'href' => 'admin/acme-tax', 'group' => 'group_content',"
            . " 'permission' => 'settings.view', 'icon' => 'M3 3h18v18H3z']]",
        );

        $manifest = PluginManifest::fromFile($file, 'acme-tax');

        self::assertSame([
            'label'      => 'Acme Tax',
            'href'       => '/admin/acme-tax',
            'group'      => 'group_content',
            'permission' => 'settings.view',
            'icon'       => 'M3 3h18v18H3z',
        ], $manifest->admin);
    }

    public function testAdminMenuFallsBackToDefaultIconAndNoGroup(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'admin' => ['label' => 'Solo', 'href' => '/admin']]",
        );

        $manifest = PluginManifest::fromFile($file, 'solo');

        self::assertNotNull($manifest->admin);
        self::assertNull($manifest->admin['group']);
        self::assertNull($manifest->admin['permission']);
        self::assertNotSame('', $manifest->admin['icon']);
    }

    public function testRejectsAdminMenuThatIsNotAnAdminPath(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'admin' => ['label' => 'Evil', 'href' => 'https://evil.test/admin']]",
        );

        self::assertNull(PluginManifest::fromFile($file, 'evil')->admin);
    }

    public function testReadsOptionalSettingsHref(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'settings' => 'admin/hello-display']",
        );

        self::assertSame('/admin/hello-display', PluginManifest::fromFile($file, 'hello')->settings);
    }

    public function testRejectsNonAdminSettingsHref(): void
    {
        foreach (['https://evil.test/admin', '/account', 'javascript:alert(1)'] as $href) {
            $file = $this->writeManifest(
                "['register' => static fn () => null, 'settings' => '" . $href . "']",
            );

            self::assertNull(PluginManifest::fromFile($file, 'hello')->settings, $href);
        }
    }

    public function testIgnoresAdminMenuWithoutLabel(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'admin' => ['href' => '/admin/mine']]",
        );

        self::assertNull(PluginManifest::fromFile($file, 'mine')->admin);
    }

    public function testReadsDescriptionAndRequiredExtensions(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'description' => '  Adds tax rules  ',"
            . " 'requires_ext' => ['CURL', ' gd ', 'curl', 'bad name!']]",
        );

        $manifest = PluginManifest::fromFile($file, 'tax');

        self::assertSame('Adds tax rules', $manifest->description);
        self::assertSame(['curl', 'gd'], $manifest->requiresExt);
    }

    public function testReadsLicenseAndHomepage(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'license' => 'MIT', 'homepage' => 'https://example.test/acme']",
        );

        $manifest = PluginManifest::fromFile($file, 'acme');

        self::assertSame('MIT', $manifest->license);
        self::assertSame('https://example.test/acme', $manifest->homepage);
    }

    public function testReadsOptionalUpdateFeedUrl(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'updates' => ['url' => 'https://example.test/updates.json']]",
        );

        self::assertSame(
            'https://example.test/updates.json',
            PluginManifest::fromFile($file, 'updatable')->updates,
        );
    }

    public function testRejectsNonHttpUpdateFeedUrl(): void
    {
        foreach (['ftp://example.test/updates.json', 'javascript:alert(1)', '/updates.json'] as $url) {
            $file = $this->writeManifest(
                "['register' => static fn () => null, 'updates' => '" . $url . "']",
            );

            self::assertNull(PluginManifest::fromFile($file, 'bad-updates')->updates, $url);
        }
    }

    public function testRejectsHomepageThatIsNotAnHttpUrl(): void
    {
        foreach (['javascript:alert(1)', 'ftp://example.test/x', '/relative/path'] as $url) {
            $file = $this->writeManifest(
                "['register' => static fn () => null, 'homepage' => '" . $url . "']",
            );

            self::assertNull(PluginManifest::fromFile($file, 'bad-home')->homepage, $url);
        }
    }

    public function testReadsPluginDependencies(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'requires_plugin' => ['acme-core' => '^1.0', 'acme-utils', 'bad slug']]",
        );

        $manifest = PluginManifest::fromFile($file, 'dependent');

        self::assertSame(['acme-core' => '^1.0', 'acme-utils' => null], $manifest->requiresPlugin);
    }

    public function testDropsSelfPluginDependency(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'requires_plugin' => ['self-plugin']]",
        );

        self::assertSame([], PluginManifest::fromFile($file, 'self-plugin')->requiresPlugin);
    }

    public function testMissingPhpExtensionIsAnIncompatibility(): void
    {
        $file = $this->writeManifest(
            "['register' => static fn () => null, 'requires_ext' => ['vl_missing_ext']]",
        );

        $manifest = PluginManifest::fromFile($file, 'needs-ext');

        self::assertStringContainsString(
            'Requires PHP extension(s): vl_missing_ext',
            (string) $manifest->incompatibilityReason('0.77.3', '8.3.0'),
        );
    }

    public function testLoadedPhpExtensionSatisfiesTheRequirement(): void
    {
        $extension = strtolower(get_loaded_extensions()[0] ?? 'core');
        $file      = $this->writeManifest(
            "['register' => static fn () => null, 'requires_ext' => ['" . $extension . "']]",
        );

        $manifest = PluginManifest::fromFile($file, 'has-ext');

        self::assertNull($manifest->incompatibilityReason('0.77.3', '8.3.0'));
    }

    private function writeManifest(string $body): string
    {
        $file = $this->dir . '/plugin.php';
        file_put_contents($file, "<?php\n\nreturn " . $body . ";\n");

        return $file;
    }
}
