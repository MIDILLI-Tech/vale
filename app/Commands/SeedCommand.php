<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run database seeders.
 *
 * Usage:  php vale db:seed
 */
final class SeedCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('db:seed')
            ->setDescription('Seed the database with default data')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview seeders without running them')
            ->addOption('demo', null, InputOption::VALUE_NONE, 'Seed sample/demo data (orders, products, users, posts, coupons, etc.)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force running seeders in production environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $isProduction = env('APP_ENV') === 'production';
        $isForce      = (bool) $input->getOption('force');

        if ($isProduction && !$isForce) {
            $io->error('Database seeding is disabled in production environment (APP_ENV=production). Use --force to execute.');
            return Command::FAILURE;
        }

        if ($input->getOption('dry-run')) {
            $io->note('Dry run — no seeders would be executed.');
            return Command::SUCCESS;
        }

        $phinxBin = base_path('vendor/bin/phinx');
        $config   = base_path('phinx.php');

        $isDemo       = (bool) $input->getOption('demo');
        $seederOption = $isDemo ? ' -s DemoSeeder' : ' -s InitialSeeder';

        $cmd = "\"$phinxBin\" seed:run -c \"$config\" -e default{$seederOption}";

        $output->writeln('<info>Running seeders...</info>');
        passthru($cmd, $exitCode);

        if ($exitCode === 0) {
            // Invalidate cache after seeding so fresh data is visible immediately
            $cacheDir = base_path('storage/cache');
            if (is_dir($cacheDir)) {
                try {
                    $files = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $file) {
                        if ($file->isFile() && !str_contains($file->getPathname(), 'container')) {
                            @unlink($file->getPathname());
                        }
                    }
                } catch (\Throwable) {
                }
            }
            return Command::SUCCESS;
        }

        $io->error('Seeding failed (exit code ' . $exitCode . '). Check the output above for details.');
        return Command::FAILURE;
    }
}
