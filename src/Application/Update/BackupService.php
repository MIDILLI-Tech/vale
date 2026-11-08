<?php

declare(strict_types=1);

namespace ValeApp\Application\Update;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Creates and restores file + database backups before applying updates.
 *
 * Backup layout:
 *   storage/backups/update-{version}-{YmdHis}/
 *     ├── files/              — mirrored installation tree (protected paths excluded)
 *     ├── database.sql        — SQL dump (PDO-based, table-by-table)
 *     └── backup.json         — metadata
 *
 * At most MAX_BACKUPS are kept; older ones are pruned automatically.
 */
final class BackupService
{
    private const MAX_BACKUPS = 5;

    /** Paths excluded from the file backup (relative to root, forward-slash normalised). */
    private const EXCLUDE = [
        'storage/cache',
        'storage/backups',
        'storage/logs',

    ];

    public function __construct(
        private readonly string $rootDir,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?\Closure $runMigrations = null,
    ) {
    }

    private function pdo(): \PDO
    {
        return $this->connection->getNativeConnection();
    }

    private function driver(): string
    {
        return (string) $this->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    private function isPgsql(): bool
    {
        return $this->driver() === 'pgsql';
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        return match ($this->driver()) {
            'sqlite' => $this->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")?->fetchAll(\PDO::FETCH_COLUMN)             ?? [],
            'pgsql'  => $this->pdo()->query("SELECT tablename FROM pg_tables WHERE schemaname = CURRENT_SCHEMA() ORDER BY tablename ASC")?->fetchAll(\PDO::FETCH_COLUMN) ?? [],
            default  => $this->pdo()->query('SHOW TABLES')?->fetchAll(\PDO::FETCH_COLUMN)                                                                                ?? [],
        };
    }

    /** Platform-correct identifier quoting for dump SQL text. */
    private function quoteIdent(string $name): string
    {
        if ($this->isPgsql()) {
            return '"' . str_replace('"', '""', $name) . '"';
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    // ── Create ────────────────────────────────────────────────────────────

    /**
     * Create a complete backup (files + database).
     *
     * @param  bool   $dryRun When true, only report what would be backed up without writing anything.
     * @return string Absolute path to the backup directory, or empty string in dry-run mode.
     * @throws \RuntimeException on failure.
     */
    public function createBackup(string $version = 'unknown', bool $quick = false, bool $dryRun = false): string
    {
        set_time_limit(0);

        $stamp     = date('YmdHis');
        $safeName  = preg_replace('/[^a-zA-Z0-9._-]/', '-', $version);
        $backupDir = $this->backupsRoot() . "/update-{$safeName}-{$stamp}";

        if ($dryRun) {
            $fileCount = 0;
            $src       = rtrim($this->rootDir, '/\\');
            $it        = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $file) {
                $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($src))), '/');
                if ($this->isExcluded($rel, $file->isDir(), $quick)) {
                    continue;
                }
                if ($file->isFile()) {
                    $fileCount++;
                }
            }

            $dbSize     = 0;
            $driver     = $this->driver();
            $isSqlite   = $driver === 'sqlite';
            $tables     = $this->tableNames();

            if ($isSqlite) {
                // For SQLite, we can just get the file size of the database
                $dbName = $this->pdo()->query("PRAGMA database_list")?->fetchAll(\PDO::FETCH_ASSOC)[0]['file'] ?? '';
                if ($dbName && file_exists($dbName)) {
                    $dbSize = filesize($dbName);
                }
            } elseif ($driver === 'pgsql') {
                foreach ($tables as $table) {
                    $row = $this->pdo()->query('SELECT pg_total_relation_size(' . $this->pdo()->quote((string) $table) . ')')?->fetch(\PDO::FETCH_NUM);
                    if ($row) {
                        $dbSize += (int) $row[0];
                    }
                }
            } else {
                foreach ($tables as $table) {
                    $row = $this->pdo()->query("SHOW TABLE STATUS LIKE " . $this->pdo()->quote((string) $table))?->fetch(\PDO::FETCH_ASSOC);
                    if ($row) {
                        $dbSize += (int) ($row['Data_length'] + $row['Index_length']);
                    }
                }
            }

            $tableCount = count($tables);
            $dbSizeFmt  = $dbSize > 0 ? round($dbSize / 1024 / 1024, 2) . ' MB' : '0 B';
            $this->logger->info("[Backup] Dry-run: would back up {$fileCount} files, {$tableCount} tables, ~{$dbSizeFmt} database");

            return '';
        }

        if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            throw new \RuntimeException("Cannot create backup directory: {$backupDir}");
        }

        $this->logger->info("[Backup] Creating " . ($quick ? 'quick ' : '') . "backup at {$backupDir}");

        // ── Early metadata (so partial backup appears in list) ──────────
        $meta = [
            'version'     => $version,
            'date'        => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'file_count'  => 0,
            'db_size'     => 0,
            'hostname'    => gethostname() ?: 'unknown',
            'quick'       => $quick,
            'db_driver'   => $this->driver(),
        ];
        file_put_contents($backupDir . '/backup.json', json_encode($meta, JSON_PRETTY_PRINT));

        // ── Files ──────────────────────────────────────────────────────
        $filesDir = $backupDir . '/files';
        mkdir($filesDir, 0755, true);
        $meta['file_count'] = $this->copyTree($this->rootDir, $filesDir, false, $quick);

        // ── Database ───────────────────────────────────────────────────
        $sqlPath         = $backupDir . '/database.sql';
        $meta['db_size'] = $this->dumpDatabase($sqlPath);

        // ── Update metadata with real values ───────────────────────────
        file_put_contents($backupDir . '/backup.json', json_encode($meta, JSON_PRETTY_PRINT));

        $this->logger->info("[Backup] Done: {$meta['file_count']} files, {$meta['db_size']} bytes SQL");

        // ── Prune old backups ──────────────────────────────────────────
        $this->pruneOldBackups();

        return $backupDir;
    }

    // ── Restore ───────────────────────────────────────────────────────────

    /**
     * Roll back to a previously created backup.
     *
     * @param  string $backupDir Absolute path to a backup directory.
     * @param  string $type      'all', 'files', or 'database'.
     * @param  bool   $dryRun    When true, only report what would be restored without writing anything.
     * @throws \RuntimeException on failure.
     */
    public function rollback(string $backupDir, string $type = 'all', bool $dryRun = false): void
    {
        if (!is_dir($backupDir)) {
            throw new \RuntimeException("Backup directory not found: {$backupDir}");
        }

        $metaFile = $backupDir . '/backup.json';
        if (!file_exists($metaFile)) {
            throw new \RuntimeException("backup.json missing in: {$backupDir}");
        }

        $meta = json_decode(file_get_contents($metaFile) ?: '{}', true);
        if (!is_array($meta)) {
            $meta = [];
        }

        $this->logger->info("[Rollback] Starting rollback ({$type}) from {$backupDir}");

        // Prevent restoring a backup taken on a different database driver,
        // which would produce invalid SQL and could destroy the current data.
        $backupDriver = isset($meta['db_driver']) ? (string) $meta['db_driver'] : '';
        if ($backupDriver !== '' && $backupDriver !== 'unknown' && $backupDriver !== $this->driver()) {
            throw new \RuntimeException(sprintf(
                'Cannot restore a %s backup into a %s database (cross-database restore is not supported).',
                strtoupper($backupDriver),
                strtoupper($this->driver())
            ));
        }

        $filesDir = $backupDir . '/files';
        $sqlPath  = $backupDir . '/database.sql';
        $hasFiles = is_dir($filesDir);
        $hasDb    = file_exists($sqlPath);

        if ($dryRun) {
            $parts = [];
            if ($type === 'all' || $type === 'files') {
                $parts[] = $hasFiles ? 'files (will be restored)' : 'no files backup found';
            }
            if ($type === 'all' || $type === 'database') {
                $parts[] = $hasDb ? 'database (will be restored)' : 'no database backup found';
            }
            $this->logger->info('[Rollback] Dry-run: would restore ' . implode(', ', $parts));
            return;
        }

        // ── Restore files ──────────────────────────────────────────────
        if ($type === 'all' || $type === 'files') {
            if ($hasFiles) {
                $this->copyTree($filesDir, $this->rootDir, copyAll: true);
                $this->logger->info('[Rollback] Files restored');
            }
        }

        // ── Restore database ───────────────────────────────────────────
        if ($type === 'all' || $type === 'database') {
            if ($hasDb) {
                $this->importDatabase($sqlPath);
                $this->logger->info('[Rollback] Database restored');
            }
        }

        $this->logger->info('[Rollback] Complete');
    }

    // ── Delete ─────────────────────────────────────────────────────────────

    /**
     * Delete a single backup directory.
     *
     * @throws \RuntimeException if the directory does not exist.
     */
    public function deleteBackup(string $backupDir): void
    {
        if (!is_dir($backupDir)) {
            throw new \RuntimeException("Backup directory not found: {$backupDir}");
        }

        $this->rmdirRecursive($backupDir);

        // Also delete associated zip if exists
        $zipPath = $backupDir . '.zip';
        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $this->logger->info("[Backup] Deleted: {$backupDir}");
    }

    /**
     * Create a ZIP archive of a backup directory.
     * Returns the path to the ZIP file.
     */
    public function zipBackup(string $backupDir): string
    {
        if (!$this->isZipSupported()) {
            throw new \RuntimeException("The ZIP extension is not enabled on this server. Cannot create archive.");
        }

        if (!is_dir($backupDir)) {
            throw new \RuntimeException("Backup directory not found: {$backupDir}");
        }

        $zipPath = rtrim($backupDir, '/') . '.zip';
        if (file_exists($zipPath)) {
            return $zipPath;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create ZIP file: {$zipPath}");
        }

        $root  = realpath($backupDir);
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath     = $file->getRealPath();
                $relativePath = substr($filePath, strlen($root) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();
        return $zipPath;
    }

    public function isZipSupported(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    // ── List ──────────────────────────────────────────────────────────────

    /**
     * Return all backups sorted newest-first.
     *
     * @return array<int, array{dir: string, version: string, date: string, file_count: int, db_size: int, size_bytes: int}>
     */
    public function listBackups(): array
    {
        $root = $this->backupsRoot();
        if (!is_dir($root)) {
            return [];
        }

        $entries = array_filter(
            scandir($root) ?: [],
            fn ($e) => $e !== '.' && $e !== '..' && is_dir("{$root}/{$e}")
        );

        $result = [];
        foreach ($entries as $entry) {
            $dir      = "{$root}/{$entry}";
            $metaFile = "{$dir}/backup.json";
            if (!file_exists($metaFile)) {
                continue;
            }
            $meta        = json_decode(file_get_contents($metaFile) ?: '{}', true) ?? [];
            $filesDir    = "{$dir}/files";
            $sqlFile     = "{$dir}/database.sql";
            $result[]    = [
                'dir'          => $dir,
                'name'         => $entry,
                'version'      => $meta['version']     ?? '?',
                'date'         => $meta['date']        ?? '?',
                'file_count'   => (int) ($meta['file_count'] ?? 0),
                'db_size'      => (int) ($meta['db_size']    ?? 0),
                'size_bytes'   => $this->dirSize($dir),
                'has_files'    => is_dir($filesDir)     && (new \FilesystemIterator($filesDir, \FilesystemIterator::SKIP_DOTS))->valid(),
                'has_database' => file_exists($sqlFile) && filesize($sqlFile) > 0,
                'db_driver'    => (string) ($meta['db_driver'] ?? 'unknown'),
            ];
        }

        // Sort newest first (directory names start with date, so reverse alpha works)
        usort($result, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $result;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function backupsRoot(): string
    {
        $dir = $this->rootDir . '/storage/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Copy directory tree. When $copyAll=false (backup mode) we skip EXCLUDE paths.
     * When $copyAll=true (restore mode) we copy everything.
     */
    private function copyTree(string $src, string $dst, bool $copyAll = false, bool $quick = false): int
    {
        $count = 0;
        $src   = rtrim($src, '/\\');
        $dst   = rtrim($dst, '/\\');

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $file) {
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($src))), '/');

            if (!$copyAll && $this->isExcluded($rel, $file->isDir(), $quick)) {
                continue;
            }

            $target = $dst . '/' . $rel;

            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                copy($file->getPathname(), $target);
                $count++;
            }
        }

        return $count;
    }

    private function isExcluded(string $rel, bool $isDir = false, bool $quick = false): bool
    {
        // Skip hidden directories (starting with .) and anything inside them
        $parts = explode('/', $rel);
        $last  = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($part !== '' && $part[0] === '.') {
                if ($i < $last || $isDir) {
                    return true;
                }
            }
        }

        // Quick backup: exclude external dependencies
        if ($quick) {
            foreach (['node_modules', 'vendor'] as $prefix) {
                if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) {
                    return true;
                }
            }
        }

        foreach (self::EXCLUDE as $prefix) {
            $prefix = rtrim($prefix, '/');
            if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dump all tables to $sqlPath using PDO.
     * Returns the number of bytes written.
     */
    private function dumpDatabase(string $sqlPath): int
    {
        $fh = fopen($sqlPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot write SQL dump to: {$sqlPath}");
        }

        $driver   = $this->driver();
        $isSqlite = $driver === 'sqlite';
        $isPgsql  = $this->isPgsql();

        fwrite($fh, "-- Vale database backup " . date('Y-m-d H:i:s') . " ({$driver})\n");

        if ($isSqlite) {
            fwrite($fh, "PRAGMA foreign_keys = OFF;\n\n");
        } elseif (!$isPgsql) {
            fwrite($fh, "SET NAMES utf8mb4;\n");
            fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n\n");
        }

        $tables = $this->tableNames();

        foreach ($tables as $table) {
            $table = (string) $table;
            $ident = $this->quoteIdent($table);

            // Table structure — PostgreSQL dumps are data-only; the schema is
            // rebuilt from Phinx migrations at restore time.
            if ($isSqlite) {
                $createRow = $this->pdo()->query("SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $this->pdo()->quote($table))?->fetch(\PDO::FETCH_NUM);
                if ($createRow) {
                    fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($fh, $createRow[0] . ";\n\n");
                }
            } elseif (!$isPgsql) {
                $createRow = $this->pdo()->query("SHOW CREATE TABLE `{$table}`")?->fetch(\PDO::FETCH_NUM);
                if ($createRow) {
                    fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($fh, $createRow[1] . ";\n\n");
                }
            }

            // Table data
            $rows = $this->pdo()->query("SELECT * FROM {$ident}")?->fetchAll(\PDO::FETCH_ASSOC) ?? [];
            foreach ($rows as $row) {
                $cols   = implode(', ', array_map(fn ($c) => $this->quoteIdent((string) $c), array_keys($row)));
                $values = implode(', ', array_map(function ($v) {
                    if ($v === null) {
                        return 'NULL';
                    }
                    if (is_bool($v)) {
                        return $v ? 'TRUE' : 'FALSE';
                    }

                    return $this->pdo()->quote((string) $v);
                }, $row));
                fwrite($fh, "INSERT INTO {$ident} ({$cols}) VALUES ({$values});\n");
            }
            fwrite($fh, "\n");
        }

        if ($isSqlite) {
            fwrite($fh, "PRAGMA foreign_keys = ON;\n");
        } elseif (!$isPgsql) {
            fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
        }
        fclose($fh);

        return (int) filesize($sqlPath);
    }

    /**
     * Splits a SQL dump into individual executable statements, respecting
     * string literals ('...'), PostgreSQL dollar quotes ($tag$...$tag$), escaped quotes, and comments.
     *
     * @return list<string>
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $len        = strlen($sql);
        $current    = '';
        $inString   = false;
        $stringChar = null;
        $inDollar   = false;
        $dollarTag  = '';
        $inComment  = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($inComment) {
                if ($ch === "\n") {
                    $inComment = false;
                }
                continue;
            }

            if ($inDollar) {
                $current .= $ch;
                if (str_ends_with($current, $dollarTag)) {
                    $inDollar  = false;
                    $dollarTag = '';
                }
                continue;
            }

            if ($inString) {
                $current .= $ch;
                if ($ch === $stringChar) {
                    if ($i > 0 && $sql[$i - 1] === '\\') {
                        $slashCount = 0;
                        for ($j = $i - 1; $j >= 0 && $sql[$j] === '\\'; $j--) {
                            $slashCount++;
                        }
                        if ($slashCount % 2 !== 0) {
                            continue;
                        }
                    }
                    if ($i + 1 < $len && $sql[$i + 1] === $stringChar) {
                        $current .= $sql[$i + 1];
                        $i++;
                        continue;
                    }
                    $inString   = false;
                    $stringChar = null;
                }
                continue;
            }

            if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                $inComment = true;
                $i++;
                continue;
            }

            // Check for PostgreSQL dollar quote start: $$ or $tag$
            if ($ch === '$' && preg_match('/^(\$[a-zA-Z0-9_]*\$)/', substr($sql, $i), $m)) {
                $inDollar  = true;
                $dollarTag = $m[1];
                $current  .= $dollarTag;
                $i        += strlen($dollarTag) - 1;
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString   = true;
                $stringChar = $ch;
                $current   .= $ch;
                continue;
            }

            if ($ch === ';') {
                $stmt = trim($current);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        $stmt = trim($current);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Re-import a previously dumped SQL file, statement by statement.
     */
    private function importDatabase(string $sqlPath): void
    {
        if ($this->isPgsql()) {
            $this->importDatabasePgsql($sqlPath);

            return;
        }

        $sql = file_get_contents($sqlPath);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read SQL dump: {$sqlPath}");
        }

        $driver   = $this->driver();
        $isSqlite = $driver === 'sqlite';
        $isMysql  = in_array($driver, ['mysql', 'mysqli', 'pdo_mysql'], true);

        // Pre-flight: parse the dump BEFORE touching any data, so a malformed
        // dump (or a cross-engine mistake that slipped past the guard) cannot
        // leave the database half-destroyed.
        $statements = $this->splitSqlStatements($sql);
        if ($statements === []) {
            throw new \RuntimeException('Database restore failed: SQL dump contains no statements.');
        }

        // SQLite: DDL is transactional, so dropping + replaying inside a
        // single transaction keeps the database intact when restore fails.
        if ($isSqlite) {
            $this->pdo()->exec('PRAGMA foreign_keys = OFF');
            $this->pdo()->beginTransaction();
            try {
                foreach ($this->tableNames() as $table) {
                    $this->pdo()->exec('DROP TABLE IF EXISTS ' . $this->quoteIdent((string) $table));
                }
                foreach ($statements as $stmt) {
                    if ($stmt === '' || str_starts_with($stmt, '--')) {
                        continue;
                    }
                    $this->pdo()->exec($stmt);
                }
                $this->pdo()->exec('PRAGMA foreign_keys = ON');
                $this->pdo()->commit();
            } catch (\Throwable $e) {
                if ($this->pdo()->inTransaction()) {
                    $this->pdo()->rollBack();
                }
                $this->pdo()->exec('PRAGMA foreign_keys = ON');
                throw new \RuntimeException('Database restore failed: ' . $e->getMessage(), 0, $e);
            }

            return;
        }

        // MySQL: DDL statements cause implicit commits, so drops cannot be
        // rolled back. Validate first, then disable FK checks, drop, replay.
        if ($isMysql) {
            $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($this->tableNames() as $table) {
                $this->pdo()->exec('DROP TABLE IF EXISTS ' . $this->quoteIdent((string) $table));
            }
        }

        $this->pdo()->beginTransaction();
        try {
            foreach ($statements as $stmt) {
                if ($stmt === '' || str_starts_with($stmt, '--')) {
                    continue;
                }
                $this->pdo()->exec($stmt);
            }

            if ($isMysql) {
                $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            $this->pdo()->commit();
        } catch (\Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            if ($isMysql) {
                $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            throw new \RuntimeException('Database restore failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * PostgreSQL restore. Backups are data-only, so the schema is rebuilt from
     * Phinx migrations first: drop all tables -> migrate -> strip FK constraints ->
     * replay data -> re-add constraints -> sync sequences.
     */
    private function importDatabasePgsql(string $sqlPath): void
    {
        if ($this->runMigrations === null) {
            throw new \RuntimeException('PostgreSQL database restore requires a migration runner (none configured).');
        }

        $sql = file_get_contents($sqlPath);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read SQL dump: {$sqlPath}");
        }

        $pdo = $this->pdo();

        // 1. Drop every table in the current schema (data-only dump carries no DDL)
        foreach ($this->tableNames() as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdent((string) $table) . ' CASCADE');
        }

        // 2. Rebuild the schema for the currently installed codebase
        $output = ($this->runMigrations)();
        $this->logger->info('[Rollback] Schema rebuilt via migrations: ' . trim(preg_replace('/\s+/', ' ', $output) ?? ''));

        // Fresh phinxlog rows would collide with the snapshot's own entries below.
        $logTables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = CURRENT_SCHEMA() AND tablename LIKE '%phinxlog%'")?->fetchAll(\PDO::FETCH_COLUMN) ?? [];
        foreach ($logTables as $logTable) {
            $pdo->exec('DELETE FROM ' . $this->quoteIdent((string) $logTable));
        }

        // 3. Replay the data without FK interference (insert order is alphabetical)
        $constraints = $this->dropForeignKeyConstraints();
        try {
            $statements = $this->splitSqlStatements($sql);
            $pdo->beginTransaction();
            foreach ($statements as $stmt) {
                if ($stmt === '' || str_starts_with($stmt, '--')) {
                    continue;
                }
                $pdo->exec($stmt);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->restoreForeignKeyConstraints($constraints);
            throw new \RuntimeException('Database restore failed: ' . $e->getMessage(), 0, $e);
        }
        $this->restoreForeignKeyConstraints($constraints);

        // 4. Sync serial/identity sequences with the restored rows
        $this->resetSequences();

        $this->logger->info('[Rollback] PostgreSQL data-only restore complete');
    }

    /**
     * Drop every FK constraint in the current schema, returning the statements
     * needed to re-create them.
     *
     * @return list<string>
     */
    private function dropForeignKeyConstraints(): array
    {
        $pdo         = $this->pdo();
        $fromClauses = ' FROM pg_constraint con'
            . ' JOIN pg_class c ON c.oid = con.conrelid'
            . ' JOIN pg_namespace n ON n.oid = c.relnamespace'
            . " WHERE n.nspname = CURRENT_SCHEMA() AND con.contype = 'f'";

        $addStatements = $pdo->query(
            "SELECT 'ALTER TABLE ' || format('%I.%I', n.nspname, c.relname)"
            . " || ' ADD CONSTRAINT ' || con.conname || ' ' || pg_get_constraintdef(con.oid)" . $fromClauses
        )?->fetchAll(\PDO::FETCH_COLUMN) ?? [];

        foreach (
            $pdo->query(
                "SELECT 'ALTER TABLE ' || format('%I.%I', n.nspname, c.relname)"
                . " || ' DROP CONSTRAINT ' || con.conname" . $fromClauses
            )?->fetchAll(\PDO::FETCH_COLUMN) ?? [] as $stmt
        ) {
            $pdo->exec((string) $stmt);
        }

        return array_map(strval(...), $addStatements);
    }

    /** @param list<string> $statements */
    private function restoreForeignKeyConstraints(array $statements): void
    {
        foreach ($statements as $stmt) {
            try {
                $this->pdo()->exec($stmt);
            } catch (\Throwable $e) {
                $this->logger->warning('[Rollback] Could not re-add constraint: ' . $e->getMessage());
            }
        }
    }

    private function resetSequences(): void
    {
        $pdo  = $this->pdo();
        $rows = $pdo->query(
            'SELECT table_name, column_name FROM information_schema.columns'
            . ' WHERE table_schema = CURRENT_SCHEMA()'
            . " AND (column_default LIKE 'nextval%' OR is_identity = 'YES')"
        )?->fetchAll(\PDO::FETCH_ASSOC) ?? [];

        foreach ($rows as $row) {
            $table  = (string) $row['table_name'];
            $column = (string) $row['column_name'];
            $seq    = $pdo->query('SELECT pg_get_serial_sequence(' . $pdo->quote($table) . ', ' . $pdo->quote($column) . ')')?->fetchColumn();
            if ($seq === false || $seq === null) {
                continue;
            }
            $pdo->exec(
                'SELECT setval(' . $pdo->quote((string) $seq)
                . ', COALESCE((SELECT MAX(' . $this->quoteIdent($column) . ') FROM ' . $this->quoteIdent($table) . '), 0) + 1, false)'
            );
        }
    }

    /** Recursively sum file sizes under $dir. */
    private function dirSize(string $dir): int
    {
        $size = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $size += $f->getSize();
            }
        }
        return $size;
    }

    private function pruneOldBackups(): void
    {
        $backups = $this->listBackups();
        if (count($backups) <= self::MAX_BACKUPS) {
            return;
        }

        // listBackups() returns newest first; remove from the end
        $toRemove = array_slice($backups, self::MAX_BACKUPS);
        foreach ($toRemove as $b) {
            $this->logger->info("[Backup] Pruning old backup: {$b['dir']}");
            $this->rmdirRecursive($b['dir']);
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
