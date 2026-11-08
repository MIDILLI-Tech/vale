<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Commands\Concerns;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\App\Commands\Concerns\FlushesPageCache;
use ValeApp\Infrastructure\Cache\PageCache;

final class FlushesPageCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vale-pagecache-test-' . uniqid();
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function helper(): object
    {
        return new class () {
            use FlushesPageCache;

            public function run(?PageCache $cache, bool $dryRun, SymfonyStyle $io): void
            {
                $this->flushPageCache($cache, $dryRun, $io);
            }
        };
    }

    private function io(BufferedOutput $output): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $output);
    }

    public function testFlushRemovesCachedPages(): void
    {
        file_put_contents($this->dir . '/abc_guest.html', '<html></html>');
        $output = new BufferedOutput();

        $this->helper()->run(new PageCache($this->dir), false, $this->io($output));

        self::assertSame([], glob($this->dir . '/*.html') ?: []);
        self::assertStringContainsString('Page cache flushed (1 file(s) removed)', $output->fetch());
    }

    public function testDryRunSkipsFlush(): void
    {
        file_put_contents($this->dir . '/abc_guest.html', '<html></html>');
        $output = new BufferedOutput();

        $this->helper()->run(new PageCache($this->dir), true, $this->io($output));

        self::assertNotSame([], glob($this->dir . '/*.html') ?: []);
        self::assertSame('', $output->fetch());
    }

    public function testNullCacheIsNoOp(): void
    {
        $output = new BufferedOutput();

        $this->helper()->run(null, false, $this->io($output));

        self::assertSame('', $output->fetch());
    }
}
