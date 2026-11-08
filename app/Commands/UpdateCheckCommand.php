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
 * Check (and optionally apply) Vale updates.
 *
 * Usage:
 *   php vale update:check                  — check for a newer release
 *   php vale update:check --apply          — check AND apply if available
 *   php vale update:check --force          — ignore the 24 h interval and always check
 *   php vale update:check --backup         — create a backup of the current installation
 *   php vale update:check --rollback       — roll back to the most recent backup
 *   php vale update:check --rollback=DIR   — roll back to a specific backup directory
 */
final class UpdateCheckCommand extends Command
{
    public function __construct(
        private readonly UpdateService $updater,
        private readonly ?BackupService $backupService = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('update:check')
            ->setDescription('Check for Vale updates (and optionally apply them)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Download and apply the update if one is available')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force a remote check even if the 24 h interval has not elapsed')
            ->addOption('backup', null, InputOption::VALUE_NONE, 'Create a full backup of the current installation')
            ->addOption('rollback', null, InputOption::VALUE_OPTIONAL, 'Roll back to the most recent backup (or specify a backup directory)', false);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $force    = (bool) $input->getOption('force');
        $apply    = (bool) $input->getOption('apply');
        $doBackup = (bool) $input->getOption('backup');
        $rollback = $input->getOption('rollback'); // false | null (flag set, no value) | string

        $current = $this->updater->getCurrentVersion();
        $io->section("Vale Update Check (installed: v{$current})");

        // ── Backup ─────────────────────────────────────────────────────
        if ($doBackup) {
            if ($this->backupService === null) {
                $io->error('BackupService is not configured. Cannot create backup.');
                return Command::FAILURE;
            }
            $io->writeln('Creating backup…');
            try {
                $backupDir = $this->backupService->createBackup($current);
                $io->success("Backup created: {$backupDir}");
            } catch (\Throwable $e) {
                $io->error('Backup failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        // ── Rollback ───────────────────────────────────────────────────
        if ($rollback !== false) {
            // --rollback with no value -> null; --rollback=DIR -> string
            $backupDir = is_string($rollback) ? $rollback : '';
            $io->writeln($backupDir !== '' ? "Rolling back to: {$backupDir}" : 'Rolling back to most recent backup…');
            try {
                $this->updater->rollback($backupDir);
                $io->success('Rollback complete.');
            } catch (\Throwable $e) {
                $io->error('Rollback failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        // ── Check ──────────────────────────────────────────────────────

        if (!$force && !$this->updater->isDueForAutoCheck()) {
            $last = $this->updater->getLastCheckedAt();
            $ago  = $last ? (int) round((time() - $last) / 60) : 0;
            $io->comment("Last check was {$ago} minute(s) ago. Use --force to check again.");
        } else {
            $io->writeln('Fetching update manifest…');
            $manifest = $this->updater->check();

            if ($manifest === null) {
                $io->error('Could not reach the update server. Check UPDATE_MANIFEST_URL in .env.');
                return Command::FAILURE;
            }

            $io->writeln("Manifest fetched. Latest release: v{$manifest['version']}");
        }

        // ── Report ─────────────────────────────────────────────────────

        if (!$this->updater->isUpdateAvailable()) {
            $io->success('You are running the latest version.');
            return Command::SUCCESS;
        }

        $available = $this->updater->getAvailableVersion();
        $manifest  = $this->updater->getCachedManifest();

        $io->note("Update available: v{$current} → v{$available}");

        if ($manifest && !empty($manifest['changelog'])) {
            $io->listing($manifest['changelog']);
        }

        // ── Apply ──────────────────────────────────────────────────────

        if (!$apply) {
            $io->comment('Run with --apply to install the update.');
            return Command::SUCCESS;
        }

        $downloadUrl    = $manifest['download_url'] ?? '';
        $expectedSha256 = $manifest['sha256']       ?? '';

        if ($downloadUrl === '') {
            $io->error('Manifest does not contain a download_url.');
            return Command::FAILURE;
        }

        $io->writeln("Downloading release ZIP…");

        try {
            $zipPath = $this->updater->download($downloadUrl, $expectedSha256);
        } catch (\Throwable $e) {
            $io->error('Download failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->writeln("Applying update…");

        try {
            $result = $this->updater->apply($zipPath, $manifest);
        } catch (\Throwable $e) {
            $io->error('Update failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            "Updated to v%s. %d files replaced, %d cache files cleared.",
            $result->newVersion,
            $result->copiedFiles,
            $result->clearedCacheFiles,
        ));

        if ($result->migrationOutput !== '') {
            $io->section('Migration output');
            $io->writeln($result->migrationOutput);
        }

        return Command::SUCCESS;
    }
}
