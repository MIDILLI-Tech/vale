<?php

declare(strict_types=1);

namespace ValeApp\App\Commands\Concerns;

use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\Infrastructure\Cache\PageCache;

/**
 * Flushes the production page cache after bulk-write commands.
 *
 * Import commands bypass the normal save flow, so the `post.published` /
 * `post.updated` invalidation listeners never fire. Without an explicit
 * flush the guest-facing full-page HTML cache stays stale after an import.
 * Always skipped in dry-run mode and when no PageCache is wired.
 */
trait FlushesPageCache
{
    protected function flushPageCache(?PageCache $pageCache, bool $dryRun, SymfonyStyle $io): void
    {
        if ($dryRun || $pageCache === null) {
            return;
        }

        $flushed = $pageCache->flush();
        $io->text("Page cache flushed ({$flushed} file(s) removed).");
    }
}
