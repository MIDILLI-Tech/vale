<?php

declare(strict_types=1);

namespace ValeApp\App\Commands\Concerns;

use ValeApp\Infrastructure\Media\MediaScanService;

/**
 * Generates WebP/thumbnail variants for freshly imported media rows.
 * Requires the consuming command to expose: $dryRun (bool), $io (SymfonyStyle),
 * $scanner (?MediaScanService).
 */
trait BackfillsMediaVariants
{
    private function backfillMediaVariants(): void
    {
        if ($this->dryRun || $this->scanner === null) {
            return;
        }
        try {
            $count = $this->scanner->backfillVariants($this->io);
            if ($count > 0) {
                $this->io->text(sprintf('Generated thumbnails/WebP variants for %d media record(s).', $count));
            }
        } catch (\Throwable $e) {
            $this->io->warning('Media variant backfill failed: ' . $e->getMessage());
        }
    }
}
