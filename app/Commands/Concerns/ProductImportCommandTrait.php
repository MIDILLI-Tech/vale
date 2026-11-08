<?php

declare(strict_types=1);

namespace ValeApp\App\Commands\Concerns;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\Infrastructure\Cache\PageCache;

trait ProductImportCommandTrait
{
    use FlushesPageCache;
    /**
     * @param array{success: bool, imported: int, variants?: int, skipped: int, errors: string[], dry_run?: bool} $result
     */
    protected function reportProductImportResult(SymfonyStyle $io, array $result): int
    {
        if (!$result['success']) {
            $io->error('Import failed: ' . implode(', ', $result['errors']));

            return Command::FAILURE;
        }

        $variantPart = isset($result['variants']) && $result['variants'] > 0
            ? sprintf(', %d variants', $result['variants'])
            : '';

        if (!empty($result['dry_run'])) {
            $io->success(sprintf(
                'Dry run complete. Would import: %d products%s. Skipped: %d',
                $result['imported'],
                $variantPart,
                $result['skipped'],
            ));
            $io->comment('Run without --dry-run to apply.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Import completed successfully! Imported: %d products%s. Skipped: %d',
            $result['imported'],
            $variantPart,
            $result['skipped'],
        ));

        $this->flushPageCache($this->pageCache ?? null, false, $io);

        return Command::SUCCESS;
    }
}
