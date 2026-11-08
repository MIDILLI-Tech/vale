<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reset database contents or perform full factory reset.
 *
 * Usage:
 *   php vale db:reset                   (Interactive prompt asking what components to reset if no options are passed)
 *   php vale db:reset --contents        (Clears content/orders/posts/pages/products while preserving users and settings)
 *   php vale db:reset --settings        (Clears site settings)
 *   php vale db:reset --users           (Clears user accounts & user roles)
 *   php vale db:reset --full            (Full factory reset: drops all tables and re-migrates database schema from scratch)
 *   php vale db:reset --contents --demo (Clears contents and seeds fresh demo data)
 */
final class DbResetCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('db:reset')
            ->setDescription('Reset database components (use --contents, --settings, --users, or --full)')
            ->addOption('contents', null, InputOption::VALUE_NONE, 'Clear content and e-commerce data (posts, pages, products, orders, coupons, forms, subscribers)')
            ->addOption('settings', null, InputOption::VALUE_NONE, 'Clear site settings')
            ->addOption('users', null, InputOption::VALUE_NONE, 'Clear user accounts and user-role assignments')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Full factory wipe: drop ALL tables, re-run migrations, and reset users/settings')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview reset operation without running database truncations')
            ->addOption('confirm', 'y', InputOption::VALUE_NONE, 'Confirm database reset without interactive prompt')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force reset in production environment')
            ->addOption('seed', null, InputOption::VALUE_NONE, 'Run initial seeder after reset')
            ->addOption('demo', null, InputOption::VALUE_NONE, 'Run demo seeder after reset');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lockFile = base_path('storage/installed.lock');
        $envPath  = base_path('.env');

        if (!file_exists($lockFile)) {
            $io->error([
                'Database reset can only be executed on an installed application.',
                'No storage/installed.lock file found. The application has not been installed yet.',
                'Please run the web installer (install.php) or ensure the installation is complete before using this command.',
            ]);
            return Command::FAILURE;
        }

        if (!file_exists($envPath) || !is_readable($envPath) || trim(file_get_contents($envPath)) === '') {
            $params      = $this->connection->getParams();
            $driver      = (string) ($params['driver'] ?? '');
            $driverLabel = match (true) {
                str_contains($driver, 'mysql')  => 'MySQL/MariaDB',
                str_contains($driver, 'pgsql')  => 'PostgreSQL',
                str_contains($driver, 'sqlite') => 'SQLite',
                default                         => $driver !== '' ? $driver : 'unknown driver',
            };
            $target = $driverLabel;
            if (isset($params['dbname'])) {
                $target .= " database '{$params['dbname']}'";
            }
            if (isset($params['host'])) {
                $target .= " on host '{$params['host']}'";
            }
            if (isset($params['path'])) {
                $target .= " ({$params['path']})";
            }
            $io->warning([
                '.env file is missing or empty.',
                "Falling back to environment variables — the reset will run against {$target}.",
                'If that is not the intended database, restore the .env file before proceeding.',
            ]);
        }

        $isProduction = env('APP_ENV') === 'production';
        $isForce      = (bool) $input->getOption('force');

        if ($isProduction && !$isForce) {
            $io->error('Database reset is disabled in production environment (APP_ENV=production). Use --force to execute.');
            return Command::FAILURE;
        }

        $isFull        = (bool) $input->getOption('full');
        $resetContents = $isFull || (bool) $input->getOption('contents');
        $resetSettings = $isFull || (bool) $input->getOption('settings');
        $resetUsers    = $isFull || (bool) $input->getOption('users');
        $isDemo        = (bool) $input->getOption('demo');

        // If --demo is passed without explicit target flags, default $resetContents = true
        if ($isDemo && !$isFull && !$resetContents && !$resetSettings && !$resetUsers) {
            $resetContents = true;
        }

        $confirmed        = (bool) $input->getOption('confirm');
        $isNonInteractive = !$input->isInteractive() || ($input->hasOption('no-interaction') && $input->getOption('no-interaction'));

        // If no target options (--contents, --settings, --users, --full, --demo) were provided:
        if (!$isFull && !$resetContents && !$resetSettings && !$resetUsers) {
            if ($isNonInteractive) {
                // Default to resetting contents in non-interactive mode if no specific flags given
                $resetContents = true;
            } else {
                $io->section('Database Reset Selector');
                $choice = $io->choice(
                    'What components would you like to reset?',
                    [
                        'contents' => 'Content & E-Commerce data (Posts, Pages, Products, Orders, Coupons, Forms, Subscribers)',
                        'settings' => 'Site Settings (Resets configuration to default)',
                        'users'    => 'User Accounts & Roles',
                        'all'      => 'Everything (Contents + Settings + Users)',
                        'full'     => 'Full Factory Wipe (Drop all tables & re-run migrations)',
                    ],
                    'contents'
                );

                switch ($choice) {
                    case 'contents':
                        $resetContents = true;
                        break;
                    case 'settings':
                        $resetSettings = true;
                        break;
                    case 'users':
                        $resetUsers = true;
                        break;
                    case 'all':
                        $resetContents = true;
                        $resetSettings = true;
                        $resetUsers    = true;
                        break;
                    case 'full':
                        $isFull        = true;
                        $resetContents = true;
                        $resetSettings = true;
                        $resetUsers    = true;
                        break;
                }
            }
        }

        // Build list of cleared vs preserved for preview
        $clearedItems   = [];
        $preservedItems = [];

        if ($resetContents) {
            $clearedItems[] = 'Content & Store Data (Orders, Products, Posts, Pages, Comments, Coupons, Forms, Subscribers)';
        } else {
            $preservedItems[] = 'Content & Store Data';
        }

        if ($resetSettings) {
            $clearedItems[] = 'Site Settings';
        } else {
            $preservedItems[] = 'Site Settings';
        }

        if ($resetUsers) {
            $clearedItems[] = 'User Accounts & Roles';
        } else {
            $preservedItems[] = 'User Accounts & Roles';
        }

        $preservedItems[] = 'Content Type Definitions';

        // Display summary of what will be cleared vs preserved
        $io->section('Database Reset Preview');
        if ($isFull) {
            $io->warning('MODE: Full Factory Reset (--full)');
            $io->text([
                ' <fg=red>• WHAT WILL BE WIPED:</fg=red> All database tables, users, roles, permissions, settings, content, products, orders, forms, subscribers.',
                ' <fg=green>• WHAT WILL BE RE-CREATED:</fg=green> Clean database schema via Phinx migrations.',
            ]);
        } else {
            $io->info('MODE: Selective Data Reset');
            $io->text([
                ' <fg=red>• WHAT WILL BE CLEARED:</fg=red> ' . implode(', ', $clearedItems) . '.',
                ' <fg=green>• WHAT WILL BE PRESERVED:</fg=green> ' . implode(', ', $preservedItems) . '.',
            ]);
        }

        if ($input->getOption('dry-run')) {
            $io->note('Dry run — no database changes would be executed.');
            return Command::SUCCESS;
        }

        if (!$confirmed && !$isNonInteractive) {
            $answer = $io->confirm(
                $isFull
                    ? 'WARNING: This will permanently drop ALL tables and data. Proceed with FULL factory reset?'
                    : 'Are you sure you want to proceed with database reset?',
                false
            );
            if (!$answer) {
                $io->note('Database reset cancelled.');
                return Command::SUCCESS;
            }
        }

        $phinxBin = base_path('vendor/bin/phinx');
        $config   = base_path('phinx.php');

        if ($isFull) {
            $io->writeln('<info>Wiping all database tables...</info>');
            try {
                $this->dropAllTables();
                $io->success('All database tables dropped successfully.');
            } catch (\Throwable $e) {
                $io->error('Failed to drop database tables: ' . $e->getMessage());
                return Command::FAILURE;
            }

            // Run Phinx migrations
            $io->writeln('<info>Running database migrations...</info>');
            $migrateCmd = "\"$phinxBin\" migrate -c \"$config\" -e default";
            passthru($migrateCmd, $migrateExitCode);

            if ($migrateExitCode !== 0) {
                $io->error('Migrations failed during database reset (exit code ' . $migrateExitCode . ').');
                $io->text('The Phinx output above should explain why. Common causes:');
                $io->text('  - Missing or corrupt migration files in database/migrations/');
                $io->text('  - Database driver mismatch (check DB_DRIVER in .env)');
                return Command::FAILURE;
            }
        } else {
            $io->writeln('<info>Clearing requested data tables...</info>');
            try {
                $this->truncateTables($resetContents, $resetSettings, $resetUsers);
                $io->success('Data tables cleared successfully.');
            } catch (\Throwable $e) {
                $io->error('Failed to clear data tables: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $shouldSeed = (bool) $input->getOption('seed');
        $shouldDemo = (bool) $input->getOption('demo');

        if ($isFull) {
            $io->writeln('<info>Running initial seeders...</info>');
            $seedCmd = "\"$phinxBin\" seed:run -c \"$config\" -e default -s InitialSeeder";
            passthru($seedCmd, $seedExitCode);
            if ($seedExitCode !== 0) {
                $io->warning('Initial seeder encountered an issue during execution.');
            }
        }

        if ($shouldDemo) {
            $io->writeln('<info>Running demo seeders...</info>');
            $seedCmd = "\"$phinxBin\" seed:run -c \"$config\" -e default -s DemoSeeder";
            passthru($seedCmd, $seedExitCode);
            if ($seedExitCode !== 0) {
                $io->warning('Demo seeder encountered an issue during execution.');
            }
        } elseif (!$isFull && ($shouldSeed || $resetSettings || $resetUsers)) {
            $io->writeln('<info>Running initial seeders...</info>');
            $seedCmd = "\"$phinxBin\" seed:run -c \"$config\" -e default -s InitialSeeder";
            passthru($seedCmd, $seedExitCode);
            if ($seedExitCode !== 0) {
                $io->warning('Initial seeder encountered an issue during execution.');
            }
        }

        if ($isFull) {
            $lockFile = base_path('storage/installed.lock');
            if (!is_dir(dirname($lockFile))) {
                mkdir(dirname($lockFile), 0755, true);
            }
            file_put_contents($lockFile, date('Y-m-d H:i:s'));
            $io->info('Created storage/installed.lock to mark application as installed.');
        }

        $io->success($isFull ? 'Full database factory reset completed!' : 'Database data reset completed!');

        return Command::SUCCESS;
    }

    private function truncateTables(bool $resetContents, bool $resetSettings, bool $resetUsers): void
    {
        $prefix        = env('DB_PREFIX', 'vl_');
        $platformClass = strtolower($this->connection->getDatabasePlatform()::class);

        // Turn off foreign key constraints
        $this->toggleForeignKeyConstraints($platformClass, false);

        $tablesToTruncate = [];

        if ($resetContents) {
            $tablesToTruncate = array_merge($tablesToTruncate, [
                'abandoned_carts',
                'api_tokens',
                'automation_enrollments',
                'automation_sequences',
                'automation_steps',
                'categories',
                'comments',
                'country_tax_rates',
                'coupons',
                'form_submissions',
                'forms',
                'media',
                'membership_plans',
                'menu_items',
                'menus',
                'newsletter_campaign_recipients',
                'newsletter_campaigns',
                'newsletter_subscriber_tags',
                'newsletter_subscribers',
                'order_downloads',
                'order_items',
                'order_logs',
                'orders',
                'pageviews',
                'password_resets',
                'post_categories',
                'post_meta',
                'post_tags',
                'posts',
                'product_categories',
                'product_download_files',
                'product_images',
                'product_to_categories',
                'product_variation_download_files',
                'product_variations',
                'product_votes',
                'products',
                'redirects',
                'revisions',
                'seo_meta',
                'tags',
                'user_subscriptions',
                'webhooks',
            ]);
        }

        if ($resetSettings) {
            $tablesToTruncate[] = 'settings';
        }

        if ($resetUsers) {
            $tablesToTruncate[] = 'user_roles';
            $tablesToTruncate[] = 'users';
        }

        $schemaManager  = $this->connection->createSchemaManager();
        $existingTables = array_map('strtolower', $schemaManager->listTableNames());

        foreach ($tablesToTruncate as $tableName) {
            $fullTable = $prefix . $tableName;
            if (in_array(strtolower($fullTable), $existingTables, true)) {
                $quotedTable = $this->connection->quoteIdentifier($fullTable);
                $this->connection->executeStatement("DELETE FROM {$quotedTable}");
            }
        }

        // Re-enable foreign key constraints
        $this->toggleForeignKeyConstraints($platformClass, true);
    }

    private function dropAllTables(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $platformClass = strtolower($this->connection->getDatabasePlatform()::class);

        $this->toggleForeignKeyConstraints($platformClass, false);

        $tables = $schemaManager->listTableNames();
        foreach ($tables as $table) {
            if (str_contains($platformClass, 'postgresql') || str_contains($platformClass, 'pgsql')) {
                $this->connection->executeStatement("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
            } else {
                $this->connection->executeStatement("DROP TABLE IF EXISTS `{$table}`");
            }
        }

        $this->toggleForeignKeyConstraints($platformClass, true);
    }

    private function toggleForeignKeyConstraints(string $platformClass, bool $enable): void
    {
        if (str_contains($platformClass, 'sqlite')) {
            $this->connection->executeStatement($enable ? 'PRAGMA foreign_keys = ON' : 'PRAGMA foreign_keys = OFF');
        } elseif (str_contains($platformClass, 'mysql') || str_contains($platformClass, 'mariadb')) {
            $this->connection->executeStatement($enable ? 'SET FOREIGN_KEY_CHECKS = 1' : 'SET FOREIGN_KEY_CHECKS = 0');
        } elseif (str_contains($platformClass, 'postgresql') || str_contains($platformClass, 'pgsql')) {
            $this->connection->executeStatement($enable ? 'SET CONSTRAINTS ALL IMMEDIATE' : 'SET CONSTRAINTS ALL DEFERRED');
        }
    }
}
