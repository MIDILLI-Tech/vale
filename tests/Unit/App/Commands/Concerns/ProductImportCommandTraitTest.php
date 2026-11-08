<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Commands\Concerns;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\App\Commands\Concerns\ProductImportCommandTrait;
use ValeApp\Infrastructure\Cache\PageCache;

final class ProductImportCommandTraitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vale-pagecache-trait-' . uniqid();
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function host(?PageCache $cache): object
    {
        return new class ($cache) extends Command {
            use ProductImportCommandTrait;

            public function __construct(
                private readonly ?PageCache $pageCache = null,
            ) {
                parent::__construct();
            }

            /** @param array{success: bool, imported: int, variants?: int, skipped: int, errors: string[], dry_run?: bool} $result */
            public function report(SymfonyStyle $io, array $result): int
            {
                return $this->reportProductImportResult($io, $result);
            }
        };
    }

    public function testSuccessfulImportFlushesPageCache(): void
    {
        file_put_contents($this->dir . '/abc_guest.html', '<html></html>');
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        $code = $this->host(new PageCache($this->dir))->report($io, [
            'success'  => true,
            'imported' => 3,
            'skipped'  => 1,
            'errors'   => [],
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertSame([], glob($this->dir . '/*.html') ?: []);
    }

    public function testDryRunImportKeepsPageCache(): void
    {
        file_put_contents($this->dir . '/abc_guest.html', '<html></html>');
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        $code = $this->host(new PageCache($this->dir))->report($io, [
            'success'  => true,
            'imported' => 3,
            'skipped'  => 1,
            'errors'   => [],
            'dry_run'  => true,
        ]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertNotSame([], glob($this->dir . '/*.html') ?: []);
    }

    public function testFailedImportKeepsPageCache(): void
    {
        file_put_contents($this->dir . '/abc_guest.html', '<html></html>');
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        $code = $this->host(new PageCache($this->dir))->report($io, [
            'success'  => false,
            'imported' => 0,
            'skipped'  => 0,
            'errors'   => ['boom'],
        ]);

        self::assertSame(Command::FAILURE, $code);
        self::assertNotSame([], glob($this->dir . '/*.html') ?: []);
    }

    public function testMissingCacheIsNoOp(): void
    {
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        $code = $this->host(null)->report($io, [
            'success'  => true,
            'imported' => 1,
            'skipped'  => 0,
            'errors'   => [],
        ]);

        self::assertSame(Command::SUCCESS, $code);
    }
}
