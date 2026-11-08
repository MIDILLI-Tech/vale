<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Update;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Update\PreUpdateChecker;

final class PreUpdateCheckerTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootDir = sys_get_temp_dir() . '/vale-preupdate-' . uniqid('', true);
        mkdir($this->rootDir . '/config', 0777, true);

        $refData = [
            'requirements' => [
                'min_php'             => '8.3.0',
                'required_extensions' => [
                    'json' => 'JSON data encoding/decoding',
                ],
                'recommended_extensions' => [],
            ],
        ];
        file_put_contents(
            $this->rootDir . '/config/reference-data.php',
            '<?php return ' . var_export($refData, true) . ';'
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->rootDir . '/config/reference-data.php')) {
            unlink($this->rootDir . '/config/reference-data.php');
        }
        if (is_dir($this->rootDir . '/config')) {
            rmdir($this->rootDir . '/config');
        }
        if (is_dir($this->rootDir)) {
            rmdir($this->rootDir);
        }
        parent::tearDown();
    }

    public function testNormalizeManifestExtensionsAcceptsArrayAndDeduplicates(): void
    {
        $checker = new PreUpdateChecker();

        [$extensions, $warnings] = $checker->normalizeManifestExtensions(
            ['pdo', 'mbstring', 'pdo', '  ', 'json']
        );

        self::assertSame(['pdo', 'mbstring', 'json'], $extensions);
        self::assertSame([], $warnings);
    }

    public function testNormalizeManifestExtensionsParsesCommaSeparatedString(): void
    {
        $checker = new PreUpdateChecker();

        [$extensions, $warnings] = $checker->normalizeManifestExtensions('pdo, mbstring;json');

        self::assertSame(['pdo', 'mbstring', 'json'], $extensions);
        self::assertSame([], $warnings);
    }

    public function testNormalizeManifestExtensionsReturnsEmptyForNullAndEmpty(): void
    {
        $checker = new PreUpdateChecker();

        self::assertSame([[], []], $checker->normalizeManifestExtensions(null));
        self::assertSame([[], []], $checker->normalizeManifestExtensions(''));
        self::assertSame([[], []], $checker->normalizeManifestExtensions([]));
    }

    public function testNormalizeManifestExtensionsWarnsOnNonArrayNonString(): void
    {
        $checker = new PreUpdateChecker();

        [$extensions, $warnings] = $checker->normalizeManifestExtensions(42);

        self::assertSame([], $extensions);
        self::assertNotEmpty($warnings);
    }

    public function testNormalizeManifestExtensionsFiltersInvalidNamesAndWarns(): void
    {
        $checker = new PreUpdateChecker();

        [$extensions, $warnings] = $checker->normalizeManifestExtensions(
            ['pdo', '../etc/passwd', 'a b', '']
        );

        self::assertSame(['pdo'], $extensions);
        self::assertCount(2, $warnings);
    }

    public function testGetMissingExtensions(): void
    {
        $checker = new PreUpdateChecker();

        $missing = $checker->getMissingExtensions(['json', 'zzzdefinitelymissing']);

        self::assertSame(['zzzdefinitelymissing'], $missing);
    }

    public function testRunAllThrowsWhenManifestRequiresMissingExtension(): void
    {
        $checker = new PreUpdateChecker();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('zzzdefinitelymissing');
        $this->expectExceptionMessage('update itself requires');

        $checker->runAll(
            ['min_extensions' => ['zzzdefinitelymissing']],
            $this->rootDir
        );
    }

    public function testRunAllSucceedsWhenAllExtensionsPresent(): void
    {
        $checker = new PreUpdateChecker();

        $warnings = $checker->runAll(
            ['min_extensions' => ['json', 'pdo']],
            $this->rootDir
        );

        self::assertIsArray($warnings);
    }
}
