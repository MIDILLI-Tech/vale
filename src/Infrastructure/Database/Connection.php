<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Database;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;

/**
 * Convenience wrapper to create a Doctrine DBAL connection from environment.
 */
final class Connection
{
    /**
     * Create a DBAL connection from current ENV variables.
     */
    public static function createFromEnv(): DbalConnection
    {
        $driver = env('DB_DRIVER', 'mysql');
        if ($driver === 'sqlite') {
            $path = env('DB_NAME', 'storage/database.sqlite');
            if (!str_starts_with($path, '/') && !preg_match('/^[a-zA-Z]:/', $path)) {
                $path = base_path(ltrim($path, '/\\'));
            }
            return DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path'   => $path,
            ]);
        }

        if (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            return DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => env('DB_HOST', '127.0.0.1'),
                'port'     => (int) env('DB_PORT', 5432),
                'dbname'   => env('DB_NAME', 'Vale'),
                'user'     => env('DB_USER', 'postgres'),
                'password' => env('DB_PASS', ''),
                'charset'  => env('DB_CHARSET', 'utf8'),
            ]);
        }

        return DriverManager::getConnection([
            'driver'   => 'pdo_mysql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => (int) env('DB_PORT', 3306),
            'dbname'   => env('DB_NAME', 'Vale'),
            'user'     => env('DB_USER', 'root'),
            'password' => env('DB_PASS', ''),
            'charset'  => env('DB_CHARSET', 'utf8mb4'),
        ]);
    }
}
