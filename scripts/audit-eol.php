<?php

declare(strict_types=1);

/**
 * Vale CMS — EOL & Dependency Audit Tool
 *
 * This script connects to the endoflife.date API to check if the currently running
 * PHP, MySQL/MariaDB, PostgreSQL, SQLite, and Composer versions (as well as the configured database
 * minimum requirements) are approaching or have reached their End-Of-Life (EOL).
 *
 * Usage:
 *   composer audit:eol
 */

require __DIR__ . '/../vendor/autoload.php';

// Colors for terminal output
const COLOR_GREEN  = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_RED    = "\033[31m";
const COLOR_RESET  = "\033[0m";
const COLOR_BOLD   = "\033[1m";

echo COLOR_BOLD . "Vale CMS — End-Of-Life (EOL) Dependency Audit" . COLOR_RESET . "\n";
echo "====================================================\n\n";

// Load .env if exists
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->safeLoad();
}

// Load requirements from config
$referenceData = require __DIR__ . '/../config/reference-data.php';
$requirements  = $referenceData['requirements'] ?? null;
if (!$requirements || !isset($requirements['min_mysql']) || !isset($requirements['min_mariadb'])) {
    echo "❌ Configuration Error: Database requirements are missing from reference-data.php.\n";
    exit(1);
}
$minMysql   = $requirements['min_mysql'];
$minMariadb = $requirements['min_mariadb'];

// 1. Gather component versions
$components = [];

// PHP (Running)
$components['php'] = [
    'name'            => 'PHP (Running)',
    'running_version' => PHP_VERSION,
    'api_product'     => 'php',
];

// Composer (Running)
$composerVersion = null;
$output          = [];
$returnVar       = 0;
@exec('composer -V 2>&1', $output, $returnVar);
if ($returnVar === 0 && !empty($output)) {
    if (preg_match('/Composer version ([0-9.]+)/i', $output[0], $matches)) {
        $composerVersion = $matches[1];
    }
}
if ($composerVersion) {
    $components['composer'] = [
        'name'            => 'Composer (Running)',
        'running_version' => $composerVersion,
        'api_product'     => 'composer',
    ];
}

// SQLite (Running)
$sqliteVersion = null;
if (extension_loaded('pdo_sqlite')) {
    try {
        $pdo           = new PDO('sqlite::memory:');
        $sqliteVersion = $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION);
    } catch (\Throwable) {
    }
}
if ($sqliteVersion) {
    $components['sqlite'] = [
        'name'            => 'SQLite (Running)',
        'running_version' => $sqliteVersion,
        'api_product'     => 'sqlite',
    ];
}

// MySQL (Required Minimum)
$components['mysql_req'] = [
    'name'            => 'MySQL (Required Min)',
    'running_version' => $minMysql,
    'api_product'     => 'mysql',
];

// MariaDB (Required Minimum)
$components['mariadb_req'] = [
    'name'            => 'MariaDB (Required Min)',
    'running_version' => $minMariadb,
    'api_product'     => 'mariadb',
];

// PostgreSQL (Required Minimum)
$minPgsql = $requirements['min_pgsql'] ?? null;
if ($minPgsql) {
    $components['pgsql_req'] = [
        'name'            => 'PostgreSQL (Required Min)',
        'running_version' => $minPgsql,
        'api_product'     => 'postgresql',
    ];
}

// Active Database Connection (Running)
$dbVersion = null;
$dbType    = null;
$dbDriver  = $_ENV['DB_DRIVER'] ?? 'mysql';

if ($dbDriver === 'mysql') {
    $dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $dbPort = $_ENV['DB_PORT'] ?? 3306;
    $dbName = $_ENV['DB_NAME'] ?? '';
    $dbUser = $_ENV['DB_USER'] ?? '';
    $dbPass = $_ENV['DB_PASS'] ?? '';

    if ($dbName !== '') {
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_TIMEOUT => 2,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $versionString = $pdo->query('SELECT VERSION()')->fetchColumn();
            if ($versionString) {
                $dbType = str_contains(strtolower($versionString), 'mariadb') ? 'mariadb' : 'mysql';
                if (preg_match('/^([0-9.]+)/', $versionString, $matches)) {
                    $dbVersion = $matches[1];
                }
            }
        } catch (\Throwable) {
            // DB is offline/unreachable, skip active database check
        }
    }
} elseif (in_array($dbDriver, ['pgsql', 'postgres', 'postgresql'], true)) {
    $dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $dbPort = $_ENV['DB_PORT'] ?? 5432;
    $dbName = $_ENV['DB_NAME'] ?? '';
    $dbUser = $_ENV['DB_USER'] ?? '';
    $dbPass = $_ENV['DB_PASS'] ?? '';

    if ($dbName !== '') {
        try {
            $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_TIMEOUT => 2,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $versionString = $pdo->query('SELECT VERSION()')->fetchColumn();
            if ($versionString) {
                $dbType = 'postgresql';
                if (preg_match('/PostgreSQL\s+([0-9.]+)/i', (string) $versionString, $matches) || preg_match('/^([0-9.]+)/', (string) $versionString, $matches)) {
                    $dbVersion = $matches[1];
                }
            }
        } catch (\Throwable) {
            // DB is offline/unreachable, skip active database check
        }
    }
}

if ($dbVersion && $dbType) {
    $activeName = match ($dbType) {
        'mariadb'    => 'MariaDB',
        'postgresql' => 'PostgreSQL',
        default      => 'MySQL',
    };
    $components['database_active'] = [
        'name'            => $activeName . ' (Active DB)',
        'running_version' => $dbVersion,
        'api_product'     => $dbType === 'postgresql' ? 'postgresql' : $dbType,
    ];
} else {
    echo "ℹ️  Active database connection is offline. Skipping active DB audit.\n\n";
}

// 2. Fetch EOL details from API
function fetchEolData(string $product): ?array
{
    $url     = "https://endoflife.date/api/{$product}.json";
    $options = [
        'http' => [
            'header'  => "User-Agent: ValeCMS-Audit/0.1.0\r\nAccept: application/json\r\n",
            'timeout' => 8,
        ]
    ];
    $context = stream_context_create($options);
    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        return null;
    }
    return json_decode($content, true);
}

$hasEolComponents = false;
$headers          = ['Component', 'Cycle', 'Version', 'Latest', 'EOL Date', 'Remaining', 'Status'];
$rows             = [];

foreach ($components as $key => $info) {
    echo "Fetching EOL data for {$info['name']}...\n";
    $apiData = fetchEolData($info['api_product']);
    if (!$apiData) {
        echo "⚠️  Failed to fetch EOL data for {$info['name']} from endoflife.date.\n";
        continue;
    }

    // Determine running version cycle
    $parts = explode('.', $info['running_version']);
    $cycle = count($parts) >= 2 ? $parts[0] . '.' . $parts[1] : $info['running_version'];

    // Find cycle match
    $cycleData = null;
    foreach ($apiData as $c) {
        if ((string) $c['cycle'] === (string) $cycle) {
            $cycleData = $c;
            break;
        }
    }

    if (!$cycleData) {
        // Fallback: match major only
        $major = $parts[0];
        foreach ($apiData as $c) {
            if ((string) $c['cycle'] === (string) $major) {
                $cycleData = $c;
                break;
            }
        }
    }

    // Use the matched API cycle for display
    if ($cycleData && isset($cycleData['cycle'])) {
        $cycle = (string) $cycleData['cycle'];
    }

    if (!$cycleData) {
        $rows[] = [$info['name'], $cycle, $info['running_version'], 'Unknown', 'Unknown', 'N/A', COLOR_YELLOW . 'Unrecognized Cycle' . COLOR_RESET];
        continue;
    }

    $latest    = $cycleData['latest'] ?? 'Unknown';
    $eolVal    = $cycleData['eol']    ?? null;
    $status    = 'Active';
    $color     = COLOR_GREEN;
    $remaining = 'N/A';

    if ($eolVal === true) {
        $status           = 'EOL (Deprecated)';
        $color            = COLOR_RED;
        $hasEolComponents = true;
    } elseif ($eolVal === false || $eolVal === null) {
        if ($info['api_product'] === 'sqlite') {
            $status    = 'Supported (Pledge 2050)';
            $remaining = 'Active (Rolling)';
        } else {
            $status = 'Supported';
        }
        $color = COLOR_GREEN;
    } else {
        // eolVal is a date string
        $eolTimestamp = strtotime($eolVal);
        $diff         = $eolTimestamp - time();
        if ($diff < 0) {
            $status           = 'EOL (Deprecated)';
            $color            = COLOR_RED;
            $hasEolComponents = true;
            $remaining        = 'Expired';
        } else {
            $days      = (int) ceil($diff / 86400);
            $remaining = $days . ' days';
            if ($days < 180) {
                $status = 'Expiring Soon';
                $color  = COLOR_YELLOW;
            } else {
                $status = 'Supported';
                $color  = COLOR_GREEN;
            }
        }
    }

    $rows[] = [
        $info['name'],
        $cycle,
        $info['running_version'],
        $latest,
        is_string($eolVal) ? $eolVal : ($eolVal ? 'Yes' : 'None'),
        $remaining,
        $color . $status . COLOR_RESET
    ];
}

echo "\n";

// Clean function to draw a beautiful CLI table
function drawTable(array $headers, array $rows): void
{
    $widths = [];
    foreach ($headers as $col => $header) {
        $widths[$col] = strlen($header);
    }
    foreach ($rows as $row) {
        foreach ($row as $col => $val) {
            // Strip ANSI codes for length calculation
            $plainVal     = preg_replace('/\033\[[0-9;]*m/', '', (string) $val);
            $widths[$col] = max($widths[$col], strlen($plainVal));
        }
    }

    $line = '+';
    foreach ($widths as $w) {
        $line .= str_repeat('-', $w + 2) . '+';
    }
    $line .= "\n";

    echo $line;
    // Headers
    echo '|';
    foreach ($headers as $col => $header) {
        echo ' ' . str_pad($header, $widths[$col]) . ' |';
    }
    echo "\n" . $line;

    // Rows
    foreach ($rows as $row) {
        echo '|';
        foreach ($row as $col => $val) {
            $plainVal = preg_replace('/\033\[[0-9;]*m/', '', (string) $val);
            $padding  = $widths[$col] - strlen($plainVal);
            echo ' ' . $val . str_repeat(' ', $padding) . ' |';
        }
        echo "\n";
    }
    echo $line;
}

if (!empty($rows)) {
    drawTable($headers, $rows);
}

echo "\n";
if ($hasEolComponents) {
    echo COLOR_RED . "❌ Audit Failed: One or more active components have reached End-Of-Life (EOL)." . COLOR_RESET . "\n";
    exit(1);
} else {
    echo COLOR_GREEN . "✅ Audit Passed: All active components are supported and healthy!" . COLOR_RESET . "\n";
    exit(0);
}
