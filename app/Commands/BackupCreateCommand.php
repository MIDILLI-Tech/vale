<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\Application\Update\BackupService;
use ValeApp\Application\Update\UpdateService;

/**
 * Create a new backup.
 */
final class BackupCreateCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly UpdateService $updateService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('backup:create')
            ->setDescription('Create a new backup (files + database)')
            ->addOption('quick', null, InputOption::VALUE_NONE, 'Skip external components (vendor, node_modules)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be backed up without actually doing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $quick   = (bool) $input->getOption('quick');
        $dryRun  = (bool) $input->getOption('dry-run');
        $version = $this->updateService->getCurrentVersion();

        $io->title(($dryRun ? 'Dry-run: ' : '') . "Creating " . ($quick ? 'quick ' : '') . "backup (v{$version})");

        try {
            $backupDir = $this->backupService->createBackup($version, $quick, $dryRun);

            if ($dryRun) {
                $io->success('Dry-run completed. No backup was created.');
                return Command::SUCCESS;
            }

            $io->success("Backup created successfully:\n" . $backupDir);
        } catch (\Throwable $e) {
            $io->error('Backup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
