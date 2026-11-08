<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

#[CoversNothing]
final class NewsletterControllerCsvTest extends TestCase
{
    private App $app;
    private static string $testDbPath;
    private static ?DbalConnection $db = null;

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vl_newsletter_test_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['APP_KEY']   = bin2hex(random_bytes(32));
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = self::$testDbPath;

        putenv('APP_KEY=' . $_ENV['APP_KEY']);
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . self::$testDbPath);

        $phinxApp = new \Phinx\Console\PhinxApplication();
        $wrapper  = new \Phinx\Wrapper\TextWrapper($phinxApp, [
            'configuration' => dirname(__DIR__, 3) . '/phinx.php',
            'environment'   => 'default',
        ]);
        $wrapper->getMigrate();
        $wrapper->getSeed();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            try {
                self::$db->close();
            } catch (\Throwable) {
            }
            self::$db = null;
        }

        gc_collect_cycles();
        clearstatcache();

        @unlink(self::$testDbPath);
        @unlink(self::$testDbPath . '-wal');
        @unlink(self::$testDbPath . '-shm');
    }

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION['user_id']      = 1;
        $_SESSION['user_role']    = 'super_admin';
        $_SESSION['user']         = [
            'id'       => 1,
            'email'    => 'admin@example.com',
            'role'     => 'super_admin',
            'username' => 'admin',
        ];
        $_SESSION['_csrf_tokens'] = 'test-token';

        /** @var App $app */
        $app       = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $this->app = $app;

        if (self::$db === null) {
            self::$db = $app->getContainer()->get(DbalConnection::class);
        }
    }

    public function testExportSubscribersCsv(): void
    {
        $requestFactory = new RequestFactory();
        $req            = $requestFactory->createRequest('POST', '/admin/newsletter/subscribers/export')
            ->withHeader('X-CSRF-Token', 'test-token')
            ->withParsedBody([
                'export_scope' => 'all',
            ]);

        $res = $this->app->handle($req);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/csv', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="subscribers-', $res->getHeaderLine('Content-Disposition'));

        $csvContent = (string) $res->getBody();
        $this->assertStringContainsString('Email,Name,Status,Tags', $csvContent);
    }

    public function testImportSubscribersCsv(): void
    {
        $csvData = "Email,Name,Tags,Status\n"
            . "test_import1@example.com,Test User 1,\"vip,newsletter\",active\n"
            . "test_import2@example.com,Test User 2,,pending\n";

        $stream       = (new StreamFactory())->createStream($csvData);
        $uploadedFile = new UploadedFile(
            $stream,
            'subscribers.csv',
            'text/csv',
            strlen($csvData),
            UPLOAD_ERR_OK
        );

        $req = (new RequestFactory())->createRequest('POST', '/admin/newsletter/subscribers/import')
            ->withHeader('X-CSRF-Token', 'test-token')
            ->withUploadedFiles(['csv_file' => $uploadedFile])
            ->withParsedBody([
                'default_status' => 'active',
            ]);

        $res = $this->app->handle($req);

        $this->assertSame(302, $res->getStatusCode());

        $row1 = self::$db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'newsletter_subscribers WHERE email = ?',
            ['test_import1@example.com']
        );
        $this->assertNotFalse($row1);
        $this->assertSame('Test User 1', $row1['name']);
        $this->assertSame('active', $row1['status']);

        $tags = self::$db->fetchAllAssociative(
            'SELECT tag FROM ' . VL_DB_PREFIX . 'newsletter_subscriber_tags WHERE subscriber_id = ? ORDER BY tag ASC',
            [$row1['id']]
        );
        $this->assertSame(['newsletter', 'vip'], array_column($tags, 'tag'));

        $row2 = self::$db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'newsletter_subscribers WHERE email = ?',
            ['test_import2@example.com']
        );
        $this->assertNotFalse($row2);
        $this->assertSame('Test User 2', $row2['name']);
        $this->assertSame('pending', $row2['status']);
    }

    public function testImportSubscribersCsvSkipsExistingAndInvalid(): void
    {
        // Seed the pre-existing subscriber here instead of relying on the row
        // left behind by testImportSubscribersCsv — test order must not matter
        // (see executionOrder="depends,defects" in phpunit.xml).
        self::$db->insert(VL_DB_PREFIX . 'newsletter_subscribers', [
            'email'  => 'test_skip_existing@example.com',
            'token'  => 'skip-existing-token-0123456789abcdef',
            'status' => 'active',
        ]);

        $csvData = "test_skip_existing@example.com\n" // Existing (inserted above)
            . "test_import3@example.com\n" // New email
            . "invalid-email-address\n"; // Invalid

        $stream       = (new StreamFactory())->createStream($csvData);
        $uploadedFile = new UploadedFile(
            $stream,
            'emails.csv',
            'text/csv',
            strlen($csvData),
            UPLOAD_ERR_OK
        );

        $req = (new RequestFactory())->createRequest('POST', '/admin/newsletter/subscribers/import')
            ->withHeader('X-CSRF-Token', 'test-token')
            ->withUploadedFiles(['csv_file' => $uploadedFile])
            ->withParsedBody([
                'default_status' => 'active',
            ]);

        $res = $this->app->handle($req);

        $this->assertSame(302, $res->getStatusCode());

        $row3 = self::$db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'newsletter_subscribers WHERE email = ?',
            ['test_import3@example.com']
        );
        $this->assertNotFalse($row3);
        $this->assertSame('active', $row3['status']);

        // The existing row must be untouched by the import.
        $skipped = self::$db->fetchAssociative(
            'SELECT status FROM ' . VL_DB_PREFIX . 'newsletter_subscribers WHERE email = ?',
            ['test_skip_existing@example.com']
        );
        $this->assertNotFalse($skipped);
        $this->assertSame('active', $skipped['status']);
    }
}
