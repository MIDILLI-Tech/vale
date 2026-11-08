<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Application\Plugin\PluginMigrator;

/**
 * Apply or revert plugin migrations.
 *
 * Usage:  php vale plugin:migrate [slug] [--dry-run]
 *         php vale plugin:migrate --rollback [--all] <slug> [--dry-run]
 */
final class PluginMigrateCommand extends Command
{
    public function __construct(
        private readonly PluginMigrator $migrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:migrate')
            ->setDescription('Run or revert plugin migrations (content/plugins/<slug>/migrations/)')
            ->addArgument('slug', InputArgument::OPTIONAL, 'Only this plugin (default: every enabled plugin)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without touching the database')
            ->addOption('rollback', null, InputOption::VALUE_NONE, 'Revert applied migrations instead of applying them (newest first)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'With --rollback: revert every applied migration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug     = trim((string) $input->getArgument('slug'));
        $dryRun   = (bool) $input->getOption('dry-run');
        $rollback = (bool) $input->getOption('rollback');
        $all      = (bool) $input->getOption('all');

        if ($all && !$rollback) {
            $output->writeln('<error>--all can only be used together with --rollback.</error>');

            return Command::INVALID;
        }

        if ($rollback) {
            return $this->rollback($output, $slug, $dryRun, $all);
        }

        try {
            $reports = $slug !== ''
                ? [$this->migrator->migrate($slug, $dryRun) + ['error' => null]]
                : $this->migrator->migrateEnabled($dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($reports === []) {
            $output->writeln('<comment>No enabled plugins to migrate.</comment>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders($dryRun ? ['Plugin', 'Would Apply', 'Files'] : ['Plugin', 'Applied', 'Pending', 'Error']);

        $failed = false;
        foreach ($reports as $report) {
            if ($report['error'] !== null) {
                $failed = true;
            }

            $table->addRow($dryRun
                ? [$report['plugin'], (string) count($report['pending']), implode(', ', $report['pending']) ?: '—']
                : [
                    $report['plugin'],
                    implode(', ', $report['applied']) ?: '—',
                    implode(', ', $report['pending']) ?: '—',
                    $report['error'] ?? '',
                ]);
        }

        $table->render();

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function rollback(OutputInterface $output, string $slug, bool $dryRun, bool $all): int
    {
        if ($slug === '') {
            $output->writeln('<error>Rolling back needs a plugin slug: php vale plugin:migrate --rollback <slug></error>');

            return Command::INVALID;
        }

        try {
            $report = $this->migrator->rollback($slug, $dryRun, $all);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($report['reverted'] === []) {
            $output->writeln('<comment>No applied migrations to roll back.</comment>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders($dryRun ? ['Plugin', 'Would Revert', 'Remaining'] : ['Plugin', 'Reverted', 'Remaining']);
        $table->addRow([
            $report['plugin'],
            implode(', ', $report['reverted']),
            implode(', ', $report['remaining']) ?: '—',
        ]);
        $table->render();

        return Command::SUCCESS;
    }
}
