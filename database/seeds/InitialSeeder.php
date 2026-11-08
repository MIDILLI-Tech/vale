<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

class InitialSeeder extends AbstractSeed
{
    public function run(): void
    {
        $prefix = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        // ── Roles ──────────────────────────────────────────
        $roles    = $this->table($prefix . 'roles');
        $roleData = [
            ['slug' => 'super_admin', 'name' => 'Super Admin', 'description' => 'Full system access', 'is_system' => true],
            ['slug' => 'admin', 'name' => 'Admin', 'description' => 'Site administration', 'is_system' => true],
            ['slug' => 'editor', 'name' => 'Editor', 'description' => 'Content management', 'is_system' => true],
            ['slug' => 'shop_manager', 'name' => 'Shop Manager', 'description' => 'E-commerce management', 'is_system' => true],
            ['slug' => 'author', 'name' => 'Author', 'description' => 'Create and manage own content', 'is_system' => true],
            ['slug' => 'subscriber', 'name' => 'Subscriber', 'description' => 'Basic access', 'is_system' => true],
        ];
        foreach ($roleData as $role) {
            $existingRole = $this->fetchRow(
                "SELECT id FROM {$prefix}roles WHERE slug = '" . $role['slug'] . "' LIMIT 1"
            );
            if (!$existingRole) {
                $roles->insert([$role])->saveData();
            }
        }

        // ── Permissions ────────────────────────────────────
        $permissions = $this->table($prefix . 'permissions');
        $permData    = [
                ['slug' => 'users.list', 'name' => 'List Users', 'group_name' => 'users'],
                ['slug' => 'users.create', 'name' => 'Create Users', 'group_name' => 'users'],
                ['slug' => 'users.edit', 'name' => 'Edit Users', 'group_name' => 'users'],
                ['slug' => 'users.delete', 'name' => 'Delete Users', 'group_name' => 'users'],
                ['slug' => 'roles.manage', 'name' => 'Manage Roles', 'group_name' => 'users'],
                ['slug' => 'posts.list', 'name' => 'List Posts', 'group_name' => 'content'],
                ['slug' => 'posts.create', 'name' => 'Create Posts', 'group_name' => 'content'],
                ['slug' => 'posts.edit', 'name' => 'Edit Posts', 'group_name' => 'content'],
                ['slug' => 'posts.edit_others', 'name' => 'Edit Others Posts', 'group_name' => 'content'],
                ['slug' => 'posts.delete', 'name' => 'Delete Posts', 'group_name' => 'content'],
                ['slug' => 'posts.publish', 'name' => 'Publish Posts', 'group_name' => 'content'],
                ['slug' => 'pages.list', 'name' => 'List Pages', 'group_name' => 'content'],
                ['slug' => 'pages.create', 'name' => 'Create Pages', 'group_name' => 'content'],
                ['slug' => 'pages.edit', 'name' => 'Edit Pages', 'group_name' => 'content'],
                ['slug' => 'pages.delete', 'name' => 'Delete Pages', 'group_name' => 'content'],
                ['slug' => 'pages.publish', 'name' => 'Publish Pages', 'group_name' => 'content'],
                ['slug' => 'media.list', 'name' => 'List Media', 'group_name' => 'media'],
                ['slug' => 'media.upload', 'name' => 'Upload Media', 'group_name' => 'media'],
                ['slug' => 'media.delete', 'name' => 'Delete Media', 'group_name' => 'media'],
                ['slug' => 'products.list', 'name' => 'List Products', 'group_name' => 'shop'],
                ['slug' => 'products.create', 'name' => 'Create Products', 'group_name' => 'shop'],
                ['slug' => 'products.edit', 'name' => 'Edit Products', 'group_name' => 'shop'],
                ['slug' => 'products.delete', 'name' => 'Delete Products', 'group_name' => 'shop'],
                ['slug' => 'orders.list', 'name' => 'List Orders', 'group_name' => 'shop'],
                ['slug' => 'orders.edit', 'name' => 'Edit Orders', 'group_name' => 'shop'],
                ['slug' => 'orders.delete', 'name' => 'Delete Orders', 'group_name' => 'shop'],
                ['slug' => 'settings.view', 'name' => 'View Settings', 'group_name' => 'settings'],
                ['slug' => 'settings.edit', 'name' => 'Edit Settings', 'group_name' => 'settings'],
                ['slug' => 'forms.manage', 'name' => 'Manage Forms', 'group_name' => 'forms'],
                ['slug' => 'newsletter.manage', 'name' => 'Manage Newsletter', 'group_name' => 'newsletter'],
                ['slug' => 'system.cache', 'name' => 'Manage Cache', 'group_name' => 'system'],
                ['slug' => 'system.plugins', 'name' => 'Manage Plugins', 'group_name' => 'system'],
                ['slug' => 'system.themes', 'name' => 'Manage Themes', 'group_name' => 'system'],
        ];

        foreach ($permData as $permission) {
            $existingPermission = $this->fetchRow(
                "SELECT id FROM {$prefix}permissions WHERE slug = '" . $permission['slug'] . "' LIMIT 1"
            );
            if (!$existingPermission) {
                $permissions->insert([$permission])->saveData();
            }
        }

        // ── Role -> Permission assignments ─────────────────
        $now = date('Y-m-d H:i:s');

        // super_admin + admin receive every permission
        $this->execute(
            "INSERT INTO {$prefix}role_permissions (role_id, permission_id, created_at)
            SELECT r.id, p.id, '{$now}'
            FROM {$prefix}roles r
            CROSS JOIN {$prefix}permissions p
            WHERE r.slug IN ('super_admin', 'admin')
              AND NOT EXISTS (
                  SELECT 1 FROM {$prefix}role_permissions rp
                  WHERE rp.role_id = r.id AND rp.permission_id = p.id
              )"
        );

        // Baseline matrix for content/shop roles (adjustable in Admin -> Roles)
        $matrix = [
            'editor' => [
                'posts.list', 'posts.create', 'posts.edit', 'posts.edit_others', 'posts.delete', 'posts.publish',
                'pages.list', 'pages.create', 'pages.edit', 'pages.delete', 'pages.publish',
                'media.list', 'media.upload', 'media.delete',
                'forms.manage', 'newsletter.manage',
            ],
            'shop_manager' => [
                'products.list', 'products.create', 'products.edit', 'products.delete',
                'orders.list', 'orders.edit', 'orders.delete',
            ],
            'author' => [
                'posts.list', 'posts.create', 'posts.edit',
                'media.list', 'media.upload',
            ],
        ];

        foreach ($matrix as $roleSlug => $permSlugs) {
            $placeholders = implode(',', array_fill(0, count($permSlugs), '?'));
            $this->execute(
                "INSERT INTO {$prefix}role_permissions (role_id, permission_id, created_at)
                SELECT r.id, p.id, '{$now}'
                FROM {$prefix}roles r
                JOIN {$prefix}permissions p ON p.slug IN ({$placeholders})
                WHERE r.slug = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM {$prefix}role_permissions rp
                      WHERE rp.role_id = r.id AND rp.permission_id = p.id
                  )",
                array_merge($permSlugs, [$roleSlug]),
            );
        }

        // ── Default Settings ───────────────────────────────
        $settingsCount = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}settings");
        if ((int) ($settingsCount['count'] ?? 0) === 0) {
            $settingsData = [
                ['group_name' => 'general', 'key' => 'site_name', 'value' => 'Vale', 'type' => 'string', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'site_description', 'value' => 'A blazing-fast, lightweight CMS', 'type' => 'string', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'admin_email', 'value' => 'admin@example.com', 'type' => 'string', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'default_locale', 'value' => 'en', 'type' => 'string', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'timezone', 'value' => 'UTC', 'type' => 'string', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'blog_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'shop_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'registration_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'pages_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'newsletter_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'form_builder_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'seo_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'analytics_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'general', 'key' => 'custom_content_types_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'content', 'key' => 'posts_per_page', 'value' => '10', 'type' => 'integer', 'is_autoload' => true],
                ['group_name' => 'content', 'key' => 'comments_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'content', 'key' => 'revisions_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'content', 'key' => 'revisions_max', 'value' => '10', 'type' => 'integer', 'is_autoload' => true],
                ['group_name' => 'content', 'key' => 'reading_time_wpm', 'value' => '200', 'type' => 'integer', 'is_autoload' => true],
                ['group_name' => 'content', 'key' => 'multi_language_content', 'value' => '0', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'content', 'key' => 'content_languages', 'value' => '', 'type' => 'string', 'is_autoload' => true],
                ['group_name' => 'content', 'key' => 'i18n_display_originals_fallback', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'content', 'key' => 'i18n_fallback_content_notice', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'ecommerce', 'key' => 'currency', 'value' => 'EUR', 'type' => 'string', 'is_autoload' => true],
                ['group_name' => 'ecommerce', 'key' => 'shop_country', 'value' => 'DE', 'type' => 'string', 'is_autoload' => true],
                ['group_name' => 'ecommerce', 'key' => 'tax_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'ecommerce', 'key' => 'subscriptions_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'seo', 'key' => 'auto_redirect_enabled', 'value' => '1', 'type' => 'boolean', 'is_autoload' => true],
                ['group_name' => 'cron', 'key' => 'cron_mode', 'value' => 'page_load', 'type' => 'string', 'is_autoload' => true],
                ['group_name' => 'cron', 'key' => 'cron_interval', 'value' => '5', 'type' => 'string', 'is_autoload' => true],
            ];
            $this->table($prefix . 'settings')->insert($settingsData)->saveData();
        }

        // ── Content Types ──────────────────────────────────
        $ctCount = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}content_types");
        if ((int) ($ctCount['count'] ?? 0) === 0) {
            $contentTypes = $this->table($prefix . 'content_types');
            $ctData       = [
                [
                    'slug'        => 'post',
                    'name'        => 'Post',
                    'description' => 'Blog posts',
                    'icon'        => 'document-text',
                    'supports'    => json_encode(['title', 'editor', 'thumbnail', 'excerpt', 'author', 'comments', 'revisions', 'seo', 'categories', 'tags', 'i18n']),
                    'is_system'   => true,
                    'created_at'  => date('Y-m-d H:i:s'),
                ],
                [
                    'slug'        => 'page',
                    'name'        => 'Page',
                    'description' => 'Static pages',
                    'icon'        => 'document-text',
                    'supports'    => json_encode(['title', 'editor', 'thumbnail', 'revisions', 'seo', 'i18n', 'scheduled_swap']),
                    'is_system'   => true,
                    'created_at'  => date('Y-m-d H:i:s'),
                ],
            ];
            $contentTypes->insert($ctData)->saveData();
        }
    }
}
