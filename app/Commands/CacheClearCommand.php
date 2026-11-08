<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Infrastructure\Cache\PageCache;

final class CacheClearCommand extends Command
{
    public function __construct(
        private readonly ?PageCache $pageCache = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('cache:clear')
            ->setDescription('Clear application caches')
            ->addArgument('type', InputArgument::OPTIONAL, 'Cache type: pages, data, views, container, or all', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be cleared without actually deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type   = $input->getArgument('type');
        $dryRun = (bool) $input->getOption('dry-run');

        $storageBase = base_path('storage/cache');

        $targets = match ($type) {
            'pages'     => ['pages'],
            'data'      => ['data'],
            'views'     => ['views'],
            'container' => ['container'],
            default     => ['pages', 'data', 'views', 'container'],
        };

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN — no files will be deleted</comment>');
        }

        $totalCleared = 0;

        foreach ($targets as $target) {
            $dir = $storageBase . '/' . $target;

            if ($dryRun) {
                $count = $this->countFiles($dir);
                $totalCleared += $count;
                $output->writeln("  <comment>{$target}:</comment> would clear {$count} files");
                continue;
            }

            if (!is_dir($dir)) {
                continue;
            }

            $count = $this->clearDirectory($dir);
            $totalCleared += $count;
            $output->writeln("  <comment>{$target}:</comment> cleared {$count} files");

            if ($target === 'pages' && $this->pageCache !== null) {
                $this->pageCache->flush();
                $output->writeln('  <comment>pagecache:</comment> flushed PageCache object');
            }
        }

        $output->writeln($dryRun
            ? '<comment>Dry run complete. Run without --dry-run to actually clear.</comment>'
            : "<info>Done. Cleared {$totalCleared} cache files.</info>");

        return Command::SUCCESS;
    }

    private function clearDirectory(string $dir): int
    {
        $count    = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                @unlink($file->getPathname());
                $count++;
            }
        }

        return $count;
    }

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count    = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                $count++;
            }
        }

        return $count;
    }
}
