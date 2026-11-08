<?php

// Truncate Vale tables and drop WordPress tables from the active database.
// Usage: php scripts/truncate_db.php [--dry-run]

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$dryRun = in_array('--dry-run', $argv, true);

$host   = getenv('DB_HOST') ?: '127.0.0.1';
$port   = getenv('DB_PORT') ?: '3306';
$db     = getenv('DB_NAME');
$user   = getenv('DB_USER');
$pass   = getenv('DB_PASS');
$prefix = getenv('DB_PREFIX') ?: 'vl_';

// Fallback: parse .env manually if Dotenv missed inline comments
if (!$db || !$user) {
    $envPath = __DIR__ . '/../.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, 'DB_NAME=') === 0) {
                $db = trim(preg_replace('/\s*#.*$/', '', explode('=', $line, 2)[1]));
            }
            if (strpos($line, 'DB_USER=') === 0) {
                $user = trim(preg_replace('/\s*#.*$/', '', explode('=', $line, 2)[1]));
            }
            if (strpos($line, 'DB_PASS=') === 0) {
                $pass = trim(preg_replace('/\s*#.*$/', '', explode('=', $line, 2)[1]));
            }
            if (strpos($line, 'DB_PREFIX=') === 0) {
                $prefix = trim(preg_replace('/\s*#.*$/', '', explode('=', $line, 2)[1]));
            }
        }
    }
}

if (!$db || !$user) {
    fwrite(STDERR, "Missing DB_NAME or DB_USER in .env\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Fetch all tables
$stmt   = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

$wpTables   = [];
$valeTables = [];

foreach ($tables as $table) {
    if (str_starts_with($table, 'wp_')) {
        $wpTables[] = $table;
    } elseif (str_starts_with($table, $prefix)) {
        $valeTables[] = $table;
    }
}

echo "WordPress tables to drop: " . count($wpTables) . "\n";
echo "Vale tables to truncate: " . count($valeTables) . "\n";

if ($dryRun) {
    echo "\n[DRY RUN] No changes made.\n";
    exit(0);
}

// Disable foreign key checks
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

// Drop WordPress tables
foreach ($wpTables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    echo "Dropped: {$table}\n";
}

// Truncate Vale tables
foreach ($valeTables as $table) {
    $pdo->exec("TRUNCATE TABLE `{$table}`");
    echo "Truncated: {$table}\n";
}

echo "Execute: 'php vale db:seed' to fill the initial values (e.g., roles).\n";

// Re-enable foreign key checks
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "\nDone.\n";
