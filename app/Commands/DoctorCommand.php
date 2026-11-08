<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Infrastructure\Security\Encrypter;

final class DoctorCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ?RoutingValidator $routing = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('doctor')
            ->setDescription('Check system health — PHP, extensions, permissions, database, environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Vale Doctor');

        $installed     = file_exists(base_path('storage/installed.lock'));
        $dbDriver      = (string) env('DB_DRIVER', 'mysql');
        $isPgDriver    = in_array($dbDriver, ['pgsql', 'postgres', 'postgresql'], true);
        $dbHost        = (string) env('DB_HOST', '');
        $dbName        = (string) env('DB_NAME', '');
        $dbConfigured  = $dbDriver === 'sqlite'
            ? $dbName !== ''
            : $dbHost !== '' && $dbName !== '';

        if (!$installed && !$dbConfigured) {
            $io->warning('Vale is not installed yet (storage/installed.lock missing or database is not configured).');
            $io->writeln('Run the installer at /install.php, or configure database settings (Via App Environment, container env vars, or .env) and run `php vale migrate` before re-running vale doctor.');
            return Command::SUCCESS;
        }

        $passed   = 0;
        $warnings = 0;
        $failed   = 0;

        // Load Requirements from config
        $refPath = base_path('config/reference-data.php');
        if (!file_exists($refPath)) {
            $io->error('Central requirements configuration file (config/reference-data.php) is missing.');
            return Command::FAILURE;
        }

        $referenceData = require $refPath;
        $requirements  = $referenceData['requirements'] ?? null;
        if ($requirements === null) {
            $io->error('Requirements are not defined in config/reference-data.php.');
            return Command::FAILURE;
        }

        $minPhp     = $requirements['min_php']     ?? null;
        $minMysql   = $requirements['min_mysql']   ?? null;
        $minMariadb = $requirements['min_mariadb'] ?? null;
        $minPgsql   = $requirements['min_pgsql']   ?? null;

        if (!$minPhp || !$minMysql || !$minMariadb || !$minPgsql) {
            $missing = [];
            if (!$minPhp) {
                $missing[] = 'min_php';
            }
            if (!$minMysql) {
                $missing[] = 'min_mysql';
            }
            if (!$minMariadb) {
                $missing[] = 'min_mariadb';
            }
            if (!$minPgsql) {
                $missing[] = 'min_pgsql';
            }
            $io->error('Required version keys are missing from config/reference-data.php: ' . implode(', ', $missing));
            return Command::FAILURE;
        }

        // ── PHP Version ──
        $io->section('PHP');
        $ok = version_compare(PHP_VERSION, $minPhp, '>=');
        $io->writeln(($ok ? '✅  ' : '❌  ') . 'PHP ' . PHP_VERSION . ($ok ? '' : ' (< ' . $minPhp . ')'));
        $ok ? $passed++ : $failed++;

        // ── Environment ──
        $io->section('Environment');
        $envExists = file_exists(base_path('.env'));
        $io->writeln(($envExists ? '✅  ' : '❌  ') . '.env file');
        $envExists ? $passed++ : $failed++;

        $appKey = env('APP_KEY');
        if ($appKey === null || $appKey === '' || $appKey === 'YourAppKeyHere') {
            $io->writeln('❌  APP_KEY not set');
            $failed++;
        } elseif (!preg_match('/^[a-f0-9]{64}$/', $appKey) && !preg_match('/^[a-f0-9]{32}$/', $appKey)) {
            $io->writeln('❌  APP_KEY format invalid (expected 64 hex characters)');
            $failed++;
        } elseif (!extension_loaded('sodium')) {
            $io->writeln('⚠️  APP_KEY format OK, but cannot verify (sodium extension missing)');
            $warnings++;
        } else {
            try {
                $encrypter = new Encrypter($appKey);
                $test      = 'doctor-' . bin2hex(random_bytes(4));
                $encrypted = $encrypter->encrypt($test);
                $decrypted = $encrypter->decrypt($encrypted);
                if ($decrypted === $test) {
                    $io->writeln('✅  APP_KEY valid (encrypt/decrypt roundtrip OK)');
                    $passed++;
                } else {
                    $io->writeln('❌  APP_KEY roundtrip failed (decrypted value mismatch)');
                    $failed++;
                }
            } catch (\Exception $e) {
                $io->writeln('❌  APP_KEY validation error: ' . $e->getMessage());
                $failed++;
            }
        }

        $this->checkEncryptPiiConsistency($io, $passed, $warnings, $failed);

        $appUrl = env('APP_URL');
        $hasUrl = $appUrl !== null && $appUrl !== '';
        $io->writeln(($hasUrl ? '✅  ' : '❌  ') . 'APP_URL configured');
        $hasUrl ? $passed++ : $failed++;
        if (!$hasUrl) {
            $io->writeln('    APP_URL is mandatory — the application refuses to boot without it.');
            $io->writeln('    Add APP_URL to your .env file, e.g. APP_URL=https://example.com');
        }

        $installed = file_exists(base_path('storage/installed.lock'));
        $io->writeln(($installed ? '✅  ' : '❌  ') . 'Application installed (storage/installed.lock)');
        $installed ? $passed++ : $failed++;

        $updatePublicKey = (string) env('UPDATE_PUBLIC_KEY_ED25519', '');
        $updateKeyValid  = false;
        if ($updatePublicKey !== '') {
            $decoded        = base64_decode($updatePublicKey, true);
            $expectedLen    = defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES') ? SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES : 32;
            $updateKeyValid = $decoded !== false && strlen($decoded) === $expectedLen;
        }
        if ($updatePublicKey === '') {
            $io->writeln('⚠️  UPDATE_PUBLIC_KEY_ED25519 not set — signed auto-updates will be rejected');
            $io->writeln('    Copy the official release key from .env.example into your .env');
            $warnings++;
        } elseif (!$updateKeyValid) {
            $io->writeln('⚠️  UPDATE_PUBLIC_KEY_ED25519 format invalid (expected 32-byte base64 Ed25519 key)');
            $warnings++;
        } else {
            $io->writeln('✅  UPDATE_PUBLIC_KEY_ED25519 configured');
            $passed++;
        }

        $appEnv   = env('APP_ENV', 'production');
        $appDebug = (bool) env('APP_DEBUG', false);
        $io->writeln('✅  APP_ENV = ' . $appEnv . ($appDebug ? ', APP_DEBUG = true' : ', APP_DEBUG = false'));
        $passed++;
        if ($appEnv === 'production' && $appDebug) {
            $io->warning('APP_DEBUG is enabled in production. Disable it for security.');
        } elseif ($appEnv !== 'production') {
            $io->note('Running in ' . $appEnv . ' mode — not recommended for production.');
        }

        if ($hasUrl) {
            $isHttps = str_starts_with((string) $appUrl, 'https://');
            $io->writeln(($isHttps ? '✅  ' : '⚠️  ') . 'APP_URL uses HTTPS' . ($isHttps ? '' : ' (' . $appUrl . ')'));
            $isHttps ? $passed++ : $warnings++;
            if (!$isHttps && $appEnv === 'production') {
                $io->warning('APP_URL does not use HTTPS in production.');
            }
        }

        if (!function_exists('disk_free_space')) {
            $io->writeln('⚠️  Disk space: unable to check (disk_free_space disabled)');
            $warnings++;
        } else {
            $freeBytes = @disk_free_space(base_path());
            if ($freeBytes === false) {
                $io->writeln('⚠️  Disk space: unable to check');
                $warnings++;
            } else {
                $freeGb      = $freeBytes / 1073741824;
                $enoughSpace = $freeBytes > 104857600;
                $io->writeln(($enoughSpace ? '✅  ' : '❌  ') . 'Disk space: ' . number_format($freeGb, 2) . ' GB free');
                $enoughSpace ? $passed++ : $failed++;
                if ($enoughSpace && $freeBytes < 1073741824) {
                    $io->note('Less than 1 GB of free disk space. Backups and updates may fail.');
                }
            }
        }

        $assetsUseCdn = (bool) env('ASSETS_USE_CDN', false);
        $io->writeln(($assetsUseCdn ? '✅  ' : '✅  ') . 'ASSETS_USE_CDN = ' . ($assetsUseCdn ? 'true (CDN)' : 'false (local)'));
        $passed++;

        // ── Database ──
        $dbDriver   = env('DB_DRIVER', 'mysql');
        $isPgDriver = in_array($dbDriver, ['pgsql', 'postgres', 'postgresql'], true);
        $dbName     = env('DB_NAME', $dbDriver === 'sqlite' ? 'storage/database.sqlite' : 'Vale');
        $dbHost     = env('DB_HOST', '127.0.0.1');
        $dbPort     = env('DB_PORT', $isPgDriver ? 5432 : 3306);

        $dbPath = $dbName;
        if ($dbDriver === 'sqlite' && !str_starts_with($dbPath, '/') && !preg_match('/^[a-zA-Z]:/', $dbPath)) {
            $dbPath = base_path(ltrim($dbPath, '/\\'));
        }

        $io->section('Database');
        $configuredLabel = $dbDriver === 'sqlite' ? 'SQLite' : ($isPgDriver ? 'PostgreSQL' : 'MySQL');
        $io->writeln('Configured driver: ' . $configuredLabel);

        if ($dbDriver === 'sqlite') {
            if (file_exists($dbPath)) {
                if (is_writable($dbPath)) {
                    $io->writeln('✅  SQLite database file found (' . $dbPath . ')');
                    $passed++;
                } else {
                    $io->writeln('❌  SQLite database file not writable (' . $dbPath . ')');
                    $failed++;
                }
            } elseif (is_writable(dirname($dbPath))) {
                $io->writeln('⚠️  SQLite database file not found (' . $dbPath . ') — will be created on first connect');
                $warnings++;
            } else {
                $io->writeln('❌  SQLite database file missing and directory not writable (' . $dbPath . ')');
                $failed++;
            }
        } elseif (file_exists(base_path('storage/database.sqlite'))) {
            $io->note('storage/database.sqlite exists, but DB_DRIVER is ' . $dbDriver . '. Set DB_DRIVER=sqlite to use it.');
        }

        $dbConnected = false;
        try {
            $this->connection->fetchOne('SELECT 1');
            $dbConnected = true;
            $driverLabel = $dbDriver === 'sqlite'
                ? 'SQLite (' . $dbPath . ')'
                : ($isPgDriver ? 'PostgreSQL' : 'MySQL') . ' (' . $dbHost . ':' . $dbPort . '/' . $dbName . ')';
            $io->writeln('✅  Connected (' . $driverLabel . ')');
            $passed++;

            if ($isPgDriver) {
                $versionString = (string) $this->connection->fetchOne('SELECT VERSION()');
                if ($versionString !== '') {
                    if (preg_match('/PostgreSQL\s+([0-9.]+)/i', $versionString, $matches) || preg_match('/^([0-9.]+)/', $versionString, $matches)) {
                        $dbVersion = $matches[1];
                        $dbOk      = $minPgsql !== null ? version_compare($dbVersion, $minPgsql, '>=') : true;
                        $io->writeln(($dbOk ? '✅  ' : '❌  ') . 'PostgreSQL version: ' . $dbVersion . ($dbOk || $minPgsql === null ? '' : ' (< ' . $minPgsql . ')'));
                        $dbOk ? $passed++ : $failed++;
                    } else {
                        $io->writeln('⚠️  Database version format unrecognized: ' . $versionString);
                        $warnings++;
                    }
                } else {
                    $io->writeln('⚠️  Unable to retrieve database version');
                    $warnings++;
                }
            } elseif ($dbDriver === 'mysql') {
                $versionString = $this->connection->fetchOne('SELECT VERSION()');
                if ($versionString) {
                    $isMariadb = str_contains(strtolower($versionString), 'mariadb');

                    if (preg_match('/^([0-9.]+)/', $versionString, $matches)) {
                        $dbVersion = $matches[1];
                        if ($isMariadb) {
                            $dbOk = version_compare($dbVersion, $minMariadb, '>=');
                            $io->writeln(($dbOk ? '✅  ' : '❌  ') . 'MariaDB version: ' . $dbVersion . ($dbOk ? '' : ' (< ' . $minMariadb . ')'));
                            $dbOk ? $passed++ : $failed++;
                        } else {
                            $dbOk = version_compare($dbVersion, $minMysql, '>=');
                            $io->writeln(($dbOk ? '✅  ' : '❌  ') . 'MySQL version: ' . $dbVersion . ($dbOk ? '' : ' (< ' . $minMysql . ')'));
                            $dbOk ? $passed++ : $failed++;
                        }
                    } else {
                        $io->writeln('⚠️  Database version format unrecognized: ' . $versionString);
                        $warnings++;
                    }
                } else {
                    $io->writeln('⚠️  Unable to retrieve database version');
                    $warnings++;
                }
            }
        } catch (\Throwable $e) {
            $io->writeln('❌  ' . strtoupper($dbDriver) . ' connection failed: ' . $e->getMessage());
            $failed++;
        }

        // ── Migrations ──
        $io->section('Migrations');
        if (!$dbConnected) {
            $io->writeln('⚠️  Skipped — database is not connected');
            $warnings++;
        } else {
            $migrationFiles = glob(base_path('database/migrations/*.php'));
            $migrationCount = $migrationFiles ? count($migrationFiles) : 0;
            $applied        = 0;
            try {
                $applied = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM " . VL_DB_PREFIX . "migrations");
            } catch (\Exception $e) {
                // table may not exist yet
            }
            $pending = $migrationCount - $applied;
            if ($migrationCount === 0) {
                $io->writeln('⚠️  No migration files found');
                $warnings++;
            } elseif ($pending > 0) {
                $io->writeln("⚠️  {$pending} pending migration(s) ({$applied} applied, {$migrationCount} total). Run `migrate`.");
                $warnings++;
            } else {
                $io->writeln('✅  All migrations applied (' . $applied . ')');
                $passed++;
            }
        }

        // ── Scheduled Content ──
        $io->section('Scheduled Content');
        if (!$dbConnected) {
            $io->writeln('⚠️  Skipped — database is not connected');
            $warnings++;
        } else {
            $stuck = [];
            foreach (['posts', 'products'] as $table) {
                try {
                    $count = (int) $this->connection->fetchOne(
                        "SELECT COUNT(*) FROM " . VL_DB_PREFIX . $table . " WHERE status = 'scheduled' AND published_at IS NULL"
                    );
                    if ($count > 0) {
                        $stuck[] = "{$table}: {$count}";
                    }
                } catch (\Exception) {
                    // table may not exist yet
                }
            }
            if ($stuck === []) {
                $io->writeln('✅  All scheduled content has a publish date');
                $passed++;
            } else {
                $io->writeln('⚠️  Scheduled without a publish date (will never publish automatically) — ' . implode(', ', $stuck));
                $io->writeln('     Edit each item and set "Publish date & time", or change its status.');
                $warnings++;
            }

            // ── Expired Products ──
            try {
                $expiredCount = (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM " . VL_DB_PREFIX . "products WHERE status IN ('published', 'unlisted') AND expires_at IS NOT NULL AND expires_at <= NOW()"
                );
                if ($expiredCount > 0) {
                    $io->writeln("⚠️  {$expiredCount} product(s) have expired but are still live — run `php vale cron:run` to expire them.");
                    $warnings++;
                } else {
                    $io->writeln('✅  No expired products stuck in a live state');
                    $passed++;
                }
            } catch (\Exception) {
                // table may not exist yet
            }

            // ── Expired Posts/Pages ──
            try {
                $expiredCount = (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM " . VL_DB_PREFIX . "posts WHERE status IN ('published', 'unlisted') AND expires_at IS NOT NULL AND expires_at <= NOW()"
                );
                if ($expiredCount > 0) {
                    $io->writeln("⚠️  {$expiredCount} post(s)/page(s) have expired but are still live — run `php vale cron:run` to expire them.");
                    $warnings++;
                } else {
                    $io->writeln('✅  No expired posts/pages stuck in a live state');
                    $passed++;
                }
            } catch (\Exception) {
                // table may not exist yet
            }
        }

        // ── Encrypted Settings Integrity ──
        $io->section('Encrypted Settings');
        if (!$dbConnected) {
            $io->writeln('⚠️  Skipped — database is not connected');
            $warnings++;
        } else {
            $encryptedKeys = [
                'stripe_secret_key', 'stripe_webhook_secret', 'paypal_client_secret',
                'mollie_api_key', 'twocheckout_api_secret', 'twocheckout_secret_key',
                'checkoutcom_secret_key', 'checkoutcom_webhook_secret',
                'mail_mailgun_api_key', 'mail_sendgrid_api_key', 'mail_resend_api_key',
                'mail_unosend_api_key', 'mail_smtp_pass',
            ];
            $encryptedPatterns = ['~^sso_[a-z0-9_]+_client_secret$~'];
            $corrupted         = [];
            try {
                $kCol = $this->connection->quoteIdentifier('key');
                $vCol = $this->connection->quoteIdentifier('value');
                $rows = $this->connection->fetchAllKeyValue("SELECT {$kCol}, {$vCol} FROM " . VL_DB_PREFIX . "settings");
                foreach ($rows as $key => $value) {
                    $isEncrypted = in_array($key, $encryptedKeys, true);
                    if (!$isEncrypted) {
                        foreach ($encryptedPatterns as $pattern) {
                            if (preg_match($pattern, $key)) {
                                $isEncrypted = true;
                                break;
                            }
                        }
                    }
                    if ($isEncrypted && $value !== null && $value !== '' && str_starts_with((string) $value, 'encrypted::v1::')) {
                        if (!extension_loaded('sodium')) {
                            continue;
                        }
                        try {
                            $encrypter = new Encrypter($appKey ?? '');
                            $decrypted = $encrypter->decrypt((string) $value);
                            if ($decrypted === (string) $value) {
                                $corrupted[] = $key;
                            }
                        } catch (\Throwable) {
                            $corrupted[] = $key;
                        }
                    }
                }
                if (empty($corrupted)) {
                    $io->writeln('✅  All encrypted settings readable');
                    $passed++;
                } else {
                    foreach ($corrupted as $key) {
                        $io->writeln("❌  Corrupted: {$key}");
                        $failed++;
                    }
                    $io->warning('Encrypted settings are corrupted. Run php vale key:generate and re-enter affected settings.');
                }
            } catch (\Throwable $e) {
                $io->writeln('⚠️  Unable to check encrypted settings: ' . $e->getMessage());
                $warnings++;
            }
        }

        // ── PHP Extensions ──
        $io->section('PHP Extensions');

        $requiredExts = array_keys($requirements['required_extensions'] ?? []);
        $extensions   = [];

        // Ensure database drivers are explicitly in the checks
        $extensions['pdo_mysql']  = ($dbDriver === 'mysql' ? 'required' : 'recommended') . ' (MySQL)';
        $extensions['pdo_pgsql']  = ($isPgDriver ? 'required' : 'recommended') . ' (PostgreSQL)';
        $extensions['pdo_sqlite'] = ($dbDriver === 'sqlite' ? 'required' : 'recommended') . ' (SQLite)';

        foreach ($requirements['required_extensions'] as $ext => $desc) {
            $extensions[$ext] = 'required';
        }
        foreach ($requirements['recommended_extensions'] as $ext => $desc) {
            $extensions[$ext] = 'recommended';
        }

        $atLeastOneDb = false;
        foreach ($extensions as $ext => $label) {
            $ok            = extension_loaded($ext);
            $isDriverExt   = ($dbDriver === 'mysql' && $ext === 'pdo_mysql')
                || ($isPgDriver && $ext === 'pdo_pgsql')
                || ($dbDriver === 'sqlite' && $ext === 'pdo_sqlite');
            if (($ext === 'pdo_mysql' || $ext === 'pdo_pgsql' || $ext === 'pdo_sqlite') && $ok) {
                $atLeastOneDb = true;
            }
            if ($ok) {
                $io->writeln('✅  ' . $ext . ' (' . $label . ')');
                $passed++;
            } elseif ($isDriverExt || in_array($ext, $requiredExts, true)) {
                $io->writeln('❌  ' . $ext . ' (' . $label . ')' . ($isDriverExt ? ' — required by configured DB_DRIVER' : ''));
                $failed++;
            } else {
                $io->writeln('⚠️  ' . $ext . ' (' . $label . ')');
                $warnings++;
            }
        }

        if (!$atLeastOneDb) {
            $io->warning('None of pdo_mysql, pdo_pgsql, or pdo_sqlite is loaded. At least one database driver is required.');
        }

        // ── Directory Permissions ──
        $io->section('Directory Permissions');
        $directories = [
            'storage',
            'storage/logs',
            'storage/cache',
            'storage/cache/container',
            'storage/sessions',
            'storage/uploads',
            'storage/ratelimit',
            'storage/backups',
            'storage/framework',
            'public',
        ];

        foreach ($directories as $dir) {
            $fullPath = base_path($dir);
            if (!is_dir($fullPath)) {
                $io->writeln('❌  ' . $dir . ' (missing)');
                $failed++;
                continue;
            }
            if (is_writable($fullPath)) {
                $io->writeln('✅  ' . $dir);
                $passed++;
            } else {
                $io->writeln('❌  ' . $dir);
                $failed++;
            }
        }

        // ── Routing Conflicts (CPT base paths vs pages / global prefixes) ──
        $io->section('Routing Conflicts');
        if (!$dbConnected) {
            $io->writeln('⚠️  Skipped — database is not connected');
            $warnings++;
        } elseif ($this->routing === null) {
            $io->writeln('⚠️  Skipped — routing validator unavailable');
            $warnings++;
        } else {
            try {
                $conflicts = $this->routing->findAllConflicts();
                if ($conflicts === []) {
                    $io->writeln('✅  No content-type / page URL conflicts');
                    $passed++;
                } else {
                    foreach ($conflicts as $conflict) {
                        $io->writeln("❌  [{$conflict['scope']}] {$conflict['name']} ({$conflict['path']}): {$conflict['error']}");
                        $failed++;
                    }
                    $io->warning('Rename the colliding content-type slug/URL prefix or page slug so each public URL is unique.');
                }
            } catch (\Throwable $e) {
                $io->writeln('⚠️  Unable to check routing conflicts: ' . $e->getMessage());
                $warnings++;
            }
        }

        // ── Summary ──
        $io->section('Results');
        $parts = [$passed . ' passed'];
        if ($warnings > 0) {
            $parts[] = $warnings . ' warnings';
        }
        if ($failed > 0) {
            $parts[] = $failed . ' failed';
        }
        $io->writeln(implode(', ', $parts));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Detect a mismatch between the ENCRYPT_PII setting and the on-disk state of
     * order/customer address data. Reads `$passed`, `$warnings` and `$failed` by
     * reference so this can participate in the command's summary/counts.
     *
     * @param int $passed
     * @param int $warnings
     * @param int $failed
     */
    private function checkEncryptPiiConsistency(SymfonyStyle $io, int &$passed, int &$warnings, int &$failed): void
    {
        $encryptPii = (bool) env('ENCRYPT_PII', false);

        $columns = [
            VL_DB_PREFIX . 'orders' => ['billing', 'shipping_address'],
            VL_DB_PREFIX . 'users'  => ['billing_address', 'shipping_address'],
        ];

        $encryptedRows = 0;
        try {
            $expr    = $this->connection->createExpressionBuilder();
            $pattern = '%encrypted::v1::%';
            foreach ($columns as $table => $cols) {
                $where = [];
                foreach ($cols as $col) {
                    $q       = $this->connection->quoteIdentifier($col);
                    $where[] = (string) $expr->like($q, '?');
                }
                $sql           = 'SELECT COUNT(*) FROM ' . $table . ' WHERE (' . implode(' OR ', $where) . ')';
                $encryptedRows += (int) $this->connection->fetchOne($sql, array_fill(0, count($cols), $pattern));
            }
        } catch (\Throwable $e) {
            $io->writeln('⚠️  Unable to check ENCRYPT_PII consistency: ' . $e->getMessage());
            $warnings++;
            return;
        }

        if ($encryptPii) {
            if ($encryptedRows > 0) {
                $io->writeln('✅  ENCRYPT_PII = true, ' . $encryptedRows . ' address record(s) encrypted');
                $passed++;
            } else {
                $io->writeln('⚠️  ENCRYPT_PII = true, but no encrypted addresses found yet (addresses are encrypted on save)');
                $warnings++;
            }
            return;
        }

        if ($encryptedRows > 0) {
            $io->writeln('❌  ENCRYPT_PII is off but ' . $encryptedRows . ' encrypted address record(s) exist');
            $io->writeln('    These will be returned as-is (ciphertext) until re-saved with ENCRYPT_PII = true.');
            $failed++;
        } else {
            $io->writeln('✅  ENCRYPT_PII = false, address data is stored in plaintext');
            $passed++;
        }
    }
}
