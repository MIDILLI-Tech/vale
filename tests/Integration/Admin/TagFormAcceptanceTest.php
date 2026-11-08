<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;

/**
 * Drives the tag admin forms the way the browser does: POST /admin/tags for
 * the create modal, POST /admin/tags/{id} with `_METHOD=PUT` for the edit
 * modal, both with a CSRF token and the modal fields. The changes must reach
 * the database — `vl_tags` has no `updated_at` column, so the save path must
 * not try to write one.
 */
#[CoversNothing]
final class TagFormAcceptanceTest extends TestCase
{
    private const CSRF_TOKEN = 'tag-form-acceptance-token';

    private App $app;
    private static string $testDbPath;

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vltf_' . bin2hex(random_bytes(8)) . '.sqlite';

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

        /** @var App $app */
        $app       = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $this->app = $app;

        $_SESSION['user_id']          = 1;
        $_SESSION['user_role']        = 'super_admin';
        $_SESSION['user']             = ['id' => 1, 'email' => 'admin@getvale.org', 'role' => 'super_admin'];
        $_SESSION['user_permissions'] = ['*'];
        $_SESSION['_csrf_tokens']     = self::CSRF_TOKEN;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
    }

    private function connection(): DbalConnection
    {
        return $this->app->getContainer()->get(DbalConnection::class);
    }

    /** @param array<string, string> $fields */
    private function postTagForm(string $path, array $fields): ResponseInterface
    {
        $request = (new RequestFactory())
            ->createRequest('POST', $path)
            ->withHeader('X-CSRF-Token', self::CSRF_TOKEN)
            ->withParsedBody($fields);

        return $this->app->handle($request);
    }

    public function testCreatingATagThroughTheAdminFormPersistsIt(): void
    {
        $slug = 'acceptance-new-tag-' . bin2hex(random_bytes(4));

        $response = $this->postTagForm('/admin/tags', [
            'content_type'   => 'post',
            'language'       => 'en',
            'translation_of' => '',
            'name'           => 'Acceptance New Tag',
            'slug'           => $slug,
        ]);

        self::assertSame(302, $response->getStatusCode(), 'The create request should redirect back to the tag list.');
        self::assertStringContainsString('/admin/tags', $response->getHeaderLine('Location'));
        self::assertSame('Tag created successfully.', $_SESSION['flash_success'] ?? null);

        $row = $this->connection()->fetchAssociative(
            'SELECT name, slug, language, content_type FROM ' . VL_DB_PREFIX . 'tags WHERE slug = ?',
            [$slug]
        );

        self::assertIsArray($row, 'The new tag should be stored in the database.');
        self::assertSame('Acceptance New Tag', $row['name']);
        self::assertSame('en', $row['language']);
        self::assertSame('post', $row['content_type']);
    }

    public function testEditingATagThroughTheAdminFormPersistsTheChanges(): void
    {
        $db   = $this->connection();
        $slug = 'acceptance-tag-' . bin2hex(random_bytes(4));

        $db->insert(VL_DB_PREFIX . 'tags', [
            'name'         => 'Acceptance Tag',
            'slug'         => $slug,
            'content_type' => 'post',
            'language'     => 'en',
        ]);
        $id = (int) $db->lastInsertId();

        $response = $this->postTagForm('/admin/tags/' . $id, [
            '_METHOD'        => 'PUT',
            'content_type'   => 'post',
            'language'       => 'en',
            'translation_of' => '',
            'name'           => 'Acceptance Tag Updated',
            'slug'           => $slug . '-updated',
        ]);

        self::assertSame(302, $response->getStatusCode(), 'The update request should redirect back to the tag list.');
        self::assertStringContainsString('/admin/tags', $response->getHeaderLine('Location'));
        self::assertSame('Tag updated successfully.', $_SESSION['flash_success'] ?? null);
        self::assertSame(
            ['name' => 'Acceptance Tag Updated', 'slug' => $slug . '-updated'],
            $db->fetchAssociative('SELECT name, slug FROM ' . VL_DB_PREFIX . 'tags WHERE id = ?', [$id]),
        );
    }
}
