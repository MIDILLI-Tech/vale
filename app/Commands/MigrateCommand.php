<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run Phinx database migrations.
 *
 * Usage:
 *   php vale migrate
 *   php vale migrate --rollback
 *   php vale migrate --status
 */
final class MigrateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('migrate')
            ->setDescription('Run database migrations')
            ->addOption('rollback', 'r', InputOption::VALUE_NONE, 'Rollback the last migration batch')
            ->addOption('status', 's', InputOption::VALUE_NONE, 'Show migration status')
            ->addOption('fake', 'f', InputOption::VALUE_NONE, 'Record migrations as applied without executing them (use when the schema already exists, e.g. after importing a database)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview migrations without running them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $phinxBin = base_path('vendor/bin/phinx');
        $config   = base_path('phinx.php');

        if ($input->getOption('dry-run')) {
            // Real preview: list pending (down) migrations without executing
            // anything — no schema changes and no seeder run.
            $cmd = "\"$phinxBin\" status -c \"$config\" -e default";
            $output->writeln('<info>Dry run — migration status preview, nothing will be executed:</info>');
            $output->writeln('<info>Running: ' . $cmd . '</info>');

            passthru($cmd);

            // Status is read-only by definition: report success once the
            // preview itself ran (Phinx may exit non-zero for informational
            // states such as missing local migration files).
            return Command::SUCCESS;
        }

        if ($input->getOption('status')) {
            $cmd = "\"$phinxBin\" status -c \"$config\" -e default";
        } elseif ($input->getOption('rollback')) {
            $cmd = "\"$phinxBin\" rollback -c \"$config\" -e default";
        } elseif ($input->getOption('fake')) {
            $cmd = "\"$phinxBin\" migrate -c \"$config\" -e default --fake";
        } else {
            $cmd = "\"$phinxBin\" migrate -c \"$config\" -e default";
        }

        $output->writeln('<info>Running: ' . $cmd . '</info>');

        passthru($cmd, $exitCode);

        // A normal migration on a fresh database also bootstraps the safe,
        // non-demo defaults. Rollbacks, status checks, and fake migrations
        // must remain schema-only operations.
        if ($exitCode === 0 && !$input->getOption('status') && !$input->getOption('rollback') && !$input->getOption('fake')) {
            $seedCmd = "\"$phinxBin\" seed:run -c \"$config\" -e default -s InitialSeeder";
            $output->writeln('<info>Running initial seeders...</info>');
            passthru($seedCmd, $seedExitCode);
            if ($seedExitCode !== 0) {
                return Command::FAILURE;
            }
        }

        if ($exitCode !== 0) {
            $io->error('Migration failed (exit code ' . $exitCode . '). Check the output above for details.');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
