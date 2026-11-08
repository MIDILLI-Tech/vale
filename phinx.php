<?php

declare(strict_types=1);

/**
 * Phinx migration configuration.
 * Reads database credentials from .env via environment variables.
 */

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/app/helpers.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$dbDriver = env('DB_DRIVER', 'mysql');
$dbName   = env('DB_NAME', 'storage/database.sqlite');

if ($dbDriver === 'sqlite') {
    if (!str_starts_with((string) $dbName, '/') && !preg_match('/^[a-zA-Z]:/', (string) $dbName)) {
        $dbName = __DIR__ . DIRECTORY_SEPARATOR . ltrim((string) $dbName, '/\\');
    }
}

$phinxAdapter = match ($dbDriver) {
    'sqlite'                          => 'sqlite',
    'pgsql', 'postgres', 'postgresql' => 'pgsql',
    default                           => 'mysql',
};

$defaultPort    = $phinxAdapter === 'pgsql' ? 5432 : 3306;
$defaultUser    = $phinxAdapter === 'pgsql' ? 'postgres' : 'root';
$defaultCharset = $phinxAdapter === 'pgsql' ? 'utf8' : 'utf8mb4';

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/database/migrations',
        'seeds'      => '%%PHINX_CONFIG_DIR%%/database/seeds',
    ],
    'environments' => [
        'default_migration_table' => env('DB_PREFIX', 'vl_') . 'migrations',
        'default_environment'     => 'default',
        'default' => [
            'adapter'   => $phinxAdapter,
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => (int) env('DB_PORT', $defaultPort),
            'name'      => $dbName,
            'suffix'    => '',
            'user'      => env('DB_USER', $defaultUser),
            'pass'      => env('DB_PASS', ''),
            'charset'   => env('DB_CHARSET', $defaultCharset),
            'db_prefix' => env('DB_PREFIX', 'vl_'),
        ],
    ],
    'version_order' => 'creation',
];
