<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

/**
 * Restore only the files.
 */
final class BackupRestoreFilesCommand extends AbstractRestoreCommand
{
    protected string $restoreType = 'files';

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('backup:restore-files')
            ->setDescription('Restore only the files from a backup');
    }
}
