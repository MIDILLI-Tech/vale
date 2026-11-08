<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;
use ValeApp\Infrastructure\Security\Encrypter;

class DemoSeeder extends AbstractSeed
{
    /** @var Encrypter|null No-op when APP_KEY is invalid or absent. */
    private ?Encrypter $encrypter = null;

    public function run(): void
    {
        $prefix = $this->getAdapter()->getOption('db_prefix') ?? 'vl_';

        // Encrypt PII in demo billing data only when ENCRYPT_PII=true AND a
        // usable APP_KEY is present. Off by default; without a key we fall back
        // to plaintext so the seeder never crashes, but surface a notice so the
        // operator knows the demo data is not encrypted.
        if (env('ENCRYPT_PII', false)) {
            $appKey = (string) ($_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? getenv('APP_KEY'));
            if ($appKey !== '') {
                try {
                    $this->encrypter = new Encrypter($appKey);
                } catch (\Throwable $e) {
                    $this->notice('ENCRYPT_PII is true but APP_KEY is invalid — demo order addresses will be stored in plaintext. (' . $e->getMessage() . ')');
                }
            } else {
                $this->notice('ENCRYPT_PII is true but APP_KEY is not set — demo order addresses will be stored in plaintext. Set APP_KEY in .env before running db:seed to encrypt PII.');
            }
        }

        // 1. Ensure InitialSeeder is executed if roles/settings do not exist
        $rolesCount = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}roles");
        if ((int) ($rolesCount['count'] ?? 0) === 0) {
            if (!class_exists('InitialSeeder', false)) {
                require_once __DIR__ . '/InitialSeeder.php';
            }
            $initialSeeder = new \InitialSeeder();
            $initialSeeder->setAdapter($this->getAdapter());
            $initialSeeder->run();
        }

        // Enable e-commerce in settings if disabled
        $driver = $this->getAdapter()->getOptions()['adapter'] ?? '';
        $q      = $driver === 'mysql' ? '`' : '"';
        $this->execute(
            "UPDATE {$prefix}settings SET {$q}value{$q} = '1' WHERE {$q}group_name{$q} = 'general' AND {$q}key{$q} = 'shop_enabled'"
        );

        // Enable subscriptions (demo seeds membership plans + menu link)
        $this->execute(
            "UPDATE {$prefix}settings SET {$q}value{$q} = '1' WHERE {$q}group_name{$q} = 'ecommerce' AND {$q}key{$q} = 'subscriptions_enabled'"
        );

        // Enable multi-language with EN + TR and fallback notice
        $this->execute(
            "UPDATE {$prefix}settings SET {$q}value{$q} = '1' WHERE {$q}group_name{$q} = 'content' AND {$q}key{$q} = 'multi_language_content'"
        );
        $this->execute(
            "UPDATE {$prefix}settings SET {$q}value{$q} = 'en,tr' WHERE {$q}group_name{$q} = 'content' AND {$q}key{$q} = 'content_languages'"
        );
        $this->execute(
            "UPDATE {$prefix}settings SET {$q}value{$q} = '1' WHERE {$q}group_name{$q} = 'content' AND {$q}key{$q} = 'i18n_display_originals_fallback'"
        );
        $this->execute(
            "UPDATE {$prefix}settings SET {$q}value{$q} = '1' WHERE {$q}group_name{$q} = 'content' AND {$q}key{$q} = 'i18n_fallback_content_notice'"
        );

        // Set login/register captcha to honeypot
        $this->execute(
            "UPDATE {$prefix}settings SET {$q}value{$q} = 'honeypot' WHERE {$q}group_name{$q} = 'registration' AND {$q}key{$q} = 'login_captcha_type'"
        );
        $this->execute(
            "UPDATE {$prefix}settings SET {$q}value{$q} = 'honeypot' WHERE {$q}group_name{$q} = 'registration' AND {$q}key{$q} = 'register_captcha_type'"
        );

        $now          = date('Y-m-d H:i:s');
        $passwordHash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);

        // 2. Sample Users
        $usersTable = $this->table($prefix . 'users');

        // Find existing admin or super_admin user with the smallest ID
        $existingAdmin = $this->fetchRow(
            "SELECT u.id FROM {$prefix}users u "
            . "INNER JOIN {$prefix}user_roles ur ON u.id = ur.user_id "
            . "INNER JOIN {$prefix}roles r ON ur.role_id = r.id "
            . "WHERE r.slug IN ('super_admin', 'admin') "
            . "ORDER BY u.id ASC LIMIT 1"
        );

        // Fallback: adopt the very first user as the demo author
        $fallbackAdopted = false;
        if (!$existingAdmin) {
            $existingAdmin   = $this->fetchRow("SELECT id FROM {$prefix}users ORDER BY id ASC LIMIT 1");
            $fallbackAdopted = $existingAdmin !== false;
        }

        $adminId = $existingAdmin['id'] ?? null;

        if (!$adminId) {
            $usersTable->insert([
                [
                    'email'             => 'admin@getvale.org',
                    'password'          => $passwordHash,
                    'name'              => 'Demo Admin',
                    'locale'            => 'en',
                    'is_active'         => true,
                    'email_verified_at' => $now,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
            ])->saveData();
            $adminId = (int) $this->getAdapter()->getConnection()->lastInsertId();

            $superAdminRole = $this->fetchRow("SELECT id FROM {$prefix}roles WHERE slug = 'super_admin'");
            if ($superAdminRole) {
                $this->table($prefix . 'user_roles')->insert([
                    ['user_id' => $adminId, 'role_id' => $superAdminRole['id'], 'created_at' => $now],
                ])->saveData();
            }
        } elseif ($fallbackAdopted) {
            // Adopted user had no admin privileges — grant the admin role so demo content has an owner
            $adoptedId    = (int) $adminId;
            $hasAdminRole = $this->fetchRow(
                "SELECT COUNT(*) as count FROM {$prefix}user_roles ur "
                . "INNER JOIN {$prefix}roles r ON ur.role_id = r.id "
                . "WHERE ur.user_id = {$adoptedId} AND r.slug IN ('super_admin', 'admin')"
            );
            if ((int) ($hasAdminRole['count'] ?? 0) === 0) {
                $adminRole = $this->fetchRow("SELECT id FROM {$prefix}roles WHERE slug = 'admin'");
                if ($adminRole) {
                    $this->table($prefix . 'user_roles')->insert([
                        ['user_id' => $adoptedId, 'role_id' => $adminRole['id'], 'created_at' => $now],
                    ])->saveData();
                }
            }
        }

        $existingUser     = $this->fetchRow("SELECT id FROM {$prefix}users WHERE email = 'user@example.com'");
        $subscriberUserId = $existingUser['id'] ?? null;

        if (!$subscriberUserId) {
            $usersTable->insert([
                [
                    'email'             => 'user@example.com',
                    'password'          => $passwordHash,
                    'name'              => 'Jane Subscriber',
                    'locale'            => 'en',
                    'is_active'         => true,
                    'email_verified_at' => $now,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
            ])->saveData();
            $subscriberUserId = (int) $this->getAdapter()->getConnection()->lastInsertId();

            $subRole = $this->fetchRow("SELECT id FROM {$prefix}roles WHERE slug = 'subscriber'");
            if ($subRole) {
                $this->table($prefix . 'user_roles')->insert([
                    ['user_id' => $subscriberUserId, 'role_id' => $subRole['id'], 'created_at' => $now],
                ])->saveData();
            }
        }

        // 3. Blog Categories & Tags
        $categoriesTable = $this->table($prefix . 'categories');
        $techCat         = $this->fetchRow("SELECT id FROM {$prefix}categories WHERE slug = 'technology' AND content_type = 'post'");
        if (!$techCat) {
            $categoriesTable->insert([
                ['content_type' => 'post', 'language' => 'en', 'name' => 'Technology', 'slug' => 'technology', 'description' => 'Tech news & updates', 'created_at' => $now],
                ['content_type' => 'post', 'language' => 'en', 'name' => 'Design', 'slug' => 'design', 'description' => 'UI/UX and modern design', 'created_at' => $now],
                ['content_type' => 'post', 'language' => 'en', 'name' => 'News', 'slug' => 'news', 'description' => 'Latest announcements', 'created_at' => $now],
            ])->saveData();
            $techCat = $this->fetchRow("SELECT id FROM {$prefix}categories WHERE slug = 'technology' AND content_type = 'post'");
        }

        $tagsTable = $this->table($prefix . 'tags');
        $cmsTag    = $this->fetchRow("SELECT id FROM {$prefix}tags WHERE slug = 'cms' AND content_type = 'post'");
        if (!$cmsTag) {
            $tagsTable->insert([
                ['content_type' => 'post', 'language' => 'en', 'name' => 'CMS', 'slug' => 'cms', 'created_at' => $now],
                ['content_type' => 'post', 'language' => 'en', 'name' => 'PHP', 'slug' => 'php', 'created_at' => $now],
                ['content_type' => 'post', 'language' => 'en', 'name' => 'Web', 'slug' => 'web', 'created_at' => $now],
                ['content_type' => 'post', 'language' => 'en', 'name' => 'Tutorial', 'slug' => 'tutorial', 'created_at' => $now],
                ['content_type' => 'post', 'language' => 'en', 'name' => 'Design', 'slug' => 'design', 'created_at' => $now],
                ['content_type' => 'post', 'language' => 'en', 'name' => 'News', 'slug' => 'news', 'created_at' => $now],
            ])->saveData();
            $cmsTag = $this->fetchRow("SELECT id FROM {$prefix}tags WHERE slug = 'cms' AND content_type = 'post'");
        }

        // 4. Sample Posts
        $postsTable = $this->table($prefix . 'posts');
        $postCount  = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}posts WHERE content_type = 'post'");
        if ((int) ($postCount['count'] ?? 0) === 0) {
            $samplePosts = [
                [
                    'content_type'   => 'post',
                    'language'       => 'en',
                    'author_id'      => $adminId,
                    'title'          => 'Welcome to Vale CMS',
                    'slug'           => 'welcome-to-vale-cms',
                    'excerpt'        => 'Discover the speed and versatility of Vale CMS for modern web publishing.',
                    'body'           => '<p>Welcome to <strong>Vale CMS</strong>! A ultra-fast, lightweight content management system built with clean modern architecture.</p>',
                    'status'         => 'published',
                    'published_at'   => $now,
                    'created_at'     => $now,
                ],
                [
                    'content_type'   => 'post',
                    'language'       => 'en',
                    'author_id'      => $adminId,
                    'title'          => 'Building Modern Web Applications in PHP',
                    'slug'           => 'building-modern-web-applications-in-php',
                    'excerpt'        => 'Learn how modern PHP frameworks and architecture scale seamlessly.',
                    'body'           => '<p>PHP 8.3 delivers incredible performance and clean syntax features that make application development a joy.</p>',
                    'status'         => 'published',
                    'published_at'   => $now,
                    'created_at'     => $now,
                ],
                [
                    'content_type'   => 'post',
                    'language'       => 'en',
                    'author_id'      => $adminId,
                    'title'          => 'Top Design Trends for 2026',
                    'slug'           => 'top-design-trends-for-2026',
                    'excerpt'        => 'Explore minimalist design patterns and responsive UI components.',
                    'body'           => '<p>Design trends are shifting towards simplicity, fast load times, and accessible color schemes.</p>',
                    'status'         => 'published',
                    'published_at'   => $now,
                    'created_at'     => $now,
                ],
                [
                    'content_type'   => 'post',
                    'language'       => 'en',
                    'author_id'      => $adminId,
                    'title'          => 'Why Speed Matters for SEO and Conversions',
                    'slug'           => 'why-speed-matters-for-seo',
                    'excerpt'        => 'Optimizing server response times and asset delivery drives higher conversion rates.',
                    'body'           => '<p>Search engines prioritize fast-loading websites. Every millisecond saved boosts user engagement.</p>',
                    'status'         => 'published',
                    'published_at'   => $now,
                    'created_at'     => $now,
                ],
                [
                    'content_type'   => 'post',
                    'language'       => 'en',
                    'author_id'      => $adminId,
                    'title'          => 'E-Commerce Best Practices for High Growth',
                    'slug'           => 'ecommerce-best-practices-for-high-growth',
                    'excerpt'        => 'Key tips to increase checkout conversion and build customer loyalty.',
                    'body'           => '<p>Streamlining checkout flows and managing inventory effectively are crucial for store success.</p>',
                    'access_tags'    => json_encode(['bronze', 'gold']),
                    'status'         => 'published',
                    'published_at'   => $now,
                    'created_at'     => $now,
                ],
                [
                    'content_type'   => 'post',
                    'language'       => 'en',
                    'author_id'      => $adminId,
                    'title'          => 'Getting Started with Automated Email Marketing',
                    'slug'           => 'getting-started-with-automated-email-marketing',
                    'excerpt'        => 'Nurture subscribers automatically with targeted email automation sequences.',
                    'body'           => '<p>Automated sequences help keep subscribers engaged from the moment they sign up.</p>',
                    'access_tags'    => json_encode(['gold']),
                    'status'         => 'published',
                    'published_at'   => $now,
                    'created_at'     => $now,
                ],
            ];
            $postsTable->insert($samplePosts)->saveData();

            $firstPost = $this->fetchRow("SELECT id FROM {$prefix}posts WHERE content_type = 'post' ORDER BY id ASC LIMIT 1");

            $designCat = $this->fetchRow("SELECT id FROM {$prefix}categories WHERE slug = 'design' AND content_type = 'post'");
            $newsCat   = $this->fetchRow("SELECT id FROM {$prefix}categories WHERE slug = 'news' AND content_type = 'post'");
            $phpTag    = $this->fetchRow("SELECT id FROM {$prefix}tags WHERE slug = 'php' AND content_type = 'post'");
            $webTag    = $this->fetchRow("SELECT id FROM {$prefix}tags WHERE slug = 'web' AND content_type = 'post'");
            $tutTag    = $this->fetchRow("SELECT id FROM {$prefix}tags WHERE slug = 'tutorial' AND content_type = 'post'");
            $designTag = $this->fetchRow("SELECT id FROM {$prefix}tags WHERE slug = 'design' AND content_type = 'post'");
            $newsTag   = $this->fetchRow("SELECT id FROM {$prefix}tags WHERE slug = 'news' AND content_type = 'post'");

            $allPosts    = $this->fetchAll("SELECT id, slug FROM {$prefix}posts WHERE content_type = 'post'");
            $postSlugMap = [];
            foreach ($allPosts as $p) {
                $postSlugMap[$p['slug']] = $p['id'];
            }

            $postCategoryLinks = [
                ['welcome-to-vale-cms', $techCat],
                ['building-modern-web-applications-in-php', $techCat],
                ['top-design-trends-for-2026', $designCat],
                ['why-speed-matters-for-seo', $techCat],
                ['ecommerce-best-practices-for-high-growth', $newsCat],
                ['getting-started-with-automated-email-marketing', $techCat],
            ];
            foreach ($postCategoryLinks as [$slug, $category]) {
                if (isset($postSlugMap[$slug]) && $category) {
                    $this->table($prefix . 'post_categories')->insert([
                        ['post_id' => $postSlugMap[$slug], 'category_id' => $category['id']],
                    ])->saveData();
                }
            }

            $postTagLinks = [
                ['welcome-to-vale-cms', $cmsTag],
                ['welcome-to-vale-cms', $webTag],
                ['building-modern-web-applications-in-php', $phpTag],
                ['building-modern-web-applications-in-php', $tutTag],
                ['top-design-trends-for-2026', $designTag, $webTag],
                ['why-speed-matters-for-seo', $tutTag],
                ['ecommerce-best-practices-for-high-growth', $newsTag, $webTag],
                ['getting-started-with-automated-email-marketing', $phpTag, $tutTag],
            ];
            foreach ($postTagLinks as $link) {
                $slug   = $link[0];
                $postId = $postSlugMap[$slug] ?? null;
                if (!$postId) {
                    continue;
                }
                foreach (array_slice($link, 1) as $tag) {
                    if ($tag) {
                        $this->table($prefix . 'post_tags')->insert([
                            ['post_id' => $postId, 'tag_id' => $tag['id']],
                        ])->saveData();
                    }
                }
            }

            // Sample Post Comments
            if ($firstPost) {
                $this->table($prefix . 'comments')->insert([
                    [
                        'entity_type'  => 'post',
                        'entity_id'    => $firstPost['id'],
                        'author_name'  => 'Alex Johnson',
                        'author_email' => 'alex@example.com',
                        'body'         => 'Great post! Loving the speed and simplicity of this CMS.',
                        'status'       => 'approved',
                        'created_at'   => $now,
                    ],
                    [
                        'entity_type'  => 'post',
                        'entity_id'    => $firstPost['id'],
                        'author_name'  => 'Sarah Parker',
                        'author_email' => 'sarah@example.com',
                        'body'         => 'Awesome job on the clean setup and structure!',
                        'status'       => 'approved',
                        'created_at'   => $now,
                    ],
                ])->saveData();
            }
        }

        // 5. Sample Pages
        $pageCount = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}posts WHERE content_type = 'page'");
        if ((int) ($pageCount['count'] ?? 0) === 0) {
            $samplePages = [
                [
                    'content_type'   => 'page',
                    'language'       => 'en',
                    'author_id'      => $adminId,
                    'title'          => 'Home',
                    'slug'           => 'home',
                    'body'           => '<h1 style="text-align: center;">Welcome to Our Website</h1>[hero variant="cta" button1Text="Explore Services" button1Url="/services" button2Text="Learn More" button2Url="/about-us"][hero_heading]New and modern[/hero_heading][hero_subtitle]We always have the most modern systems.[/hero_subtitle][/hero]<p style="text-align: center;">Looking for more?</p>[card_group gap="12"] [card_item image="" link="/services" target="_self" bg=""][card_title]Services[/card_title][card_desc]We build exceptional digital experiences.[/card_desc][/card_item][card_item image="" link="/services/web-design" target="_self" bg=""][card_title]Web Design[/card_title][card_desc]Still don\'t own one? We help you build one.[/card_desc][/card_item] [/card_group]<p style="text-align: center;">Or buy one of our items:</p>[link_button_group layout="vertical" gap="12"] [link_button href="/shop" target="_self" bgColor="#000000" textColor="#ffffff" borderWidth="0px" borderStyle="none" borderColor="#000000" borderRadius="4px" padding="10px 20px" alignment="center" fullWidth="false"]Shop[/link_button] [/link_button_group]<p style="text-align: center;">As always, you can contact us if you have any questions:</p>[link_button_group layout="vertical" gap="12"] [link_button href="/contact" target="_self" bgColor="#000000" textColor="#ffffff" borderWidth="0px" borderStyle="none" borderColor="#000000" borderRadius="4px" padding="10px 20px" alignment="center" fullWidth="false"]Contact Us[/link_button] [/link_button_group]<p></p>',
                    'status'         => 'published',
                    'published_at'   => $now,
                    'created_at'     => $now,
                    'page_template'  => 'full-width',
                    'hide_title'     => 1,
                ],
                [
                    'content_type' => 'page',
                    'language'     => 'en',
                    'author_id'    => $adminId,
                    'title'        => 'About Us',
                    'slug'         => 'about-us',
                    'body'         => '<h2>About Our Company</h2><p>Our mission is to deliver fast, secure, and intuitive web solutions.</p>',
                    'status'       => 'published',
                    'published_at' => $now,
                    'created_at'   => $now,
                ],
                [
                    'content_type' => 'page',
                    'language'     => 'en',
                    'author_id'    => $adminId,
                    'title'        => 'Services',
                    'slug'         => 'services',
                    'body'         => '<h2>Our Services</h2><p>From custom development to high-performance e-commerce solutions.</p><p>[pages]</p>',
                    'status'       => 'published',
                    'published_at' => $now,
                    'created_at'   => $now,
                ],
                [
                    'content_type' => 'page',
                    'language'     => 'en',
                    'author_id'    => $adminId,
                    'title'        => 'Web Design',
                    'slug'         => 'web-design',
                    'body'         => '<p>We create stunning, responsive websites tailored to your brand.</p>',
                    'status'       => 'published',
                    'published_at' => $now,
                    'created_at'   => $now,
                ],
                [
                    'content_type' => 'page',
                    'language'     => 'en',
                    'author_id'    => $adminId,
                    'title'        => 'SEO',
                    'slug'         => 'seo',
                    'body'         => '<p>Boost your rankings and drive organic traffic with our SEO strategies.</p>',
                    'status'       => 'published',
                    'published_at' => $now,
                    'created_at'   => $now,
                ],
                [
                    'content_type' => 'page',
                    'language'     => 'en',
                    'author_id'    => $adminId,
                    'title'        => 'Contact',
                    'slug'         => 'contact',
                    'body'         => '<h2>Get In Touch</h2><p>Feel free to reach out to us anytime using the contact form below.</p><p><span style="color: oklch(0.278 0.033 256.848);">[form slug="contact-form"]</span></p>',
                    'status'       => 'published',
                    'published_at' => $now,
                    'created_at'   => $now,
                ],
                [
                    'content_type' => 'page',
                    'language'     => 'en',
                    'author_id'    => $adminId,
                    'title'        => 'Privacy Policy',
                    'slug'         => 'privacy-policy',
                    'body'         => '<h2>Privacy Policy</h2><p>We value your privacy and process all personal data strictly in accordance with GDPR regulations.</p>',
                    'status'       => 'published',
                    'published_at' => $now,
                    'created_at'   => $now,
                ],
                [
                    'content_type' => 'page',
                    'language'     => 'en',
                    'author_id'    => $adminId,
                    'title'        => 'Terms of Service',
                    'slug'         => 'terms-of-service',
                    'body'         => '<h2>Terms of Service</h2><p>By accessing this site, you agree to comply with our terms and conditions.</p>',
                    'status'       => 'published',
                    'published_at' => $now,
                    'created_at'   => $now,
                ],
            ];
            $postsTable->insert($samplePages)->saveData();

            $servicesPage = $this->fetchRow("SELECT id FROM {$prefix}posts WHERE content_type = 'page' AND slug = 'services' LIMIT 1");
            if ($servicesPage) {
                $servicesId = (int) $servicesPage['id'];

                $webDesignPage = $this->fetchRow("SELECT id, parent_id FROM {$prefix}posts WHERE content_type = 'page' AND slug = 'web-design' LIMIT 1");
                if ($webDesignPage && ((int) $webDesignPage['parent_id']) === 0) {
                    $this->execute("UPDATE {$prefix}posts SET parent_id = {$servicesId} WHERE id = " . (int) $webDesignPage['id']);
                }

                $seoPage = $this->fetchRow("SELECT id, parent_id FROM {$prefix}posts WHERE content_type = 'page' AND slug = 'seo' LIMIT 1");
                if ($seoPage && ((int) $seoPage['parent_id']) === 0) {
                    $this->execute("UPDATE {$prefix}posts SET parent_id = {$servicesId} WHERE id = " . (int) $seoPage['id']);
                }
            }

            $homePage = $this->fetchRow("SELECT id FROM {$prefix}posts WHERE content_type = 'page' AND slug = 'home' LIMIT 1");
            if ($homePage) {
                $settingsToSet = [
                    ['group_name' => 'content', 'key' => 'show_on_front', 'value' => 'page'],
                    ['group_name' => 'content', 'key' => 'homepage_page_id', 'value' => (string) $homePage['id']],
                ];
                foreach ($settingsToSet as $setting) {
                    $exists = $this->fetchRow(
                        "SELECT id FROM {$prefix}settings WHERE group_name = '{$setting['group_name']}' AND {$q}key{$q} = '{$setting['key']}' LIMIT 1"
                    );
                    if (!$exists) {
                        $this->table($prefix . 'settings')->insert([
                            'group_name'  => $setting['group_name'],
                            'key'         => $setting['key'],
                            'value'       => $setting['value'],
                            'type'        => 'string',
                            'is_autoload' => true,
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ])->saveData();
                    }
                }
            }
        }

        // 5b. Sample navigation menus
        $basePath        = (string) (parse_url((string) getenv('APP_URL'), PHP_URL_PATH) ?: '');
        $menuUrl         = static fn (string $slug): string => rtrim($basePath, '/') . '/' . ltrim($slug, '/');
        $menuDefinitions = [
            [
                'name'     => 'Main Menu',
                'slug'     => 'main-menu',
                'location' => 'primary',
                'items'    => [
                    ['label' => 'Home', 'slug' => 'home'],
                    ['label' => 'About Us', 'slug' => 'about-us'],
                    ['label' => 'Services', 'slug' => 'services'],
                    ['label' => 'Web Design', 'slug' => 'web-design', 'parent' => 'Services'],
                    ['label' => 'SEO', 'slug' => 'seo', 'parent' => 'Services'],
                    ['label' => 'Shop', 'url' => $menuUrl('shop'), 'type' => 'shop'],
                    ['label' => 'Membership Plans', 'url' => $menuUrl('membership'), 'type' => 'membership'],
                    ['label' => 'Blog', 'url' => $menuUrl('blog'), 'type' => 'blog'],
                ],
            ],
            [
                'name'     => 'Footer',
                'slug'     => 'footer',
                'location' => 'footer',
                'items'    => [
                    ['label' => 'Terms of Service', 'slug' => 'terms-of-service'],
                    ['label' => 'Privacy Policy', 'slug' => 'privacy-policy'],
                    ['label' => 'Contact', 'slug' => 'contact'],
                ],
            ],
        ];

        $menusTable = $this->table($prefix . 'menus');
        foreach ($menuDefinitions as $definition) {
            $menu = $this->fetchRow(
                "SELECT id FROM {$prefix}menus WHERE slug = '" . $definition['slug'] . "' LIMIT 1"
            );
            if (!$menu) {
                $menusTable->insert([
                    [
                        'name'       => $definition['name'],
                        'slug'       => $definition['slug'],
                        'language'   => 'en',
                        'location'   => $definition['location'],
                        'created_at' => $now,
                    ],
                ])->saveData();
                $menu = $this->fetchRow(
                    "SELECT id FROM {$prefix}menus WHERE slug = '" . $definition['slug'] . "' LIMIT 1"
                );
            }

            if (!$menu) {
                continue;
            }

            $existingItem = $this->fetchRow(
                "SELECT id FROM {$prefix}menu_items WHERE menu_id = " . (int) $menu['id'] . " LIMIT 1"
            );
            if ($existingItem) {
                continue;
            }

            $menuId      = (int) $menu['id'];
            $labelToId   = [];
            $parentMap   = []; // itemLabel => parentLabel
            $insertRows  = [];

            foreach ($definition['items'] as $sortOrder => $item) {
                $isUrl = isset($item['url']);
                $page  = null;
                if (!$isUrl) {
                    $page = $this->fetchRow(
                        "SELECT id FROM {$prefix}posts WHERE content_type = 'page' AND slug = '" . $item['slug'] . "' LIMIT 1"
                    );
                    if (!$page) {
                        continue;
                    }
                }

                $insertRows[] = [
                    'menu_id'      => $menuId,
                    'parent_id'    => null,
                    'type'         => $item['type'] ?? ($isUrl ? 'shop' : 'page'),
                    'label'        => $item['label'],
                    'url'          => $isUrl ? $item['url'] : $menuUrl($item['slug']),
                    'target'       => '_self',
                    'reference_id' => $page ? (int) $page['id'] : null,
                    'sort_order'   => $sortOrder,
                    'css_class'    => '',
                    'created_at'   => $now,
                ];

                if (isset($item['parent'])) {
                    $parentMap[$item['label']] = $item['parent'];
                }
            }

            // Insert all items (parent_id = null for now)
            $menuItemsTable = $this->table($prefix . 'menu_items');
            if (!empty($insertRows)) {
                $menuItemsTable->insert($insertRows)->saveData();
            }

            // Resolve parent labels -> parent_id
            if (!empty($parentMap)) {
                $pdo  = $this->getAdapter()->getConnection();
                $rows = $pdo->query(
                    "SELECT id, label FROM {$prefix}menu_items WHERE menu_id = {$menuId} ORDER BY sort_order ASC"
                )->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $labelToId[$row['label']] = (int) $row['id'];
                }

                foreach ($parentMap as $childLabel => $parentLabel) {
                    if (isset($labelToId[$childLabel]) && isset($labelToId[$parentLabel])) {
                        $stmt = $pdo->prepare(
                            "UPDATE {$prefix}menu_items SET parent_id = ? WHERE id = ?"
                        );
                        $stmt->execute([$labelToId[$parentLabel], $labelToId[$childLabel]]);
                    }
                }
            }
        }

        // Migrate legacy membership menu items saved with type=shop before the
        // dedicated membership type existed (they resolved to /shop on render)
        $this->execute(
            "UPDATE {$prefix}menu_items SET type = 'membership' WHERE label = 'Membership Plans' AND type = 'shop'"
        );

        // 6. Product Categories & Products
        $prodCatTable = $this->table($prefix . 'product_categories');
        $elecCat      = $this->fetchRow("SELECT id FROM {$prefix}product_categories WHERE slug = 'electronics'");
        if (!$elecCat) {
            $prodCatTable->insert([
                ['language' => 'en', 'name' => 'Electronics', 'slug' => 'electronics', 'description' => 'Gadgets & Devices', 'created_at' => $now],
                ['language' => 'en', 'name' => 'Clothing', 'slug' => 'clothing', 'description' => 'Apparel & Fashion', 'created_at' => $now],
                ['language' => 'en', 'name' => 'Digital Goods', 'slug' => 'digital-goods', 'description' => 'E-books & Software', 'created_at' => $now],
            ])->saveData();
            $elecCat = $this->fetchRow("SELECT id FROM {$prefix}product_categories WHERE slug = 'electronics'");
        }

        $productsTable = $this->table($prefix . 'products');
        $prodCount     = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}products");
        if ((int) ($prodCount['count'] ?? 0) === 0) {
            $sampleProducts = [
                [
                    'name'              => 'Wireless Noise-Canceling Headphones',
                    'slug'              => 'wireless-noise-canceling-headphones',
                    'language'          => 'en',
                    'sku'               => 'HP-NC-001',
                    'price'             => 199.99,
                    'sale_price'        => 179.99,
                    'stock_quantity'    => 25,
                    'stock_status'      => 'in_stock',
                    'description'       => 'Premium over-ear wireless headphones with active noise cancellation and 30-hour battery life.',
                    'short_description' => 'Crystal clear sound with active noise cancellation.',
                    'status'            => 'published',
                    'type'              => 'simple',
                    'created_at'        => $now,
                ],
                [
                    'name'              => 'Minimalist Cotton T-Shirt',
                    'slug'              => 'minimalist-cotton-t-shirt',
                    'language'          => 'en',
                    'sku'               => 'TS-COT-002',
                    'price'             => 29.99,
                    'sale_price'        => null,
                    'stock_quantity'    => 50,
                    'stock_status'      => 'in_stock',
                    'description'       => '100% organic cotton breathable t-shirt available in multiple sizes and colors.',
                    'short_description' => 'Comfortable organic cotton t-shirt.',
                    'status'            => 'published',
                    'type'              => 'variable',
                    'created_at'        => $now,
                ],
                [
                    'name'              => 'Ergonomic Mechanical Keyboard',
                    'slug'              => 'ergonomic-mechanical-keyboard',
                    'language'          => 'en',
                    'sku'               => 'KB-MECH-003',
                    'price'             => 129.50,
                    'sale_price'        => null,
                    'stock_quantity'    => 15,
                    'stock_status'      => 'in_stock',
                    'description'       => 'Tactile mechanical keyboard with customizable RGB backlighting and hot-swappable switches.',
                    'short_description' => 'Tactile RGB mechanical keyboard.',
                    'status'            => 'published',
                    'type'              => 'variable',
                    'created_at'        => $now,
                ],
                [
                    'name'               => 'Vale CMS Developer Guide E-Book',
                    'slug'               => 'vale-cms-developer-guide-ebook',
                    'language'           => 'en',
                    'sku'                => 'EBK-VALE-004',
                    'price'              => 19.00,
                    'sale_price'         => null,
                    'stock_quantity'     => 999,
                    'stock_status'       => 'in_stock',
                    'description'        => 'Comprehensive digital guide to building plugins, themes, and integrations with Vale CMS.',
                    'short_description'  => 'Official digital guide for developers.',
                    'status'             => 'published',
                    'type'               => 'digital',
                    'shipping_class'     => 'digital',
                    'download_source'    => 'external',
                    'download_url'       => 'https://example.com/fileName.zip',
                    'download_url_proxy' => 1,
                    'created_at'         => $now,
                ],
                [
                    'name'              => 'Smart Fitness Watch',
                    'slug'              => 'smart-fitness-watch',
                    'language'          => 'en',
                    'sku'               => 'SW-FIT-005',
                    'price'             => 149.00,
                    'sale_price'        => 119.00,
                    'stock_quantity'    => 30,
                    'stock_status'      => 'in_stock',
                    'description'       => 'Track your daily activity, heart rate, sleep quality, and workouts with long-lasting battery life.',
                    'short_description' => 'All-in-one health and activity tracker.',
                    'status'            => 'published',
                    'type'              => 'simple',
                    'created_at'        => $now,
                ],
                [
                    'name'              => 'Premium Leather Wallet',
                    'slug'              => 'premium-leather-wallet',
                    'language'          => 'en',
                    'sku'               => 'WLT-LTH-006',
                    'price'             => 45.00,
                    'sale_price'        => null,
                    'stock_quantity'    => 40,
                    'stock_status'      => 'in_stock',
                    'description'       => 'Handcrafted genuine leather wallet with RFID blocking protection and slim bifold layout.',
                    'short_description' => 'Handcrafted RFID blocking leather wallet.',
                    'status'            => 'published',
                    'type'              => 'simple',
                    'created_at'        => $now,
                ],
            ];
            $productsTable->insert($sampleProducts)->saveData();

            // Insert Product Category relationships for ALL products
            $clothingCat = $this->fetchRow("SELECT id FROM {$prefix}product_categories WHERE slug = 'clothing'");
            $digitalCat  = $this->fetchRow("SELECT id FROM {$prefix}product_categories WHERE slug = 'digital-goods'");

            $headphones = $this->fetchRow("SELECT id FROM {$prefix}products WHERE slug = 'wireless-noise-canceling-headphones'");
            $tshirt     = $this->fetchRow("SELECT id FROM {$prefix}products WHERE slug = 'minimalist-cotton-t-shirt'");
            $keyboard   = $this->fetchRow("SELECT id FROM {$prefix}products WHERE slug = 'ergonomic-mechanical-keyboard'");
            $ebook      = $this->fetchRow("SELECT id FROM {$prefix}products WHERE slug = 'vale-cms-developer-guide-ebook'");
            $watch      = $this->fetchRow("SELECT id FROM {$prefix}products WHERE slug = 'smart-fitness-watch'");
            $wallet     = $this->fetchRow("SELECT id FROM {$prefix}products WHERE slug = 'premium-leather-wallet'");

            $productCategoryMap = [
                [$headphones, $elecCat],
                [$tshirt, $clothingCat],
                [$keyboard, $elecCat],
                [$ebook, $digitalCat],
                [$watch, $elecCat],
                [$wallet, $clothingCat],
            ];
            foreach ($productCategoryMap as [$product, $category]) {
                if ($product && $category) {
                    $this->table($prefix . 'product_to_categories')->insert([
                        ['product_id' => $product['id'], 'category_id' => $category['id']],
                    ])->saveData();
                }
            }

            // Insert Product Variations for T-Shirt
            $tshirt = $this->fetchRow("SELECT id FROM {$prefix}products WHERE slug = 'minimalist-cotton-t-shirt'");
            if ($tshirt) {
                $this->table($prefix . 'product_variations')->insert([
                    [
                        'product_id'     => $tshirt['id'],
                        'attributes'     => json_encode(['Size' => 'S', 'Color' => 'Black']),
                        'sku'            => 'TS-COT-002-S-BLK',
                        'price'          => 29.99,
                        'stock_quantity' => 20,
                        'stock_status'   => 'in_stock',
                    ],
                    [
                        'product_id'     => $tshirt['id'],
                        'attributes'     => json_encode(['Size' => 'M', 'Color' => 'Black']),
                        'sku'            => 'TS-COT-002-M-BLK',
                        'price'          => 29.99,
                        'stock_quantity' => 15,
                        'stock_status'   => 'in_stock',
                    ],
                    [
                        'product_id'     => $tshirt['id'],
                        'attributes'     => json_encode(['Size' => 'L', 'Color' => 'White']),
                        'sku'            => 'TS-COT-002-L-WHT',
                        'price'          => 29.99,
                        'stock_quantity' => 15,
                        'stock_status'   => 'in_stock',
                    ],
                    [
                        'product_id'        => $tshirt['id'],
                        'attributes'        => json_encode(['Size' => 'Custom', 'Color' => 'Custom', 'Custom Name' => '']),
                        'sku'               => 'TS-COT-002-CUST',
                        'price'             => 34.99,
                        'stock_quantity'    => 10,
                        'stock_status'      => 'in_stock',
                        'shipping_time_min' => 4,
                        'shipping_time_max' => 10,
                    ],
                ])->saveData();
            }

            // Insert Product Variations for Keyboard (Size: Small/Medium/Large)
            $keyboard = $this->fetchRow("SELECT id FROM {$prefix}products WHERE slug = 'ergonomic-mechanical-keyboard'");
            if ($keyboard) {
                $this->table($prefix . 'product_variations')->insert([
                    [
                        'product_id'     => $keyboard['id'],
                        'attributes'     => json_encode(['Size' => 'Small']),
                        'sku'            => 'KB-MECH-003-S',
                        'price'          => 129.50,
                        'stock_quantity' => 5,
                        'stock_status'   => 'in_stock',
                    ],
                    [
                        'product_id'     => $keyboard['id'],
                        'attributes'     => json_encode(['Size' => 'Medium']),
                        'sku'            => 'KB-MECH-003-M',
                        'price'          => 139.50,
                        'stock_quantity' => 5,
                        'stock_status'   => 'in_stock',
                    ],
                    [
                        'product_id'     => $keyboard['id'],
                        'attributes'     => json_encode(['Size' => 'Large']),
                        'sku'            => 'KB-MECH-003-L',
                        'price'          => 149.50,
                        'stock_quantity' => 5,
                        'stock_status'   => 'in_stock',
                    ],
                ])->saveData();
            }

            // Insert Product Reviews (comments + linked votes)
            if ($headphones) {
                $this->table($prefix . 'comments')->insert([
                    [
                        'entity_type'  => 'product',
                        'entity_id'    => $headphones['id'],
                        'author_name'  => 'Jane Subscriber',
                        'author_email' => 'user@example.com',
                        'author_ip'    => '127.0.0.1',
                        'user_id'      => $subscriberUserId,
                        'body'         => 'Outstanding noise cancellation and super comfortable for long flights!',
                        'status'       => 'approved',
                        'verified'     => true,
                        'locale'       => 'en',
                        'created_at'   => $now,
                    ],
                ])->saveData();
                $headphonesCommentId = (int) $this->getAdapter()->getConnection()->lastInsertId();

                $this->table($prefix . 'product_votes')->insert([
                    [
                        'product_id'  => $headphones['id'],
                        'comment_id'  => $headphonesCommentId,
                        'user_id'     => $subscriberUserId,
                        'session_id'  => 'demo_session_123',
                        'rating'      => 5,
                        'created_at'  => $now,
                    ],
                ])->saveData();
            }

            $keyboard = $this->fetchRow("SELECT id FROM {$prefix}products WHERE slug = 'ergonomic-mechanical-keyboard'");
            if ($keyboard) {
                $this->table($prefix . 'comments')->insert([
                    [
                        'entity_type'  => 'product',
                        'entity_id'    => $keyboard['id'],
                        'author_name'  => 'Alex Guest',
                        'author_email' => 'alex@example.com',
                        'author_ip'    => '127.0.0.1',
                        'user_id'      => null,
                        'body'         => 'Best keyboard I have ever used! The tactile switches and RGB backlighting are incredible.',
                        'status'       => 'approved',
                        'verified'     => false,
                        'locale'       => 'en',
                        'created_at'   => $now,
                    ],
                ])->saveData();
                $keyboardCommentId = (int) $this->getAdapter()->getConnection()->lastInsertId();

                $this->table($prefix . 'product_votes')->insert([
                    [
                        'product_id'  => $keyboard['id'],
                        'comment_id'  => $keyboardCommentId,
                        'user_id'     => null,
                        'session_id'  => bin2hex(random_bytes(16)),
                        'rating'      => 5,
                        'created_at'  => $now,
                    ],
                ])->saveData();
            }
        }

        // Ensure custom tshirt variation has estimated delivery override (4-10 days)
        $this->execute(
            "UPDATE {$prefix}product_variations SET shipping_time_min = 4, shipping_time_max = 10 WHERE sku = 'TS-COT-002-CUST'"
        );

        // 6b. Membership Plans
        $plansTable      = $this->table($prefix . 'membership_plans');
        $membershipPlans = [
            [
                'name'          => 'Bronze Plan',
                'slug'          => 'bronze-plan',
                'description'   => 'This is the first tier to unlock our content.',
                'price'         => 1.99,
                'plan_interval' => 'monthly',
                'gateway'       => 'stripe',
                'tag'           => 'bronze',
                'trial_days'    => 0,
                'trial_tag'     => null,
                'trial_price'   => 0.00,
                'sort_order'    => 0,
                'is_active'     => true,
                'created_at'    => $now,
            ],
            [
                'name'          => 'Gold Plan',
                'slug'          => 'gold-plan',
                'description'   => 'Get access to all tiers with free trial for 7 days!',
                'price'         => 19.99,
                'plan_interval' => 'monthly',
                'gateway'       => 'stripe',
                'tag'           => 'gold',
                'trial_days'    => 7,
                'trial_tag'     => 'gold',
                'trial_price'   => 0.00,
                'sort_order'    => 0,
                'is_active'     => true,
                'created_at'    => $now,
            ],
        ];
        foreach ($membershipPlans as $plan) {
            $existingPlan = $this->fetchRow(
                "SELECT id FROM {$prefix}membership_plans WHERE slug = '" . $plan['slug'] . "' LIMIT 1"
            );
            if (!$existingPlan) {
                $plansTable->insert([$plan])->saveData();
            }
        }

        // 7. Coupons
        $couponsTable = $this->table($prefix . 'coupons');
        $couponCount  = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}coupons");
        if ((int) ($couponCount['count'] ?? 0) === 0) {
            $couponsTable->insert([
                [
                    'code'          => 'WELCOME10',
                    'type'          => 'percent',
                    'amount'        => 10.00,
                    'free_shipping' => false,
                    'uses_count'    => 1,
                    'enabled'       => true,
                    'created_at'    => $now,
                ],
                [
                    'code'          => 'SUMMER20',
                    'type'          => 'fixed',
                    'amount'        => 20.00,
                    'free_shipping' => true,
                    'uses_count'    => 1,
                    'enabled'       => true,
                    'created_at'    => $now,
                ],
            ])->saveData();
        }

        // 8. E-Commerce Orders
        $ordersTable = $this->table($prefix . 'orders');
        $orderCount  = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}orders");
        if ((int) ($orderCount['count'] ?? 0) === 0) {
            $sampleHeadphones = $this->fetchRow("SELECT id, name, sku FROM {$prefix}products WHERE slug = 'wireless-noise-canceling-headphones'");
            $sampleWatch      = $this->fetchRow("SELECT id, name, sku FROM {$prefix}products WHERE slug = 'smart-fitness-watch'");
            $sampleWallet     = $this->fetchRow("SELECT id, name, sku FROM {$prefix}products WHERE slug = 'premium-leather-wallet'");
            $sampleEbook      = $this->fetchRow("SELECT id, name, sku FROM {$prefix}products WHERE slug = 'vale-cms-developer-guide-ebook'");
            $sampleKeyboard   = $this->fetchRow("SELECT id, name, sku FROM {$prefix}products WHERE slug = 'ergonomic-mechanical-keyboard'");

            $sampleOrders = [
                [
                    'order_number'    => 'ORD-2026-1001',
                    'order_type'      => 'shop',
                    'user_id'         => $subscriberUserId,
                    'status'          => 'completed',
                    'locale'          => 'en',
                    'currency'        => 'EUR',
                    'subtotal'        => 179.99,
                    'tax'             => 29.54,
                    'shipping'        => 5.00,
                    'discount'        => 0.00,
                    'total'           => 184.99,
                    'payment_method'  => 'stripe',
                    'transaction_id'  => 'ch_3M0011223344',
                    'billing'         => $this->demoBilling(['first_name' => 'Jane', 'last_name' => 'Subscriber', 'email' => 'user@example.com', 'address' => 'Main St 123', 'city' => 'Berlin', 'country' => 'DE']),
                    'paid_at'         => $now,
                    'created_at'      => $now,
                ],
                [
                    'order_number'    => 'ORD-2026-1002',
                    'order_type'      => 'shop',
                    'user_id'         => $subscriberUserId,
                    'status'          => 'processing',
                    'locale'          => 'en',
                    'currency'        => 'EUR',
                    'subtotal'        => 119.00,
                    'tax'             => 17.40,
                    'shipping'        => 0.00,
                    'discount'        => 10.00,
                    'coupon_code'     => 'WELCOME10',
                    'total'           => 109.00,
                    'payment_method'  => 'paypal',
                    'transaction_id'  => 'PAYID-123456789',
                    'billing'         => $this->demoBilling(['first_name' => 'Jane', 'last_name' => 'Subscriber', 'email' => 'user@example.com', 'address' => 'Main St 123', 'city' => 'Berlin', 'country' => 'DE']),
                    'paid_at'         => $now,
                    'created_at'      => $now,
                ],
                [
                    'order_number'    => 'ORD-2026-1003',
                    'order_type'      => 'shop',
                    'user_id'         => null,
                    'status'          => 'pending',
                    'locale'          => 'en',
                    'currency'        => 'EUR',
                    'subtotal'        => 45.00,
                    'tax'             => 7.98,
                    'shipping'        => 5.00,
                    'discount'        => 0.00,
                    'total'           => 50.00,
                    'payment_method'  => 'stripe',
                    'billing'         => $this->demoBilling(['first_name' => 'Michael', 'last_name' => 'Smith', 'email' => 'm.smith@example.com', 'address' => 'Königsallee 4', 'city' => 'Düsseldorf', 'country' => 'DE']),
                    'created_at'      => $now,
                ],
                [
                    'order_number'    => 'ORD-2026-1004',
                    'order_type'      => 'shop',
                    'user_id'         => $subscriberUserId,
                    'status'          => 'completed',
                    'locale'          => 'en',
                    'currency'        => 'EUR',
                    'subtotal'        => 19.00,
                    'tax'             => 3.03,
                    'shipping'        => 0.00,
                    'discount'        => 0.00,
                    'total'           => 19.00,
                    'payment_method'  => 'stripe',
                    'transaction_id'  => 'ch_3M0099887766',
                    'billing'         => $this->demoBilling(['first_name' => 'Jane', 'last_name' => 'Subscriber', 'email' => 'user@example.com', 'address' => 'Main St 123', 'city' => 'Berlin', 'country' => 'DE']),
                    'paid_at'         => $now,
                    'created_at'      => $now,
                ],
                [
                    'order_number'    => 'ORD-2026-1005',
                    'order_type'      => 'shop',
                    'user_id'         => null,
                    'status'          => 'completed',
                    'locale'          => 'en',
                    'currency'        => 'EUR',
                    'subtotal'        => 129.50,
                    'tax'             => 17.48,
                    'shipping'        => 0.00,
                    'discount'        => 20.00,
                    'coupon_code'     => 'SUMMER20',
                    'total'           => 109.50,
                    'payment_method'  => 'paypal',
                    'transaction_id'  => 'PAYID-987654321',
                    'billing'         => $this->demoBilling(['first_name' => 'Emma', 'last_name' => 'Watson', 'email' => 'emma@example.com', 'address' => 'Oxford St 12', 'city' => 'London', 'country' => 'GB']),
                    'paid_at'         => $now,
                    'created_at'      => $now,
                ],
            ];
            $ordersTable->insert($sampleOrders)->saveData();

            // Order items
            $order1 = $this->fetchRow("SELECT id FROM {$prefix}orders WHERE order_number = 'ORD-2026-1001'");
            if ($order1 && $sampleHeadphones) {
                $this->table($prefix . 'order_items')->insert([
                    [
                        'order_id'   => $order1['id'],
                        'product_id' => $sampleHeadphones['id'],
                        'name'       => $sampleHeadphones['name'],
                        'sku'        => $sampleHeadphones['sku'],
                        'quantity'   => 1,
                        'price'      => 179.99,
                        'tax_rate'   => 19.00,
                        'tax_amount' => 28.74,
                        'subtotal'   => 179.99,
                        'total'      => 179.99,
                    ],
                ])->saveData();
            }

            $order2 = $this->fetchRow("SELECT id FROM {$prefix}orders WHERE order_number = 'ORD-2026-1002'");
            if ($order2 && $sampleWatch) {
                $this->table($prefix . 'order_items')->insert([
                    [
                        'order_id'   => $order2['id'],
                        'product_id' => $sampleWatch['id'],
                        'name'       => $sampleWatch['name'],
                        'sku'        => $sampleWatch['sku'],
                        'quantity'   => 1,
                        'price'      => 119.00,
                        'tax_rate'   => 19.00,
                        'tax_amount' => 17.40,
                        'subtotal'   => 119.00,
                        'total'      => 119.00,
                    ],
                ])->saveData();
            }

            $order3 = $this->fetchRow("SELECT id FROM {$prefix}orders WHERE order_number = 'ORD-2026-1003'");
            if ($order3 && $sampleWallet) {
                $this->table($prefix . 'order_items')->insert([
                    [
                        'order_id'   => $order3['id'],
                        'product_id' => $sampleWallet['id'],
                        'name'       => $sampleWallet['name'],
                        'sku'        => $sampleWallet['sku'],
                        'quantity'   => 1,
                        'price'      => 45.00,
                        'tax_rate'   => 19.00,
                        'tax_amount' => 7.18,
                        'subtotal'   => 45.00,
                        'total'      => 45.00,
                    ],
                ])->saveData();
            }

            $order4 = $this->fetchRow("SELECT id FROM {$prefix}orders WHERE order_number = 'ORD-2026-1004'");
            if ($order4 && $sampleEbook) {
                $this->table($prefix . 'order_items')->insert([
                    [
                        'order_id'   => $order4['id'],
                        'product_id' => $sampleEbook['id'],
                        'name'       => $sampleEbook['name'],
                        'sku'        => $sampleEbook['sku'],
                        'quantity'   => 1,
                        'price'      => 19.00,
                        'tax_rate'   => 19.00,
                        'tax_amount' => 3.03,
                        'subtotal'   => 19.00,
                        'total'      => 19.00,
                    ],
                ])->saveData();
            }

            $order5 = $this->fetchRow("SELECT id FROM {$prefix}orders WHERE order_number = 'ORD-2026-1005'");
            if ($order5 && $sampleKeyboard) {
                $this->table($prefix . 'order_items')->insert([
                    [
                        'order_id'   => $order5['id'],
                        'product_id' => $sampleKeyboard['id'],
                        'name'       => $sampleKeyboard['name'],
                        'sku'        => $sampleKeyboard['sku'],
                        'quantity'   => 1,
                        'price'      => 129.50,
                        'tax_rate'   => 19.00,
                        'tax_amount' => 17.48,
                        'subtotal'   => 129.50,
                        'total'      => 129.50,
                    ],
                ])->saveData();
            }

            // Update total_sold counter on products based on completed & processing orders
            $this->execute(
                "UPDATE {$prefix}products SET total_sold = ("
                . "SELECT COALESCE(SUM(oi.quantity), 0) "
                . "FROM {$prefix}order_items oi "
                . "INNER JOIN {$prefix}orders o ON oi.order_id = o.id "
                . "WHERE oi.product_id = {$prefix}products.id AND o.status IN ('completed', 'processing')"
                . ")"
            );
        }

        // 9. Sample Form & Submissions
        $formsTable = $this->table($prefix . 'forms');
        $formCount  = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}forms");
        if ((int) ($formCount['count'] ?? 0) === 0) {
            $formsTable->insert([
                [
                    'name'            => 'Contact Form',
                    'slug'            => 'contact-form',
                    'language'        => 'en',
                    'fields'          => json_encode([
                        ['name' => 'name', 'type' => 'text', 'label' => 'Your Name', 'required' => true],
                        ['name' => 'email', 'type' => 'email', 'label' => 'Your Email', 'required' => true],
                        ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true],
                    ]),
                    'settings'        => json_encode([
                        'captcha'          => true,
                        'captcha_type'     => 'math',
                        'allow_external'   => false,
                        'allowed_domains'  => [],
                        'honeypot_enabled' => false,
                        'allow_multiple'   => true,
                    ]),
                    'notify_email'    => 'admin@getvale.org',
                    'submit_label'    => 'Send Message',
                    'success_message' => 'Thank you for reaching out! We will get back to you shortly.',
                    'enabled'         => true,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ],
            ])->saveData();

            $contactForm = $this->fetchRow("SELECT id FROM {$prefix}forms WHERE slug = 'contact-form'");
            if ($contactForm) {
                $this->table($prefix . 'form_submissions')->insert([
                    [
                        'form_id'    => $contactForm['id'],
                        'data'       => json_encode(['name' => 'John Doe', 'email' => 'john@example.com', 'message' => 'Hello, I have a question regarding product availability.']),
                        'ip_address' => '127.0.0.1',
                        'is_read'    => false,
                        'created_at' => $now,
                    ],
                    [
                        'form_id'    => $contactForm['id'],
                        'data'       => json_encode(['name' => 'Alice Miller', 'email' => 'alice@example.com', 'message' => 'Great website! Interested in partnership opportunities.']),
                        'ip_address' => '127.0.0.1',
                        'is_read'    => true,
                        'created_at' => $now,
                    ],
                ])->saveData();
            }
        }

        // 10. Newsletter Subscribers & Automation Sequence
        $subscribersTable = $this->table($prefix . 'newsletter_subscribers');
        $subCount         = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}newsletter_subscribers");
        if ((int) ($subCount['count'] ?? 0) === 0) {
            $subscribersTable->insert([
                [
                    'user_id'       => $subscriberUserId,
                    'email'         => 'user@example.com',
                    'name'          => 'Jane Subscriber',
                    'status'        => 'confirmed',
                    'tier'          => 'free',
                    'token'         => bin2hex(random_bytes(16)),
                    'confirmed_at'  => $now,
                    'created_at'    => $now,
                ],
                [
                    'user_id'       => null,
                    'email'         => 'subscriber1@example.com',
                    'name'          => 'David Miller',
                    'status'        => 'active',
                    'tier'          => 'free',
                    'token'         => bin2hex(random_bytes(16)),
                    'confirmed_at'  => $now,
                    'created_at'    => $now,
                ],
                [
                    'user_id'       => null,
                    'email'         => 'subscriber2@example.com',
                    'name'          => 'Sophie Turner',
                    'status'        => 'pending',
                    'tier'          => 'free',
                    'token'         => bin2hex(random_bytes(16)),
                    'created_at'    => $now,
                ],
            ])->saveData();
        }

        // Subscriber membership tag
        $goldSubscriber = $this->fetchRow(
            "SELECT id FROM {$prefix}newsletter_subscribers WHERE email = 'subscriber1@example.com' LIMIT 1"
        );
        if ($goldSubscriber) {
            $existingGoldTag = $this->fetchRow(
                "SELECT id FROM {$prefix}newsletter_subscriber_tags WHERE subscriber_id = " . (int) $goldSubscriber['id'] . " AND tag = 'gold' LIMIT 1"
            );
            if (!$existingGoldTag) {
                $this->table($prefix . 'newsletter_subscriber_tags')->insert([
                    ['subscriber_id' => (int) $goldSubscriber['id'], 'tag' => 'gold'],
                ])->saveData();
            }
        }

        // 10b. Sample A/B newsletter campaign
        $campaignsTable   = $this->table($prefix . 'newsletter_campaigns');
        $existingCampaign = $this->fetchRow(
            "SELECT id FROM {$prefix}newsletter_campaigns WHERE subject = 'Hello there!' LIMIT 1"
        );
        if (!$existingCampaign) {
            $campaignsTable->insert([
                [
                    'subject'              => 'Hello there!',
                    'subject_b'            => ' How’d’y, pardner!',
                    'body_html'            => '<p>[subscriber_name|Hi there],</p><p>Thanks for joining our newsletter! Here we\'ll send you frequently some amazing content.</p><p>Here is the first set of them:</p><p>[latest_posts count="5" type="post" category="" tag=""]</p><p>[unsubscribe_url][open_pixel]</p>',
                    'body_html_b'          => '<p>[subscriber_name|Hey],</p><p>Thanks for joining our newsletter! Here we\'ll send you frequently some amazing content.</p><p>Here is the first set of them:</p><p>[latest_posts count="5" type="post" category="" tag=""]</p><p>[unsubscribe_url][open_pixel]</p><p><br><br></p>',
                    'from_name'            => 'Vale Newsletter',
                    'from_email'           => 'newsletter@getvale.org',
                    'status'               => 'draft',
                    'ab_test_enabled'      => true,
                    'variation_b_weight'   => 50,
                    'tracking_enabled'     => 3,
                    'opens_count'          => 0,
                    'opens_count_b'        => 0,
                    'clicks_count'         => 0,
                    'clicks_count_b'       => 0,
                    'is_recurring'         => true,
                    'recurring_interval'   => 'weekly',
                    'recurring_day'        => '1',
                    'recurring_time'       => '09:00',
                    'recurring_post_count' => 5,
                    'recurring_post_types' => 'post',
                    'created_at'           => $now,
                ],
            ])->saveData();
        }

        $autoSeqTable = $this->table($prefix . 'automation_sequences');
        $autoCount    = $this->fetchRow("SELECT COUNT(*) as count FROM {$prefix}automation_sequences");
        if ((int) ($autoCount['count'] ?? 0) === 0) {
            $autoSeqTable->insert([
                [
                    'name'         => 'Welcome Onboarding Series',
                    'trigger_type' => 'subscribe',
                    'status'       => 'active',
                    'created_at'   => $now,
                ],
            ])->saveData();

            $welcomeSeq = $this->fetchRow("SELECT id FROM {$prefix}automation_sequences WHERE name = 'Welcome Onboarding Series'");
            if ($welcomeSeq) {
                $this->table($prefix . 'automation_steps')->insert([
                    [
                        'sequence_id'   => $welcomeSeq['id'],
                        'sort_order'    => 1,
                        'delay_minutes' => 0,
                        'subject'       => 'Welcome to our community!',
                        'body_html'     => '<h1>Welcome aboard!</h1><p>Thank you for subscribing to our newsletter.</p>',
                        'created_at'    => $now,
                    ],
                    [
                        'sequence_id'   => $welcomeSeq['id'],
                        'sort_order'    => 2,
                        'delay_minutes' => 1440,
                        'subject'       => 'Here is a special 10% discount for you',
                        'body_html'     => '<h2>Special Gift</h2><p>Use coupon code <strong>WELCOME10</strong> on your first order!</p>',
                        'created_at'    => $now,
                    ],
                ])->saveData();
            }
        }
    }

    /**
     * Build a demo order billing JSON, encrypting the free-text PII fields when
     * an Encrypter is available. Without a key the billing is stored in
     * plaintext (matching the app's backward-compatible read path).
     *
     * @param array<string,mixed> $billing
     */
    private function demoBilling(array $billing): string
    {
        $encrypting = $this->encrypter !== null;
        foreach (['first_name', 'last_name', 'phone', 'company', 'address', 'address_2'] as $field) {
            if ($encrypting && isset($billing[$field]) && $billing[$field] !== '') {
                $billing[$field] = $this->encrypter->encrypt((string) $billing[$field]);
            }
        }
        return json_encode($billing);
    }

    private function notice(string $message): void
    {
        $this->getOutput()->writeln('<comment>' . $message . '</comment>');
    }
}
