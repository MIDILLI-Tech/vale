<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\Application\Update\BackupService;
use ValeApp\Application\Update\UpdateService;

/**
 * Base class for restore commands.
 */
abstract class AbstractRestoreCommand extends Command
{
    protected string $restoreType = 'all';

    public function __construct(
        protected readonly UpdateService $updateService,
        protected readonly BackupService $backupService,
        protected readonly string $rootDir
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('backup', InputArgument::OPTIONAL, 'Backup name or directory path (if omitted, the most recent backup is used)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be restored without actually doing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $dryRun    = (bool) $input->getOption('dry-run');
        $backupArg = $input->getArgument('backup');

        $backupDir = '';
        if ($backupArg) {
            $backupDir = $this->resolveBackupDir($backupArg);
            if (!is_dir($backupDir)) {
                $io->error("Backup directory not found: {$backupDir}");
                return Command::FAILURE;
            }
        }

        if ($backupDir === '') {
            $backups = $this->backupService->listBackups();
            if (empty($backups)) {
                $io->error('No backups found.');
                return Command::FAILURE;
            }
            $backupDir = $backups[0]['dir'];
            $io->note("Using most recent backup: " . basename($backupDir));
        }

        if (!$dryRun) {
            $io->warning("Restoring {$this->restoreType} from: " . basename($backupDir));
            if (!$io->confirm('Are you sure you want to proceed? This will overwrite current data.', false)) {
                $io->writeln('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        try {
            $this->updateService->rollback($backupDir, $this->restoreType, $dryRun);
            $io->success($dryRun ? 'Dry-run completed. No files were restored.' : 'Restore completed successfully.');
        } catch (\Throwable $e) {
            $io->error('Restore failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function resolveBackupDir(string $arg): string
    {
        if (is_dir($arg)) {
            return realpath($arg) ?: $arg;
        }

        $possible = $this->rootDir . '/storage/backups/' . $arg;
        if (is_dir($possible)) {
            return realpath($possible) ?: $possible;
        }

        return $arg;
    }
}
