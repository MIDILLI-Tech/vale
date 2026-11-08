<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

/**
 * Restore only the database.
 */
final class BackupRestoreDbCommand extends AbstractRestoreCommand
{
    protected string $restoreType = 'database';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('backup:restore-db')
            ->setDescription('Restore only the database from a backup');
    }
}
