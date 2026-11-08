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

/**
 * Delete a backup.
 */
final class BackupDeleteCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly string $rootDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('backup:delete')
            ->setDescription('Delete a backup')
            ->addArgument('backup', InputArgument::REQUIRED, 'Backup name or directory path')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without actually doing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $arg    = $input->getArgument('backup');
        $dryRun = (bool) $input->getOption('dry-run');

        $backupDir = $this->resolveBackupDir($arg);
        if (!is_dir($backupDir)) {
            $io->error("Backup directory not found: {$backupDir}");
            return Command::FAILURE;
        }

        $name = basename($backupDir);
        $io->warning("Deleting backup: {$name}");

        if (!$dryRun) {
            if (!$io->confirm('Are you sure? This cannot be undone.', false)) {
                $io->writeln('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        try {
            if ($dryRun) {
                $io->success("Dry-run: would delete backup \"{$name}\"");
            } else {
                $this->backupService->deleteBackup($backupDir);
                $io->success("Backup \"{$name}\" deleted successfully.");
            }
        } catch (\Throwable $e) {
            $io->error('Delete failed: ' . $e->getMessage());
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
