<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ValeApp\Application\Maintenance\ExportImportService;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class ExportImportServiceTest extends TestCase
{
    private Connection $db;
    private ExportImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createSchema();
        $settings      = $this->createStub(SettingsRepositoryInterface::class);
        $this->service = new ExportImportService($this->db, $settings);
    }

    private function createSchema(): void
    {
        $sql = [
            'CREATE TABLE vl_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, name TEXT)',
            'CREATE TABLE vl_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, name TEXT, password TEXT, created_at TEXT)',
            'CREATE TABLE vl_user_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE vl_media (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, path TEXT UNIQUE, filename TEXT)',
            'CREATE TABLE vl_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, content_type TEXT, language TEXT, parent_id INTEGER NULL, translation_of INTEGER NULL, name TEXT)',
            'CREATE TABLE vl_product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, language TEXT, parent_id INTEGER NULL, translation_of INTEGER NULL, name TEXT, image_id INTEGER NULL)',
            'CREATE TABLE vl_tags (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, content_type TEXT, language TEXT, translation_of INTEGER NULL, name TEXT)',
            'CREATE TABLE vl_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, content_type TEXT, language TEXT, author_id INTEGER, title TEXT, body TEXT NULL, featured_image_id INTEGER NULL, parent_id INTEGER NULL, translation_of INTEGER NULL, swap_post_id INTEGER NULL)',
            'CREATE TABLE vl_post_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, category_id INTEGER)',
            'CREATE TABLE vl_post_tags (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, tag_id INTEGER)',
            'CREATE TABLE vl_post_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT NULL)',
            'CREATE TABLE vl_products (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, language TEXT, name TEXT, description TEXT NULL, short_description TEXT NULL, featured_image_id INTEGER NULL, translation_of INTEGER NULL, upsell_ids TEXT NULL, cross_sell_ids TEXT NULL, download_file_id INTEGER NULL)',
            'CREATE TABLE vl_product_variations (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, image_id INTEGER NULL, download_file_id INTEGER NULL, sku TEXT NULL, price TEXT NULL)',
            'CREATE TABLE vl_product_to_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, category_id INTEGER)',
            'CREATE TABLE vl_product_images (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, media_id INTEGER)',
            'CREATE TABLE vl_product_download_files (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, media_id INTEGER)',
            'CREATE TABLE vl_product_variation_download_files (id INTEGER PRIMARY KEY AUTOINCREMENT, variation_id INTEGER, media_id INTEGER)',
            'CREATE TABLE vl_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT, entity_id INTEGER, parent_id INTEGER NULL, author_email TEXT, body TEXT, user_id INTEGER NULL)',
            'CREATE TABLE vl_menus (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, name TEXT, translation_of INTEGER NULL)',
            "CREATE TABLE vl_menu_items (id INTEGER PRIMARY KEY AUTOINCREMENT, menu_id INTEGER, parent_id INTEGER NULL, type TEXT, label TEXT, url TEXT NULL, reference_id INTEGER NULL, sort_order INTEGER DEFAULT 0)",
            'CREATE TABLE vl_forms (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, name TEXT, translation_of INTEGER NULL)',
            'CREATE TABLE vl_form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, form_id INTEGER, data TEXT NULL)',
            'CREATE TABLE vl_coupons (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, allowed_product_ids TEXT NULL, uses_count INTEGER DEFAULT 0)',
            'CREATE TABLE vl_redirects (id INTEGER PRIMARY KEY AUTOINCREMENT, old_path TEXT UNIQUE, new_path TEXT)',
            'CREATE TABLE vl_country_tax_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, country_code TEXT UNIQUE, rate TEXT NULL)',
            'CREATE TABLE vl_membership_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, name TEXT)',
            'CREATE TABLE vl_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_number TEXT UNIQUE, user_id INTEGER NULL, plan_id INTEGER NULL)',
            'CREATE TABLE vl_order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER NULL, variation_id INTEGER NULL, name TEXT)',
            'CREATE TABLE vl_order_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, user_id INTEGER NULL, event TEXT)',
            'CREATE TABLE vl_order_downloads (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER NULL, variation_id INTEGER NULL, source_file_id INTEGER NULL, product_name TEXT, token TEXT)',
            'CREATE TABLE vl_seo_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT, entity_id INTEGER, og_image_id INTEGER NULL)',
            'CREATE TABLE vl_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, group_name TEXT, key TEXT, value TEXT NULL, type TEXT DEFAULT \'string\', is_autoload INTEGER DEFAULT 1, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE vl_content_types (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, name TEXT)',
        ];
        foreach ($sql as $stmt) {
            $this->db->executeStatement($stmt);
        }
    }

    private function seedTarget(): array
    {
        $this->db->insert('vl_roles', ['slug' => 'admin', 'name' => 'Admin']);
        $adminRoleId = (int) $this->db->lastInsertId();
        $this->db->insert('vl_roles', ['slug' => 'editor', 'name' => 'Editor']);
        $editorRoleId = (int) $this->db->lastInsertId();
        $this->db->insert('vl_users', ['email' => 'admin@local.test', 'name' => 'Admin', 'password' => 'x']);
        $adminId = (int) $this->db->lastInsertId();
        $this->db->insert('vl_user_roles', ['user_id' => $adminId, 'role_id' => $adminRoleId]);
        return ['adminRoleId' => $adminRoleId, 'editorRoleId' => $editorRoleId, 'adminId' => $adminId];
    }

    public function testFeaturedImageIdIsRemapped(): void
    {
        $seed = $this->seedTarget();
        $this->db->insert('vl_media', ['user_id' => $seed['adminId'], 'path' => '2026/10/a.jpg', 'filename' => 'a.jpg']);
        $existingMediaId = (int) $this->db->lastInsertId();

        $data = [
            'site_url' => 'https://old.example',
            'data'     => [
                'users' => [
                    ['id' => 5, 'email' => 'author@example.com', 'name' => 'Author', 'password' => 'h', 'role_slugs' => ['editor'], 'roles' => [99]],
                ],
                'media' => [
                    ['id' => 10, 'user_id' => 5, 'path' => '2026/10/a.jpg', 'filename' => 'a.jpg'],
                    ['id' => 11, 'user_id' => 5, 'path' => '2026/10/b.jpg', 'filename' => 'b.jpg'],
                ],
                'categories' => ['content' => [], 'product' => []],
                'tags'       => [],
                'posts'      => [
                    ['id' => 20, 'slug' => 'hello', 'content_type' => 'post', 'language' => 'en', 'author_id' => 5, 'title' => 'Hello', 'body' => 'x', 'featured_image_id' => 11, 'categories' => [], 'tags' => [], 'meta' => []],
                ],
                'products' => [
                    ['id' => 30, 'slug' => 'widget', 'language' => 'en', 'name' => 'Widget', 'featured_image_id' => 11, 'variations' => [], 'categories' => [], 'images' => []],
                ],
                'seo_meta' => [
                    ['id' => 1, 'entity_type' => 'post', 'entity_id' => 20, 'og_image_id' => 11],
                ],
            ],
        ];

        $result = $this->service->import($data);
        self::assertTrue($result['success']);

        $newMediaId = $this->db->fetchOne('SELECT id FROM vl_media WHERE path = ?', ['2026/10/b.jpg']);
        self::assertNotFalse($newMediaId);

        $postImg = $this->db->fetchOne('SELECT featured_image_id FROM vl_posts WHERE slug = ?', ['hello']);
        self::assertEquals((int) $newMediaId, (int) $postImg);

        $productImg = $this->db->fetchOne('SELECT featured_image_id FROM vl_products WHERE slug = ?', ['widget']);
        self::assertEquals((int) $newMediaId, (int) $productImg);

        // Pre-existing media row was reused, existing id kept
        $postForExisting = $existingMediaId;
        self::assertNotEquals($postForExisting, (int) $newMediaId);
    }

    public function testCategoryParentHierarchyIsRemapped(): void
    {
        $this->seedTarget();
        $data = [
            'site_url' => 'https://old.example',
            'data'     => [
                'categories' => [
                    'content' => [
                        ['id' => 1, 'slug' => 'parent', 'content_type' => 'post', 'language' => 'en', 'name' => 'Parent', 'parent_id' => null],
                        ['id' => 2, 'slug' => 'child', 'content_type' => 'post', 'language' => 'en', 'name' => 'Child', 'parent_id' => 1],
                    ],
                    'product' => [],
                ],
            ],
        ];
        $result = $this->service->import($data);
        self::assertTrue($result['success']);
        $parentId    = (int) $this->db->fetchOne('SELECT id FROM vl_categories WHERE slug = ?', ['parent']);
        $childParent = (int) $this->db->fetchOne('SELECT parent_id FROM vl_categories WHERE slug = ?', ['child']);
        self::assertSame($parentId, $childParent);
    }

    public function testRolesAreMappedBySlugNotRawId(): void
    {
        $seed = $this->seedTarget();
        $data = [
            'data' => [
                // Roles are matched by slug, so differing IDs between instances are fine.
                'users' => [
                    ['id' => 7, 'email' => 'ed@example.com', 'name' => 'Ed', 'password' => 'h', 'role_slugs' => ['editor']],
                ],
            ],
        ];
        $result = $this->service->import($data);
        self::assertTrue($result['success']);
        $userId  = (int) $this->db->fetchOne('SELECT id FROM vl_users WHERE email = ?', ['ed@example.com']);
        $roleIds = $this->db->fetchFirstColumn('SELECT role_id FROM vl_user_roles WHERE user_id = ?', [$userId]);
        self::assertSame([$seed['editorRoleId']], array_map(intval(...), $roleIds));
    }

    public function testSecretSettingsSkippedByDefault(): void
    {
        $this->seedTarget();
        $data = [
            'data' => [
                'site_settings' => [
                    ['group_name' => 'general', 'key' => 'site_name', 'value' => 'Clone', 'type' => 'string'],
                    ['group_name' => 'payment', 'key' => 'stripe_secret_key', 'value' => 'sk_live_123', 'type' => 'string'],
                    ['group_name' => 'cron', 'key' => 'cron_secret', 'value' => 'abc', 'type' => 'string'],
                ],
            ],
        ];
        $result = $this->service->import($data);
        self::assertTrue($result['success']);
        self::assertSame('Clone', $this->db->fetchOne('SELECT value FROM vl_settings WHERE key = ?', ['site_name']));
        self::assertFalse($this->db->fetchOne('SELECT id FROM vl_settings WHERE key = ?', ['stripe_secret_key']));
        self::assertNotEmpty($result['warnings']);

        $result2 = $this->service->import($data, false, ['include_secrets' => true]);
        self::assertTrue($result2['success']);
        self::assertSame('sk_live_123', $this->db->fetchOne('SELECT value FROM vl_settings WHERE key = ?', ['stripe_secret_key']));
    }

    public function testOldSiteUrlIsRewrittenInImportedContent(): void
    {
        $this->seedTarget();
        $data = [
            'site_url' => 'https://old.example',
            'data'     => [
                'users' => [
                    ['id' => 5, 'email' => 'a@example.com', 'name' => 'A', 'password' => 'h', 'role_slugs' => [], 'roles' => []],
                ],
                'posts' => [
                    ['id' => 20, 'slug' => 'p1', 'content_type' => 'post', 'language' => 'en', 'author_id' => 5, 'title' => 'P1', 'body' => '<img src="https://old.example/uploads/x.jpg">', 'categories' => [], 'tags' => [], 'meta' => []],
                ],
            ],
        ];
        putenv('APP_URL=https://new.example');
        $_ENV['APP_URL']    = 'https://new.example';
        $_SERVER['APP_URL'] = 'https://new.example';
        try {
            $result = $this->service->import($data);
        } finally {
            putenv('APP_URL=http://localhost');
            $_ENV['APP_URL']    = 'http://localhost';
            $_SERVER['APP_URL'] = 'http://localhost';
        }
        self::assertTrue($result['success']);
        $body = (string) $this->db->fetchOne('SELECT body FROM vl_posts WHERE slug = ?', ['p1']);
        self::assertStringContainsString('https://new.example/uploads/x.jpg', $body);
    }

    public function testMenuReferenceAndParentRemapped(): void
    {
        $this->seedTarget();
        $data = [
            'data' => [
                'users' => [
                    ['id' => 5, 'email' => 'a@example.com', 'name' => 'A', 'password' => 'h', 'role_slugs' => [], 'roles' => []],
                ],
                'pages' => [
                    ['id' => 100, 'slug' => 'about', 'content_type' => 'page', 'language' => 'en', 'author_id' => 5, 'title' => 'About', 'body' => '', 'categories' => [], 'tags' => [], 'meta' => []],
                ],
                'menus' => [
                    ['id' => 10, 'slug' => 'main', 'name' => 'Main', 'items' => [
                        ['id' => 1000, 'type' => 'page', 'label' => 'About', 'url' => '/about', 'reference_id' => 100, 'parent_id' => null, 'sort_order' => 0],
                        ['id' => 1001, 'type' => 'custom', 'label' => 'Sub', 'url' => 'https://example.com', 'reference_id' => null, 'parent_id' => 1000, 'sort_order' => 1],
                    ]],
                ],
            ],
        ];
        $result = $this->service->import($data);
        self::assertTrue($result['success']);
        $pageId = (int) $this->db->fetchOne('SELECT id FROM vl_posts WHERE slug = ?', ['about']);
        $refId  = (int) $this->db->fetchOne("SELECT reference_id FROM vl_menu_items WHERE label = ?", ['About']);
        self::assertSame($pageId, $refId);
        $parentLink  = $this->db->fetchOne("SELECT parent_id FROM vl_menu_items WHERE label = ?", ['Sub']);
        $aboutItemId = (int) $this->db->fetchOne("SELECT id FROM vl_menu_items WHERE label = ?", ['About']);
        self::assertEquals($aboutItemId, (int) $parentLink);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->seedTarget();
        $before = (int) $this->db->fetchOne('SELECT COUNT(*) FROM vl_users');
        $data   = [
            'data' => [
                'users' => [
                    ['id' => 9, 'email' => 'dry@example.com', 'name' => 'Dry', 'password' => 'h', 'role_slugs' => [], 'roles' => []],
                ],
            ],
        ];
        $result = $this->service->import($data, true);
        self::assertTrue($result['success']);
        self::assertSame($before, (int) $this->db->fetchOne('SELECT COUNT(*) FROM vl_users'));
        self::assertSame(1, $result['imported']['users']);
    }

    public function testExportStripsSecretsByDefault(): void
    {
        $this->seedTarget();
        $this->db->insert('vl_settings', ['group_name' => 'general', 'key' => 'site_name', 'value' => 'Shop']);
        $this->db->insert('vl_settings', ['group_name' => 'payment', 'key' => 'stripe_secret_key', 'value' => 'sk_live']);

        $exported = $this->service->export(['site_settings']);
        $keys     = array_column($exported['data']['site_settings'], 'key');
        self::assertContains('site_name', $keys);
        self::assertNotContains('stripe_secret_key', $keys);
        self::assertSame(1, $exported['secrets_stripped']);
    }

    public function testExportKeepsSecretsWhenOptedIn(): void
    {
        $this->seedTarget();
        $this->db->insert('vl_settings', ['group_name' => 'payment', 'key' => 'stripe_secret_key', 'value' => 'sk_live']);

        $exported = $this->service->export(['site_settings'], ['include_secrets' => true]);
        $keys     = array_column($exported['data']['site_settings'], 'key');
        self::assertContains('stripe_secret_key', $keys);
        self::assertSame(0, $exported['secrets_stripped']);
    }
}
