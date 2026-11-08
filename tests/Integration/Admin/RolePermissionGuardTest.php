<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\RequestFactory;

#[CoversNothing]
final class RolePermissionGuardTest extends TestCase
{
    private App $app;
    private static string $testDbPath;

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vlg_' . bin2hex(random_bytes(8)) . '.sqlite';

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
    }

    /** @param string[] $permissions */
    private function loginAsEditor(array $permissions): void
    {
        $editor = ['id' => 99, 'email' => 'editor@example.com', 'role' => 'editor'];

        $_SESSION['user_id']          = 99;
        $_SESSION['user_role']        = 'editor';
        $_SESSION['user']             = $editor;
        $_SESSION['user_permissions'] = $permissions;
        $_SESSION['_csrf_tokens']     = 'guard-test-csrf-token';
        unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['_flash']);
    }

    private function postStatus(string $status, array $permissions): int
    {
        $this->loginAsEditor($permissions);
        $request = (new RequestFactory())->createRequest('POST', '/admin/posts')
            ->withHeader('X-CSRF-Token', 'guard-test-csrf-token')
            ->withParsedBody([
                'title'        => 'Guard Test',
                'slug'         => 'guard-test-' . $status,
                'body'         => 'Body',
                'status'       => $status,
                'content_type' => 'post',
                'language'     => 'en',
            ]);

        return $this->app->handle($request)->getStatusCode();
    }

    public function testStoreScheduledRequiresPublishPermission(): void
    {
        $code = $this->postStatus('scheduled', ['posts.list', 'posts.create', 'posts.edit']);
        self::assertSame(403, $code);
    }

    public function testStoreUnlistedRequiresPublishPermission(): void
    {
        $code = $this->postStatus('unlisted', ['posts.list', 'posts.create', 'posts.edit']);
        self::assertSame(403, $code);
    }

    public function testStoreScheduledAllowedWithPublishPermission(): void
    {
        $perms = ['posts.list', 'posts.create', 'posts.edit', 'posts.publish'];
        self::assertNotEquals(403, $this->postStatus('scheduled', $perms));
    }

    public function testPostFormHidesPublishStatesWithoutPublishPermission(): void
    {
        $this->loginAsEditor(['posts.list', 'posts.create']);
        $request  = (new RequestFactory())->createRequest('GET', '/admin/posts/create');
        $response = $this->app->handle($request);
        self::assertLessThan(400, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringNotContainsString('value="published"', $body);
        self::assertStringNotContainsString('value="unlisted"', $body);
        self::assertStringNotContainsString('value="archived"', $body);

        $this->loginAsEditor(['posts.list', 'posts.create', 'posts.publish']);
        $request  = (new RequestFactory())->createRequest('GET', '/admin/posts/create');
        $response = $this->app->handle($request);
        self::assertLessThan(400, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('value="published"', $body);
        self::assertStringContainsString('value="unlisted"', $body);
        self::assertStringContainsString('value="archived"', $body);
    }

    public function testFormsIndexRequiresFormsManage(): void
    {
        $this->loginAsEditor(['posts.list']);
        $request  = (new RequestFactory())->createRequest('GET', '/admin/forms');
        $response = $this->app->handle($request);
        self::assertSame(403, $response->getStatusCode());

        $this->loginAsEditor(['posts.list', 'forms.manage']);
        $request  = (new RequestFactory())->createRequest('GET', '/admin/forms');
        $response = $this->app->handle($request);
        self::assertLessThan(400, $response->getStatusCode());
    }

    public function testNewsletterCampaignsRequireNewsletterManage(): void
    {
        $this->loginAsEditor(['posts.list']);
        $request  = (new RequestFactory())->createRequest('GET', '/admin/newsletter/campaigns');
        $response = $this->app->handle($request);
        self::assertSame(403, $response->getStatusCode());

        $this->loginAsEditor(['posts.list', 'newsletter.manage']);
        $request  = (new RequestFactory())->createRequest('GET', '/admin/newsletter/campaigns');
        $response = $this->app->handle($request);
        self::assertLessThan(400, $response->getStatusCode());
    }

    public function testFormsListJsonStaysAccessibleToContentEditors(): void
    {
        // The block editor embed picker fetches this endpoint while editing posts.
        $this->loginAsEditor(['posts.list', 'posts.create', 'posts.edit']);
        $request  = (new RequestFactory())->createRequest('GET', '/admin/forms/list-json');
        $response = $this->app->handle($request);
        self::assertLessThan(400, $response->getStatusCode());
    }

    public function testNewPermissionsSeededForContentRoles(): void
    {
        $db     = $this->app->getContainer()->get(DbalConnection::class);
        $prefix = getenv('DB_PREFIX') ?: 'vl_';

        foreach (['forms.manage', 'newsletter.manage'] as $slug) {
            $sql  = "SELECT id FROM {$prefix}permissions WHERE slug = ?";
            $perm = $db->fetchAssociative($sql, [$slug]);
            self::assertNotFalse($perm, "Permission '{$slug}' should exist after migrate+seed.");

            foreach (['super_admin', 'admin', 'editor'] as $role) {
                $row = $db->fetchAssociative(
                    "SELECT rp.role_id FROM {$prefix}role_permissions rp"
                    . " JOIN {$prefix}roles r ON r.id = rp.role_id"
                    . ' WHERE r.slug = ? AND rp.permission_id = ?',
                    [$role, $perm['id']],
                );
                self::assertNotFalse($row, "Role '{$role}' should hold '{$slug}'.");
            }

            $author = $db->fetchAssociative(
                "SELECT rp.role_id FROM {$prefix}role_permissions rp"
                . " JOIN {$prefix}roles r ON r.id = rp.role_id"
                . ' WHERE r.slug = ? AND rp.permission_id = ?',
                ['author', $perm['id']],
            );
            self::assertFalse($author, "Role 'author' should NOT hold '{$slug}'.");
        }
    }

    private function seedGuardPost(string $contentType, int $authorId, string $slug): int
    {
        $db     = $this->app->getContainer()->get(DbalConnection::class);
        $prefix = getenv('DB_PREFIX') ?: 'vl_';

        $db->insert("{$prefix}posts", [
            'content_type' => $contentType,
            'language'     => 'en',
            'author_id'    => $authorId,
            'title'        => 'Guard Clone Source',
            'slug'         => $slug,
            'status'       => 'published',
        ]);

        return (int) $db->lastInsertId();
    }

    public function testPostCloneRequiresEditRights(): void
    {
        $id = $this->seedGuardPost('post', 1, 'guard-clone-src');

        $this->loginAsEditor(['posts.list']);
        $request = (new RequestFactory())->createRequest('POST', '/admin/posts/' . $id . '/clone')
            ->withHeader('X-CSRF-Token', 'guard-test-csrf-token');

        self::assertSame(403, $this->app->handle($request)->getStatusCode());
    }

    public function testPostCloneDuplicatesAsDraft(): void
    {
        $id = $this->seedGuardPost('post', 99, 'guard-clone-ok');

        $this->loginAsEditor(['posts.list']);
        $request = (new RequestFactory())->createRequest('POST', '/admin/posts/' . $id . '/clone')
            ->withHeader('X-CSRF-Token', 'guard-test-csrf-token');

        $response = $this->app->handle($request);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/admin/posts/', $response->getHeaderLine('Location'));

        $db     = $this->app->getContainer()->get(DbalConnection::class);
        $prefix = getenv('DB_PREFIX') ?: 'vl_';
        $copy   = $db->fetchAssociative(
            "SELECT * FROM {$prefix}posts WHERE slug = ?",
            ['guard-clone-ok-copy'],
        );
        self::assertNotFalse($copy);
        self::assertSame('draft', $copy['status']);
    }

    public function testPageCloneRedirectsToPageEditor(): void
    {
        $id = $this->seedGuardPost('page', 99, 'guard-clone-page');

        $this->loginAsEditor(['pages.create']);
        $request = (new RequestFactory())->createRequest('POST', '/admin/pages/' . $id . '/clone')
            ->withHeader('X-CSRF-Token', 'guard-test-csrf-token');

        $response = $this->app->handle($request);
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/admin/pages/', $response->getHeaderLine('Location'));
    }
}
