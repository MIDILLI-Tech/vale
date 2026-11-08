<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Media\ImageProcessor;

final class ImageProcessorMemoryBudgetTest extends TestCase
{
    private string $origLimit;

    private string $tmpDir;

    protected function setUp(): void
    {
        $orig            = ini_get('memory_limit');
        $this->origLimit = $orig === false ? '-1' : $orig;

        $this->tmpDir = sys_get_temp_dir() . '/vl_budget_' . bin2hex(random_bytes(4));
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->origLimit);

        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->tmpDir);
    }

    public function testBudgetRaisesAndRestoresLimitForHeavyDecodes(): void
    {
        $processor = new ImageProcessor();
        $method    = new \ReflectionMethod($processor, 'beginDecodeBudget');

        // The baseline limit must be settable: PHP refuses to LOWER the limit
        // below current usage, so compute a baseline that comfortably sits
        // above it instead of assuming a fixed value.
        $baseline = (string) (memory_get_usage(true) * 2 + 32 * 1024 * 1024);
        self::assertNotFalse(ini_set('memory_limit', $baseline));
        self::assertSame($baseline, (string) ini_get('memory_limit'));

        // ~70 MP decode: usage + 70M×5 bytes + 64 MiB headroom blows past the
        // baseline (~usage×2 + 32 MiB).
        /** @var \Closure $release */
        $release = $method->invoke($processor, 8485, 8485);
        $raised  = (string) ini_get('memory_limit');

        $this->assertNotSame($baseline, $raised);
        $this->assertTrue(
            $this->parseIni($raised) > $this->parseIni($baseline),
            "Raised limit '{$raised}' must exceed baseline '{$baseline}'",
        );

        $release();

        self::assertSame($baseline, (string) ini_get('memory_limit'));
    }

    public function testBudgetIsNoOpWhenLimitAlreadyFits(): void
    {
        $processor = new ImageProcessor();
        $method    = new \ReflectionMethod($processor, 'beginDecodeBudget');

        ini_set('memory_limit', '512M');

        /** @var \Closure $release */
        $release = $method->invoke($processor, 100, 100);
        $release();

        self::assertSame('512M', (string) ini_get('memory_limit'));
    }

    public function testBudgetIsNoOpWhenLimitIsUnlimited(): void
    {
        $processor = new ImageProcessor();
        $method    = new \ReflectionMethod($processor, 'beginDecodeBudget');

        ini_set('memory_limit', '-1');

        /** @var \Closure $release */
        $release = $method->invoke($processor, 14300, 14000);
        $release();

        self::assertSame('-1', (string) ini_get('memory_limit'));
    }

    public function testShorthandParsing(): void
    {
        $processor = new ImageProcessor();
        $method    = new \ReflectionMethod($processor, 'phpIniBytes');

        self::assertSame(512 * 1024 * 1024, $method->invoke(null, '512M'));
        self::assertSame(1024 ** 3, $method->invoke(null, '1G'));
        self::assertSame(131072, $method->invoke(null, '128K'));
        self::assertSame(1024, $method->invoke(null, '1024'));
        self::assertSame(PHP_INT_MAX, $method->invoke(null, '-1'));
    }

    #[RequiresPhpExtension('gd')]
    public function testSanitizeSmallPngKeepsLimitUnchanged(): void
    {
        ini_set('memory_limit', '512M');

        $source   = $this->tmpDir . '/small.png';
        $resource = imagecreatetruecolor(32, 32);
        self::assertNotFalse($resource);
        self::assertTrue(imagepng($resource, $source));

        $processor = new ImageProcessor();
        $processor->sanitizeImage($source, $source);

        self::assertFileExists($source);
        [$width] = getimagesize($source) ?: [0];
        self::assertSame(32, $width);
        self::assertSame('512M', (string) ini_get('memory_limit'));
    }

    /**
     * Mirror of php.ini shorthand parsing ("64M" → bytes) for assertions.
     */
    private function parseIni(string $value): int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(g|m|k|b)?$/i', $value, $m) === 1) {
            $multiplier = match (strtolower($m[2] ?? 'b')) {
                'g'     => 1024 ** 3,
                'm'     => 1024 ** 2,
                'k'     => 1024,
                default => 1,
            };

            return (int) round((float) $m[1] * $multiplier);
        }

        return (int) $value;
    }
}
