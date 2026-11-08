<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

/**
 * Restore everything (files + database).
 */
final class BackupRestoreAllCommand extends AbstractRestoreCommand
{
    protected string $restoreType = 'all';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('backup:restore-all')
            ->setDescription('Restore everything (files + database) from a backup');
    }
}
