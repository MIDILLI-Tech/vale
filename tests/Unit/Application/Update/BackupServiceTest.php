<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Update;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Update\BackupService;

final class BackupServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/vale_backup_test_' . uniqid();
        mkdir($this->tempDir . '/storage/backups', 0755, true);
        mkdir($this->tempDir . '/public', 0755, true);
        file_put_contents($this->tempDir . '/public/index.php', '<?php echo "hello";');
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    public function testBackupAndRestoreDatabaseWithSemicolonsAndNewlines(): void
    {
        $dbPath = $this->tempDir . '/test.sqlite';
        $conn   = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $pdo    = $conn->getNativeConnection();

        $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT)');
        $css = "p {\n  color: red;\n  font-size: 16px;\n}";
        $js  = "var x = 10;\nvar y = 20;\nif (x > y) { console.log(\"hello\"); }";
        $pdo->exec("INSERT INTO posts (id, title, body) VALUES (1, 'CSS Style', '{$css}')");
        $pdo->exec("INSERT INTO posts (id, title, body) VALUES (2, 'JS Script', '{$js}')");

        $service   = new BackupService($this->tempDir, $conn);
        $backupDir = $service->createBackup('1.0.0', false);

        $this->assertDirectoryExists($backupDir);
        $this->assertFileExists($backupDir . '/database.sql');

        // Alter table data and add a new table post-backup
        $pdo->exec("UPDATE posts SET body = 'modified' WHERE id = 1");
        $pdo->exec("CREATE TABLE new_post_table (id INTEGER PRIMARY KEY, value TEXT)");
        $pdo->exec("INSERT INTO new_post_table (id, value) VALUES (1, 'temp')");

        // Roll back database
        $service->rollback($backupDir, 'database');

        // Verify original data restored
        $stmt  = $pdo->query('SELECT * FROM posts ORDER BY id ASC');
        $rows  = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows[0]['id']);
        $this->assertSame($css, $rows[0]['body']);
        $this->assertSame($js, $rows[1]['body']);

        // Verify post-backup table was removed
        $stmt = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='new_post_table'"
        );
        $this->assertEmpty($stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testListBackupsAndPrune(): void
    {
        $dbPath = $this->tempDir . '/test.sqlite';
        $conn   = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $pdo    = $conn->getNativeConnection();
        $pdo->exec('CREATE TABLE dummy (id INTEGER PRIMARY KEY)');

        $service = new BackupService($this->tempDir, $conn);

        for ($i = 1; $i <= 6; $i++) {
            $service->createBackup("1.0.{$i}", true);
            usleep(100000); // 100ms delay to ensure unique timestamps
        }

        $list = $service->listBackups();
        // MAX_BACKUPS is 5
        $this->assertCount(5, $list);
    }

    public function testDryRunDoesNotCreateBackup(): void
    {
        $dbPath = $this->tempDir . '/test.sqlite';
        $conn   = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $pdo    = $conn->getNativeConnection();
        $pdo->exec('CREATE TABLE dummy (id INTEGER PRIMARY KEY)');

        $service   = new BackupService($this->tempDir, $conn);
        $backupDir = $service->createBackup('1.0.0', false, true);

        $this->assertSame('', $backupDir);
        $this->assertEmpty($service->listBackups());
    }

    public function testSplitSqlStatementsSupportsPostgresDollarQuotesAndSemicolons(): void
    {
        $dbPath  = $this->tempDir . '/test.sqlite';
        $conn    = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $dbPath]);
        $service = new BackupService($this->tempDir, $conn);

        $ref    = new \ReflectionClass($service);
        $method = $ref->getMethod('splitSqlStatements');

        $stmt1 = 'INSERT INTO posts VALUES ($$p { color: red; };$$)';
        $stmt2 = 'INSERT INTO posts VALUES ($tag$var x = 1;$tag$)';
        $sql   = $stmt1 . ";\n"
            . $stmt2 . ";\n"
            . "-- comment line\n"
            . "SELECT * FROM posts;";

        /** @var list<string> $statements */
        $statements = $method->invoke($service, $sql);

        $this->assertCount(3, $statements);
        $this->assertSame($stmt1, $statements[0]);
        $this->assertSame($stmt2, $statements[1]);
        $this->assertSame("SELECT * FROM posts", $statements[2]);
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
