<?php

declare(strict_types=1);

/**
 * Database configuration.
 */

return [
    'driver'  => env('DB_DRIVER', 'mysql'),
    'host'    => env('DB_HOST', '127.0.0.1'),
    'port'    => (int) env('DB_PORT', in_array(env('DB_DRIVER', 'mysql'), ['pgsql', 'postgres', 'postgresql'], true) ? 5432 : 3306),
    'name'    => env('DB_DRIVER', 'mysql') === 'sqlite'
        ? (env('DB_NAME') === ':memory:' || str_starts_with((string) env('DB_NAME', ''), '/') || preg_match('/^[a-zA-Z]:/', (string) env('DB_NAME', '')) ? env('DB_NAME') : base_path(ltrim((string) env('DB_NAME', 'storage/database.sqlite'), '/\\')))
        : env('DB_NAME', 'Vale'),
    'user'    => env('DB_USER', in_array(env('DB_DRIVER', 'mysql'), ['pgsql', 'postgres', 'postgresql'], true) ? 'postgres' : 'root'),
    'pass'    => env('DB_PASS', ''),
    'charset' => env('DB_CHARSET', in_array(env('DB_DRIVER', 'mysql'), ['pgsql', 'postgres', 'postgresql'], true) ? 'utf8' : 'utf8mb4'),

    // Doctrine DBAL specific
    'options' => [
        \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES   => false,
    ],
];
