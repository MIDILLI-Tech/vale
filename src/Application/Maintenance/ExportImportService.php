<?php

declare(strict_types=1);

namespace ValeApp\Application\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class ExportImportService
{
    public const string VERSION = '1.1.0';

    public const array SUPPORTED_TYPES = [
        'pages',
        'posts',
        'products',
        'orders',
        'users',
        'categories',
        'tags',
        'seo_meta',
        'site_settings',
        'media',
        'content_types',
        'menus',
        'comments',
        'forms',
        'coupons',
        'redirects',
        'tax_rates',
        'membership_plans',
    ];

    /**
     * Settings keys that are environment-specific and must NOT be overwritten
     * on import unless the caller explicitly opts in via ['include_secrets' => true].
     */
    private const SETTINGS_SKIP_EXACT = [
        'cron_secret',
    ];

    private const SETTINGS_SKIP_SUFFIXES = [
        '_secret',
        '_secret_key',
        '_api_key',
        '_api_secret',
        '_client_secret',
        '_smtp_pass',
    ];

    private const SETTINGS_SKIP_PREFIXES = [
        'sso_',
        'stripe_',
        'paypal_',
        'mollie_',
        'twocheckout_',
        'checkoutcom_',
        'mail_smtp_',
        'mail_mailgun_',
        'mail_sendgrid_',
        'mail_resend_',
        'mail_unosend_',
    ];

    private const SETTINGS_SKIP_CONTAINS = [
        'api_key',
        'smtp_pass',
    ];

    private array $idMapping = [
        'users'              => [],
        'media'              => [],
        'categories'         => [],
        'product_categories' => [],
        'tags'               => [],
        'pages'              => [],
        'posts'              => [],
        'products'           => [],
        'product_variations' => [],
        'content_types'      => [],
        'menus'              => [],
        'menu_items'         => [],
        'comments'           => [],
        'forms'              => [],
        'form_submissions'   => [],
        'coupons'            => [],
        'redirects'          => [],
        'tax_rates'          => [],
        'membership_plans'   => [],
    ];

    /** @var array<string, array<string, bool>> table => column => true */
    private array $columnCache = [];

    /** @var array<int, array{newId: int, oldParent: int|null, oldTranslation: int|null, oldSwap: int|null}> */
    private array $pendingPostHierarchy = [];

    /** @var array<int, array{newId: int, oldParent: int|null, oldTranslation: int|null, table: string, map: string}> */
    private array $pendingCategoryHierarchy = [];

    /** @var int[] */
    private array $insertedPostIds = [];

    /** @var int[] */
    private array $insertedProductIds = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SettingsRepositoryInterface $settingsRepository
    ) {
    }

    /**
     * @param string[] $types
     * @param array<string, mixed> $options ['include_secrets' => bool]
     * @return array<string, mixed>
     */
    public function export(array $types, array $options = []): array
    {
        $includeSecrets = (bool) ($options['include_secrets'] ?? false);

        $exportData = [
            'version'     => self::VERSION,
            'exported_at' => date('Y-m-d H:i:s'),
            'site_url'    => self::currentAbsoluteUrl(),
            'data'        => [],
        ];

        foreach ($types as $type) {
            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                continue;
            }

            $method = 'export' . str_replace('_', '', ucwords($type, '_'));
            if (method_exists($this, $method)) {
                $exportData['data'][$type] = $this->$method();
            }
        }

        $stripped = 0;
        if (!$includeSecrets && isset($exportData['data']['site_settings'])) {
            $filtered = [];
            foreach ($exportData['data']['site_settings'] as $setting) {
                if (isset($setting['key']) && $this->isSecretSetting((string) $setting['key'])) {
                    $stripped++;
                    continue;
                }
                $filtered[] = $setting;
            }
            $exportData['data']['site_settings'] = $filtered;
        }
        $exportData['secrets_stripped'] = $stripped;

        return $exportData;
    }

    public function createZip(string $jsonContent, string $jsonFilename): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension not found');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'vale_export');
        if ($tempFile === false) {
            throw new \RuntimeException('Cannot create temporary file');
        }
        $zip = new \ZipArchive();
        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create temporary zip file');
        }

        $zip->addFromString($jsonFilename, $jsonContent);
        $zip->close();

        return $tempFile;
    }

    private function exportPages(): array
    {
        $pages = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . "posts WHERE content_type = 'page'"
        );
        return $this->attachPostData($pages);
    }

    private function exportPosts(): array
    {
        $posts = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . "posts WHERE content_type != 'page'"
        );
        return $this->attachPostData($posts);
    }

    private function attachPostData(array $posts): array
    {
        foreach ($posts as &$post) {
            $postId             = (int) $post['id'];
            $post['categories'] = $this->db->fetchFirstColumn(
                'SELECT category_id FROM ' . VL_DB_PREFIX . 'post_categories WHERE post_id = ?',
                [$postId]
            );
            $post['tags'] = $this->db->fetchFirstColumn(
                'SELECT tag_id FROM ' . VL_DB_PREFIX . 'post_tags WHERE post_id = ?',
                [$postId]
            );
            $post['meta'] = $this->db->fetchAllAssociative(
                'SELECT meta_key, meta_value FROM ' . VL_DB_PREFIX . 'post_meta WHERE post_id = ?',
                [$postId]
            );
        }
        return $posts;
    }

    private function exportProducts(): array
    {
        $products = $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'products');
        foreach ($products as &$product) {
            $productId             = (int) $product['id'];
            $product['variations'] = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'product_variations WHERE product_id = ?',
                [$productId]
            );
            foreach ($product['variations'] as &$variation) {
                $variation['download_files'] = $this->tableExists('product_variation_download_files')
                    ? $this->db->fetchAllAssociative(
                        'SELECT * FROM ' . VL_DB_PREFIX . 'product_variation_download_files WHERE variation_id = ?',
                        [(int) $variation['id']]
                    )
                    : [];
            }
            $product['categories'] = $this->db->fetchFirstColumn(
                'SELECT category_id FROM ' . VL_DB_PREFIX . 'product_to_categories WHERE product_id = ?',
                [$productId]
            );
            $product['images'] = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'product_images WHERE product_id = ?',
                [$productId]
            );
            $product['download_files'] = $this->tableExists('product_download_files')
                ? $this->db->fetchAllAssociative(
                    'SELECT * FROM ' . VL_DB_PREFIX . 'product_download_files WHERE product_id = ?',
                    [$productId]
                )
                : [];
        }
        return $products;
    }

    private function exportOrders(): array
    {
        $orders = $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'orders');
        foreach ($orders as &$order) {
            $orderId        = (int) $order['id'];
            $order['items'] = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'order_items WHERE order_id = ?',
                [$orderId]
            );
            $order['logs'] = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'order_logs WHERE order_id = ?',
                [$orderId]
            );
            $order['downloads'] = $this->db->fetchAllAssociative(
                'SELECT * FROM ' . VL_DB_PREFIX . 'order_downloads WHERE order_id = ?',
                [$orderId]
            );
        }
        return $orders;
    }

    private function exportUsers(): array
    {
        $users         = $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'users');
        $roleSlugsById = $this->roleSlugMap();
        foreach ($users as &$user) {
            $roleIds = $this->db->fetchFirstColumn(
                'SELECT role_id FROM ' . VL_DB_PREFIX . 'user_roles WHERE user_id = ?',
                [(int) $user['id']]
            );
            $user['roles'] = $roleIds;
            $slugs         = [];
            foreach ($roleIds as $roleId) {
                if (isset($roleSlugsById[(int) $roleId])) {
                    $slugs[] = $roleSlugsById[(int) $roleId];
                }
            }
            $user['role_slugs'] = $slugs;
        }
        return $users;
    }

    private function exportCategories(): array
    {
        return [
            'content' => $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'categories'),
            'product' => $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'product_categories'),
        ];
    }

    private function exportTags(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'tags');
    }

    private function exportSeometa(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'seo_meta');
    }

    private function exportSitesettings(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'settings');
    }

    private function exportMedia(): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'media');
    }

    private function exportContenttypes(): array
    {
        if (!$this->tableExists('content_types')) {
            return [];
        }
        return $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'content_types');
    }

    private function exportMenus(): array
    {
        if (!$this->tableExists('menus')) {
            return [];
        }
        $menus = $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'menus');
        foreach ($menus as &$menu) {
            $menu['items'] = $this->tableExists('menu_items')
                ? $this->db->fetchAllAssociative(
                    'SELECT * FROM ' . VL_DB_PREFIX . 'menu_items WHERE menu_id = ? ORDER BY sort_order ASC, id ASC',
                    [(int) $menu['id']]
                )
                : [];
        }
        return $menus;
    }

    private function exportComments(): array
    {
        if (!$this->tableExists('comments')) {
            return [];
        }
        return $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'comments ORDER BY id ASC'
        );
    }

    private function exportForms(): array
    {
        if (!$this->tableExists('forms')) {
            return [];
        }
        $forms = $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'forms');
        foreach ($forms as &$form) {
            $form['submissions'] = $this->tableExists('form_submissions')
                ? $this->db->fetchAllAssociative(
                    'SELECT * FROM ' . VL_DB_PREFIX . 'form_submissions WHERE form_id = ?',
                    [(int) $form['id']]
                )
                : [];
        }
        return $forms;
    }

    private function exportCoupons(): array
    {
        if (!$this->tableExists('coupons')) {
            return [];
        }
        return $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'coupons');
    }

    private function exportRedirects(): array
    {
        if (!$this->tableExists('redirects')) {
            return [];
        }
        return $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'redirects');
    }

    private function exportTaxrates(): array
    {
        if (!$this->tableExists('country_tax_rates')) {
            return [];
        }
        return $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'country_tax_rates');
    }

    private function exportMembershipplans(): array
    {
        if (!$this->tableExists('membership_plans')) {
            return [];
        }
        return $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'membership_plans');
    }

    /**
     * @param array<string, mixed> $importData
     * @param array<string, mixed> $options ['include_secrets' => bool, 'rewrite_urls' => bool]
     * @return array<string, mixed>
     */
    public function import(array $importData, bool $dryRun = false, array $options = []): array
    {
        $this->resetRunState();

        $results = [
            'success'  => true,
            'imported' => [],
            'skipped'  => [],
            'errors'   => [],
            'warnings' => [],
        ];

        $data       = $importData['data'] ?? [];
        $oldSiteUrl = is_string($importData['site_url'] ?? null) ? (string) $importData['site_url'] : '';
        $version    = (string) ($importData['version'] ?? '');
        if ($version !== '' && $version !== self::VERSION && $version !== '1.0.0') {
            $results['warnings'][] = 'Export version ' . $version . ' differs from importer ' . self::VERSION . '; import continues on a best-effort basis.';
        }

        $includeSecrets = (bool) ($options['include_secrets'] ?? false);
        $rewriteUrls    = (bool) ($options['rewrite_urls'] ?? true);

        if (isset($data['media']) && !$dryRun) {
            $results['warnings'][] = 'Media files are NOT included in the export (database rows only). Copy the uploads directory manually, then run "php vale media:scan" (or Admin > Settings > Data > Rescan Media).';
        }

        $this->db->beginTransaction();
        try {
            // Order of import matters because of foreign keys
            $importOrder = [
                'content_types', 'users', 'media', 'categories', 'tags', 'pages', 'posts',
                'products', 'comments', 'menus', 'forms', 'coupons', 'tax_rates',
                'membership_plans', 'redirects', 'orders', 'seo_meta', 'site_settings',
            ];

            foreach ($importOrder as $type) {
                if (!isset($data[$type])) {
                    continue;
                }

                $method = 'import' . str_replace('_', '', ucwords($type, '_'));
                if (!method_exists($this, $method)) {
                    continue;
                }

                if ($type === 'site_settings') {
                    $typeResults = $this->importSitesettings(
                        is_array($data[$type]) ? $data[$type] : [],
                        $dryRun,
                        $includeSecrets
                    );
                } else {
                    $typeResults = $this->$method($data[$type], $dryRun);
                }
                $results['imported'][$type] = $typeResults['imported'] ?? 0;
                $results['skipped'][$type]  = $typeResults['skipped']  ?? 0;
                if (!empty($typeResults['errors'])) {
                    $results['errors'][$type] = $typeResults['errors'];
                }
                if (!empty($typeResults['warnings'])) {
                    foreach ($typeResults['warnings'] as $warning) {
                        $results['warnings'][] = $warning;
                    }
                }
            }

            if (!$dryRun) {
                $this->applyDeferredHierarchyFixups();
                if ($rewriteUrls) {
                    $rewritten = $this->rewriteImportedUrls($oldSiteUrl);
                    if ($rewritten > 0) {
                        $results['warnings'][] = 'Rewrote ' . $rewritten . ' content field(s) from ' . $oldSiteUrl . ' to the current site URL.';
                    }
                }
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }
        } catch (\Exception $e) {
            $this->db->rollBack();
            $results['success']          = false;
            $results['errors']['global'] = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
        }

        return $results;
    }

    private function importContenttypes(array $rows, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($rows as $row) {
            $oldId = (int) ($row['id'] ?? 0);
            unset($row['id']);
            if (!isset($row['slug'])) {
                $skipped++;
                continue;
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'content_types WHERE slug = ?',
                [$row['slug']]
            );
            if ($existing) {
                $newId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('content_types', $row);
                    $newId = (int) $this->db->lastInsertId();
                } else {
                    $newId = 0;
                }
                $imported++;
            }
            $this->idMapping['content_types'][$oldId] = $newId;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importUsers(array $users, bool $dryRun = false): array
    {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $roleIdBySlug = $this->roleIdMap();
        foreach ($users as $user) {
            $oldId     = (int) ($user['id'] ?? 0);
            $roleSlugs = $user['role_slugs'] ?? [];
            unset($user['id'], $user['roles'], $user['role_slugs']);

            if (!isset($user['email'])) {
                $skipped++;
                continue;
            }

            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'users WHERE email = ?',
                [$user['email']]
            );

            if ($existing) {
                $userId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    try {
                        $this->insertFiltered('users', $user);
                    } catch (\Exception $e) {
                        $errors[] = 'User ' . $user['email'] . ': ' . $e->getMessage();
                        $skipped++;
                        continue;
                    }
                    $userId = (int) $this->db->lastInsertId();

                    $targetRoleIds = $this->resolveRoleIds($roleSlugs, $roleIdBySlug);
                    foreach ($targetRoleIds as $roleId) {
                        try {
                            $this->db->insert(VL_DB_PREFIX . 'user_roles', [
                                'user_id' => $userId,
                                'role_id' => $roleId,
                            ]);
                        } catch (\Exception $e) {
                            // Skip if role doesn't exist / duplicate
                        }
                    }
                } else {
                    $userId = 0;
                }
                $imported++;
            }
            $this->idMapping['users'][$oldId] = $userId;
        }
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param mixed $roleSlugs
     * @param array<string, int> $roleIdBySlug
     * @return int[]
     */
    private function resolveRoleIds(mixed $roleSlugs, array $roleIdBySlug): array
    {
        $resolved = [];
        if (!is_array($roleSlugs)) {
            return [];
        }
        foreach ($roleSlugs as $slug) {
            if (isset($roleIdBySlug[(string) $slug])) {
                $resolved[] = $roleIdBySlug[(string) $slug];
            }
        }
        return array_values(array_unique($resolved));
    }

    private function importMedia(array $mediaItems, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($mediaItems as $media) {
            // Reject poisoned relative paths that would escape the uploads
            // directory (delete/unlink targets derived from this column).
            if (
                !isset($media['path']) || !is_string($media['path'])
                                       || str_contains($media['path'], '..')
                                       || !preg_match('#^[a-z0-9/_\-\.]+$#i', $media['path'])
            ) {
                $skipped++;
                continue;
            }
            $oldId = (int) ($media['id'] ?? 0);
            unset($media['id']);
            $media['user_id'] = $this->resolveUserId($media['user_id'] ?? null);
            if ($media['user_id'] === null) {
                $skipped++;
                continue;
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'media WHERE path = ?',
                [$media['path']]
            );
            if ($existing) {
                $mediaId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('media', $media);
                    $mediaId = (int) $this->db->lastInsertId();
                } else {
                    $mediaId = 0;
                }
                $imported++;
            }
            $this->idMapping['media'][$oldId] = $mediaId;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importCategories(array $categories, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;

        foreach ($categories['content'] ?? [] as $cat) {
            $oldId          = (int) ($cat['id'] ?? 0);
            $oldParent      = isset($cat['parent_id'])      && $cat['parent_id']      !== null ? (int) $cat['parent_id'] : null;
            $oldTranslation = isset($cat['translation_of']) && $cat['translation_of'] !== null ? (int) $cat['translation_of'] : null;
            unset($cat['id']);
            $cat['parent_id'] = null;
            $existing         = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'categories WHERE slug = ? AND content_type = ? AND language = ?',
                [$cat['slug'], $cat['content_type'], $cat['language']]
            );
            if ($existing) {
                $catId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('categories', $cat);
                    $catId = (int) $this->db->lastInsertId();
                } else {
                    $catId = 0;
                }
                $imported++;
            }
            $this->idMapping['categories'][$oldId] = $catId;
            if ($catId > 0 && ($oldParent !== null || $oldTranslation !== null)) {
                $this->pendingCategoryHierarchy[] = [
                    'newId'          => $catId, 'oldParent' => $oldParent,
                    'oldTranslation' => $oldTranslation, 'table' => 'categories', 'map' => 'categories',
                ];
            }
        }

        foreach ($categories['product'] ?? [] as $cat) {
            $oldId          = (int) ($cat['id'] ?? 0);
            $oldParent      = isset($cat['parent_id'])      && $cat['parent_id']      !== null ? (int) $cat['parent_id'] : null;
            $oldTranslation = isset($cat['translation_of']) && $cat['translation_of'] !== null ? (int) $cat['translation_of'] : null;
            unset($cat['id']);
            $cat['parent_id'] = null;
            if (isset($cat['image_id']) && $cat['image_id']) {
                $cat['image_id'] = $this->idMapping['media'][(int) $cat['image_id']] ?? null;
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'product_categories WHERE slug = ? AND language = ?',
                [$cat['slug'], $cat['language']]
            );
            if ($existing) {
                $catId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('product_categories', $cat);
                    $catId = (int) $this->db->lastInsertId();
                } else {
                    $catId = 0;
                }
                $imported++;
            }
            $this->idMapping['product_categories'][$oldId] = $catId;
            if ($catId > 0 && ($oldParent !== null || $oldTranslation !== null)) {
                $this->pendingCategoryHierarchy[] = [
                    'newId'          => $catId, 'oldParent' => $oldParent,
                    'oldTranslation' => $oldTranslation, 'table' => 'product_categories', 'map' => 'product_categories',
                ];
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importTags(array $tags, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($tags as $tag) {
            $oldId          = (int) ($tag['id'] ?? 0);
            $oldTranslation = isset($tag['translation_of']) && $tag['translation_of'] !== null ? (int) $tag['translation_of'] : null;
            unset($tag['id']);
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'tags WHERE slug = ? AND content_type = ? AND language = ?',
                [$tag['slug'], $tag['content_type'], $tag['language']]
            );
            if ($existing) {
                $tagId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $tag['translation_of'] = null;
                    $this->insertFiltered('tags', $tag);
                    $tagId = (int) $this->db->lastInsertId();
                    if ($oldTranslation !== null) {
                        $newTranslation = $this->idMapping['tags'][$oldTranslation] ?? null;
                        if ($newTranslation) {
                            $this->db->update(VL_DB_PREFIX . 'tags', ['translation_of' => $newTranslation], ['id' => $tagId]);
                        }
                    }
                } else {
                    $tagId = 0;
                }
                $imported++;
            }
            $this->idMapping['tags'][$oldId] = $tagId;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importPages(array $pages, bool $dryRun = false): array
    {
        return $this->importPostsInternal($pages, $dryRun, true);
    }

    private function importPosts(array $posts, bool $dryRun = false): array
    {
        return $this->importPostsInternal($posts, $dryRun, false);
    }

    private function importPostsInternal(array $posts, bool $dryRun = false, bool $isPage = false): array
    {
        $imported   = 0;
        $skipped    = 0;
        $mappingKey = $isPage ? 'pages' : 'posts';

        foreach ($posts as $post) {
            $oldId      = (int) ($post['id'] ?? 0);
            $categories = $post['categories'] ?? [];
            $tags       = $post['tags']       ?? [];
            $meta       = $post['meta']       ?? [];
            unset($post['id'], $post['categories'], $post['tags'], $post['meta']);

            $oldParent              = isset($post['parent_id'])      && $post['parent_id']      !== null ? (int) $post['parent_id'] : null;
            $oldTranslation         = isset($post['translation_of']) && $post['translation_of'] !== null ? (int) $post['translation_of'] : null;
            $oldSwap                = isset($post['swap_post_id'])   && $post['swap_post_id']   !== null ? (int) $post['swap_post_id'] : null;
            $post['parent_id']      = null;
            $post['translation_of'] = null;
            if (array_key_exists('swap_post_id', $post)) {
                $post['swap_post_id'] = null;
            }

            $post['author_id'] = $this->resolveUserId($post['author_id'] ?? null);
            if ($post['author_id'] === null) {
                $skipped++;
                continue;
            }
            if (array_key_exists('featured_image_id', $post) && $post['featured_image_id']) {
                $post['featured_image_id'] = $this->idMapping['media'][(int) $post['featured_image_id']] ?? null;
            }

            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'posts WHERE slug = ? AND content_type = ? AND language = ?',
                [$post['slug'], $post['content_type'], $post['language']]
            );
            if ($existing) {
                $postId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('posts', $post);
                    $postId                  = (int) $this->db->lastInsertId();
                    $this->insertedPostIds[] = $postId;

                    foreach (is_array($categories) ? $categories : [] as $oldCatId) {
                        $newCatId = $this->idMapping['categories'][(int) $oldCatId] ?? null;
                        if ($newCatId) {
                            try {
                                $this->db->insert(VL_DB_PREFIX . 'post_categories', ['post_id' => $postId, 'category_id' => $newCatId]);
                            } catch (\Exception) {
                            }
                        }
                    }
                    foreach (is_array($tags) ? $tags : [] as $oldTagId) {
                        $newTagId = $this->idMapping['tags'][(int) $oldTagId] ?? null;
                        if ($newTagId) {
                            try {
                                $this->db->insert(VL_DB_PREFIX . 'post_tags', ['post_id' => $postId, 'tag_id' => $newTagId]);
                            } catch (\Exception) {
                            }
                        }
                    }
                    foreach (is_array($meta) ? $meta : [] as $m) {
                        if (!isset($m['meta_key'])) {
                            continue;
                        }
                        $this->db->insert(VL_DB_PREFIX . 'post_meta', [
                            'post_id'    => $postId,
                            'meta_key'   => $m['meta_key'],
                            'meta_value' => $m['meta_value'] ?? null,
                        ]);
                    }
                } else {
                    $postId = 0;
                }
                $imported++;
            }
            $this->idMapping[$mappingKey][$oldId] = $postId;
            if ($postId > 0 && ($oldParent !== null || $oldTranslation !== null || $oldSwap !== null)) {
                $this->pendingPostHierarchy[] = [
                    'newId'          => $postId, 'oldParent' => $oldParent,
                    'oldTranslation' => $oldTranslation, 'oldSwap' => $oldSwap,
                ];
            }
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importProducts(array $products, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($products as $product) {
            $oldId         = (int) ($product['id'] ?? 0);
            $variations    = $product['variations']     ?? [];
            $categories    = $product['categories']     ?? [];
            $images        = $product['images']         ?? [];
            $downloadFiles = $product['download_files'] ?? [];
            unset($product['id'], $product['variations'], $product['categories'], $product['images'], $product['download_files']);

            $oldTranslation            = isset($product['translation_of']) && $product['translation_of'] !== null ? (int) $product['translation_of'] : null;
            $product['translation_of'] = null;

            if (array_key_exists('featured_image_id', $product) && $product['featured_image_id']) {
                $product['featured_image_id'] = $this->idMapping['media'][(int) $product['featured_image_id']] ?? null;
            }
            if (isset($product['download_file_id']) && $product['download_file_id']) {
                $product['download_file_id'] = $this->idMapping['media'][(int) $product['download_file_id']] ?? null;
            }
            foreach (['upsell_ids', 'cross_sell_ids'] as $relKey) {
                if (isset($product[$relKey]) && $product[$relKey] !== null && $product[$relKey] !== '') {
                    $product[$relKey] = $this->remapIdList($product[$relKey], $this->idMapping['products']);
                }
            }

            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'products WHERE slug = ? AND language = ?',
                [$product['slug'], $product['language']]
            );
            if ($existing) {
                $productId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('products', $product);
                    $productId                  = (int) $this->db->lastInsertId();
                    $this->insertedProductIds[] = $productId;
                    if ($oldTranslation !== null && isset($this->idMapping['products'][$oldTranslation])) {
                        $newTranslation = $this->idMapping['products'][$oldTranslation];
                        if ($newTranslation) {
                            $this->db->update(VL_DB_PREFIX . 'products', ['translation_of' => $newTranslation], ['id' => $productId]);
                        }
                    }

                    foreach (is_array($variations) ? $variations : [] as $variation) {
                        $oldVarId     = (int) ($variation['id'] ?? 0);
                        $varDownloads = $variation['download_files'] ?? [];
                        unset($variation['id'], $variation['product_id'], $variation['download_files']);
                        $variation['product_id'] = $productId;
                        if (isset($variation['image_id']) && $variation['image_id']) {
                            $variation['image_id'] = $this->idMapping['media'][(int) $variation['image_id']] ?? null;
                        }
                        if (isset($variation['download_file_id']) && $variation['download_file_id']) {
                            $variation['download_file_id'] = $this->idMapping['media'][(int) $variation['download_file_id']] ?? null;
                        }
                        $this->insertFiltered('product_variations', $variation);
                        $newVarId                                         = (int) $this->db->lastInsertId();
                        $this->idMapping['product_variations'][$oldVarId] = $newVarId;
                        foreach (is_array($varDownloads) ? $varDownloads : [] as $dl) {
                            if (!$this->tableExists('product_variation_download_files')) {
                                break;
                            }
                            unset($dl['id'], $dl['variation_id']);
                            $dl['variation_id'] = $newVarId;
                            if (isset($dl['media_id']) && $dl['media_id']) {
                                $dl['media_id'] = $this->idMapping['media'][(int) $dl['media_id']] ?? $dl['media_id'];
                            }
                            $this->insertFiltered('product_variation_download_files', $dl);
                        }
                    }

                    foreach (is_array($categories) ? $categories : [] as $oldCatId) {
                        $newCatId = $this->idMapping['product_categories'][(int) $oldCatId] ?? null;
                        if ($newCatId) {
                            try {
                                $this->db->insert(VL_DB_PREFIX . 'product_to_categories', [
                                    'product_id'  => $productId,
                                    'category_id' => $newCatId,
                                ]);
                            } catch (\Exception) {
                            }
                        }
                    }

                    foreach (is_array($images) ? $images : [] as $img) {
                        unset($img['id'], $img['product_id']);
                        $img['product_id'] = $productId;
                        if (isset($img['media_id']) && $img['media_id']) {
                            $img['media_id'] = $this->idMapping['media'][(int) $img['media_id']] ?? $img['media_id'];
                        }
                        $this->insertFiltered('product_images', $img);
                    }

                    foreach (is_array($downloadFiles) ? $downloadFiles : [] as $dl) {
                        if (!$this->tableExists('product_download_files')) {
                            break;
                        }
                        unset($dl['id'], $dl['product_id']);
                        $dl['product_id'] = $productId;
                        if (isset($dl['media_id']) && $dl['media_id']) {
                            $dl['media_id'] = $this->idMapping['media'][(int) $dl['media_id']] ?? $dl['media_id'];
                        }
                        $this->insertFiltered('product_download_files', $dl);
                    }
                } else {
                    $productId = 0;
                }
                $imported++;
            }
            $this->idMapping['products'][$oldId] = $productId;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importComments(array $rows, bool $dryRun = false): array
    {
        $imported       = 0;
        $skipped        = 0;
        $pendingParents = [];
        foreach ($rows as $row) {
            $oldId     = (int) ($row['id'] ?? 0);
            $oldParent = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            unset($row['id']);
            $row['parent_id'] = null;
            $newEntityId      = $this->mapEntityId((string) ($row['entity_type'] ?? 'post'), (int) ($row['entity_id'] ?? 0));
            if (!$newEntityId) {
                $skipped++;
                continue;
            }
            $row['entity_id'] = $newEntityId;
            if (isset($row['user_id']) && $row['user_id']) {
                $resolved       = $this->idMapping['users'][(int) $row['user_id']] ?? null;
                $row['user_id'] = $resolved ?: null;
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'comments WHERE entity_type = ? AND entity_id = ? AND author_email = ? AND body = ?',
                [$row['entity_type'], $row['entity_id'], $row['author_email'] ?? '', $row['body'] ?? '']
            );
            if ($existing) {
                $newId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('comments', $row);
                    $newId = (int) $this->db->lastInsertId();
                } else {
                    $newId = 0;
                }
                $imported++;
            }
            $this->idMapping['comments'][$oldId] = $newId;
            if ($newId > 0 && $oldParent !== null) {
                $pendingParents[] = ['newId' => $newId, 'oldParent' => $oldParent];
            }
        }
        if (!$dryRun) {
            foreach ($pendingParents as $p) {
                $newParent = $this->idMapping['comments'][$p['oldParent']] ?? null;
                if ($newParent) {
                    $this->db->update(VL_DB_PREFIX . 'comments', ['parent_id' => $newParent], ['id' => $p['newId']]);
                }
            }
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importMenus(array $rows, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($rows as $menu) {
            $oldId = (int) ($menu['id'] ?? 0);
            $items = $menu['items'] ?? [];
            unset($menu['id'], $menu['items']);
            $oldTranslation         = isset($menu['translation_of']) && $menu['translation_of'] !== null ? (int) $menu['translation_of'] : null;
            $menu['translation_of'] = null;
            $existing               = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'menus WHERE slug = ?',
                [$menu['slug'] ?? '']
            );
            if ($existing) {
                $menuId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('menus', $menu);
                    $menuId = (int) $this->db->lastInsertId();
                } else {
                    $menuId = 0;
                }
                $imported++;
            }
            $this->idMapping['menus'][$oldId] = $menuId;
            if ($menuId > 0 && !$dryRun) {
                if ($oldTranslation !== null && isset($this->idMapping['menus'][$oldTranslation])) {
                    $newTranslation = $this->idMapping['menus'][$oldTranslation];
                    if ($newTranslation) {
                        $this->db->update(VL_DB_PREFIX . 'menus', ['translation_of' => $newTranslation], ['id' => $menuId]);
                    }
                }
                $this->importMenuItems($menuId, is_array($items) ? $items : []);
            } elseif ($menuId > 0 && $dryRun) {
                foreach (is_array($items) ? $items : [] as $item) {
                    $this->idMapping['menu_items'][(int) ($item['id'] ?? 0)] = 0;
                }
            }
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function importMenuItems(int $menuId, array $items): void
    {
        $oldToNew   = [];
        $oldParents = [];
        $oldRefs    = [];
        foreach ($items as $item) {
            $oldItemId              = (int) ($item['id'] ?? 0);
            $oldParents[$oldItemId] = isset($item['parent_id']) && $item['parent_id'] !== null ? (int) $item['parent_id'] : null;
            $oldRefs[$oldItemId]    = [
                'type' => (string) ($item['type'] ?? 'custom'),
                'ref'  => isset($item['reference_id']) && $item['reference_id'] !== null ? (int) $item['reference_id'] : null,
            ];
            unset($item['id'], $item['menu_id'], $item['parent_id']);
            $item['menu_id']      = $menuId;
            $item['reference_id'] = null;
            $this->insertFiltered('menu_items', $item);
            $newItemId                                 = (int) $this->db->lastInsertId();
            $oldToNew[$oldItemId]                      = $newItemId;
            $this->idMapping['menu_items'][$oldItemId] = $newItemId;
        }
        foreach ($oldToNew as $oldItemId => $newItemId) {
            $updates   = [];
            $oldParent = $oldParents[$oldItemId] ?? null;
            if ($oldParent !== null && isset($oldToNew[$oldParent])) {
                $updates['parent_id'] = $oldToNew[$oldParent];
            }
            $ref = $oldRefs[$oldItemId] ?? null;
            if ($ref !== null && $ref['ref'] !== null) {
                $mapped = $this->mapMenuReference($ref['type'], $ref['ref']);
                if ($mapped !== null) {
                    $updates['reference_id'] = $mapped;
                }
            }
            if ($updates !== []) {
                $this->db->update(VL_DB_PREFIX . 'menu_items', $updates, ['id' => $newItemId]);
            }
        }
    }

    private function importForms(array $rows, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($rows as $form) {
            $oldId       = (int) ($form['id'] ?? 0);
            $submissions = $form['submissions'] ?? [];
            unset($form['id'], $form['submissions']);
            $oldTranslation         = isset($form['translation_of']) && $form['translation_of'] !== null ? (int) $form['translation_of'] : null;
            $form['translation_of'] = null;
            $existing               = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'forms WHERE slug = ?',
                [$form['slug'] ?? '']
            );
            if ($existing) {
                $formId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('forms', $form);
                    $formId = (int) $this->db->lastInsertId();
                    if ($oldTranslation !== null && isset($this->idMapping['forms'][$oldTranslation])) {
                        $newTranslation = $this->idMapping['forms'][$oldTranslation];
                        if ($newTranslation) {
                            $this->db->update(VL_DB_PREFIX . 'forms', ['translation_of' => $newTranslation], ['id' => $formId]);
                        }
                    }
                    foreach (is_array($submissions) ? $submissions : [] as $sub) {
                        $oldSubId = (int) ($sub['id'] ?? 0);
                        unset($sub['id'], $sub['form_id']);
                        $sub['form_id'] = $formId;
                        if (!isset($sub['submitter_email'])) {
                            $sub['submitter_email'] = $this->normalizeSubmitterEmailFromData($sub['data'] ?? null);
                        }
                        $this->insertFiltered('form_submissions', $sub);
                        $this->idMapping['form_submissions'][$oldSubId] = (int) $this->db->lastInsertId();
                    }
                } else {
                    $formId = 0;
                }
                $imported++;
            }
            $this->idMapping['forms'][$oldId] = $formId;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Legacy exports carry no submitter_email column — derive it from the
     * submission payload so email-based duplicate detection keeps working.
     */
    private function normalizeSubmitterEmailFromData(mixed $rawData): ?string
    {
        if (!is_string($rawData) || $rawData === '') {
            return null;
        }
        $data  = json_decode($rawData, true) ?? [];
        $email = is_array($data) ? ($data['email'] ?? null) : null;
        if (!is_string($email)) {
            return null;
        }
        $email = mb_strtolower(trim($email));
        return $email !== '' ? $email : null;
    }

    private function importCoupons(array $rows, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($rows as $row) {
            $oldId = (int) ($row['id'] ?? 0);
            unset($row['id']);
            if (!isset($row['code'])) {
                $skipped++;
                continue;
            }
            foreach (['allowed_product_ids', 'blocked_product_ids'] as $k) {
                if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') {
                    $row[$k] = $this->remapIdList($row[$k], $this->idMapping['products']);
                }
            }
            foreach (['allowed_category_ids', 'blocked_category_ids'] as $k) {
                if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') {
                    $row[$k] = $this->remapIdList($row[$k], $this->idMapping['product_categories']);
                }
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'coupons WHERE code = ?',
                [$row['code']]
            );
            if ($existing) {
                $newId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    if (array_key_exists('uses_count', $row)) {
                        $row['uses_count'] = 0;
                    }
                    $this->insertFiltered('coupons', $row);
                    $newId = (int) $this->db->lastInsertId();
                } else {
                    $newId = 0;
                }
                $imported++;
            }
            $this->idMapping['coupons'][$oldId] = $newId;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importRedirects(array $rows, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($rows as $row) {
            $oldId = (int) ($row['id'] ?? 0);
            unset($row['id']);
            if (!isset($row['old_path'])) {
                $skipped++;
                continue;
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'redirects WHERE old_path = ?',
                [$row['old_path']]
            );
            if ($existing) {
                $newId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('redirects', $row);
                    $newId = (int) $this->db->lastInsertId();
                } else {
                    $newId = 0;
                }
                $imported++;
            }
            $this->idMapping['redirects'][$oldId] = $newId;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importTaxrates(array $rows, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($rows as $row) {
            $oldId = (int) ($row['id'] ?? 0);
            unset($row['id']);
            if (!isset($row['country_code'])) {
                $skipped++;
                continue;
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'country_tax_rates WHERE country_code = ?',
                [$row['country_code']]
            );
            if ($existing) {
                $newId = (int) $existing;
                if (!$dryRun) {
                    $this->updateFiltered('country_tax_rates', $row, ['id' => $existing]);
                }
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('country_tax_rates', $row);
                    $newId = (int) $this->db->lastInsertId();
                } else {
                    $newId = 0;
                }
                $imported++;
            }
            $this->idMapping['tax_rates'][$oldId] = $newId;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importMembershipplans(array $rows, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($rows as $row) {
            $oldId = (int) ($row['id'] ?? 0);
            unset($row['id']);
            if (!isset($row['slug'])) {
                $skipped++;
                continue;
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'membership_plans WHERE slug = ?',
                [$row['slug']]
            );
            if ($existing) {
                $newId = (int) $existing;
                $skipped++;
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('membership_plans', $row);
                    $newId = (int) $this->db->lastInsertId();
                } else {
                    $newId = 0;
                }
                $imported++;
            }
            $this->idMapping['membership_plans'][$oldId] = $newId;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importOrders(array $orders, bool $dryRun = false): array
    {
        $imported = 0;
        $skipped  = 0;
        foreach ($orders as $order) {
            $items     = $order['items']     ?? [];
            $logs      = $order['logs']      ?? [];
            $downloads = $order['downloads'] ?? [];
            unset($order['id'], $order['items'], $order['logs'], $order['downloads']);

            if (isset($order['user_id']) && $order['user_id']) {
                $mapped           = $this->idMapping['users'][(int) $order['user_id']] ?? null;
                $order['user_id'] = $mapped ?: null;
            }
            if (isset($order['plan_id']) && $order['plan_id']) {
                $order['plan_id'] = $this->idMapping['membership_plans'][(int) $order['plan_id']] ?? null;
            }

            if (!isset($order['order_number'])) {
                $skipped++;
                continue;
            }
            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'orders WHERE order_number = ?',
                [$order['order_number']]
            );
            if ($existing) {
                $skipped++;
                continue;
            }

            if (!$dryRun) {
                $this->insertFiltered('orders', $order);
                $orderId = (int) $this->db->lastInsertId();

                foreach (is_array($items) ? $items : [] as $item) {
                    unset($item['id'], $item['order_id']);
                    $item['order_id'] = $orderId;
                    if (isset($item['product_id']) && $item['product_id']) {
                        $item['product_id'] = $this->idMapping['products'][(int) $item['product_id']] ?? null;
                    }
                    if (isset($item['variation_id']) && $item['variation_id']) {
                        $item['variation_id'] = $this->idMapping['product_variations'][(int) $item['variation_id']] ?? null;
                    }
                    $this->insertFiltered('order_items', $item);
                }

                foreach (is_array($logs) ? $logs : [] as $log) {
                    unset($log['id'], $log['order_id']);
                    $log['order_id'] = $orderId;
                    if (isset($log['user_id']) && $log['user_id']) {
                        $log['user_id'] = $this->idMapping['users'][(int) $log['user_id']] ?? null;
                    }
                    $this->insertFiltered('order_logs', $log);
                }

                foreach (is_array($downloads) ? $downloads : [] as $dl) {
                    unset($dl['id'], $dl['order_id']);
                    $dl['order_id'] = $orderId;
                    if (isset($dl['product_id']) && $dl['product_id']) {
                        $dl['product_id'] = $this->idMapping['products'][(int) $dl['product_id']] ?? null;
                    }
                    if (isset($dl['variation_id']) && $dl['variation_id']) {
                        $dl['variation_id'] = $this->idMapping['product_variations'][(int) $dl['variation_id']] ?? null;
                    }
                    if (isset($dl['source_file_id']) && $dl['source_file_id']) {
                        $dl['source_file_id'] = $this->idMapping['media'][(int) $dl['source_file_id']] ?? null;
                    }
                    $this->insertFiltered('order_downloads', $dl);
                }
            }
            $imported++;
        }
        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function importSeometa(array $seoMetas, bool $dryRun = false): array
    {
        $imported = 0;
        foreach ($seoMetas as $meta) {
            unset($meta['id']);
            if (!isset($meta['entity_type'], $meta['entity_id'])) {
                continue;
            }
            $oldEntityId = (int) $meta['entity_id'];
            $entityType  = (string) $meta['entity_type'];

            $newEntityId = $this->mapEntityId($entityType, $oldEntityId);
            if (!$newEntityId) {
                continue;
            }
            $meta['entity_id'] = $newEntityId;
            if (isset($meta['og_image_id']) && $meta['og_image_id']) {
                $meta['og_image_id'] = $this->idMapping['media'][(int) $meta['og_image_id']] ?? null;
            }

            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'seo_meta WHERE entity_type = ? AND entity_id = ?',
                [$meta['entity_type'], $meta['entity_id']]
            );
            if ($existing) {
                if (!$dryRun) {
                    $this->updateFiltered('seo_meta', $meta, ['id' => $existing]);
                }
            } else {
                if (!$dryRun) {
                    $this->insertFiltered('seo_meta', $meta);
                }
            }
            $imported++;
        }
        return ['imported' => $imported, 'skipped' => 0];
    }

    private function mapEntityId(string $type, int $oldId): ?int
    {
        $mapType = match ($type) {
            'post'             => 'posts',
            'page'             => 'pages',
            'product'          => 'products',
            'category'         => 'categories',
            'product_category' => 'product_categories',
            'tag'              => 'tags',
            default            => null
        };

        if (!$mapType) {
            return $oldId;
        }

        return $this->idMapping[$mapType][$oldId] ?? null;
    }

    private function mapMenuReference(string $type, int $oldRef): ?int
    {
        return match ($type) {
            'page'     => $this->idMapping['pages'][$oldRef]    ?? null,
            'post'     => $this->idMapping['posts'][$oldRef]    ?? null,
            'product'  => $this->idMapping['products'][$oldRef] ?? null,
            'category' => $this->idMapping['categories'][$oldRef]
                ?? $this->idMapping['product_categories'][$oldRef] ?? null,
            default => $this->idMapping['posts'][$oldRef]
                ?? $this->idMapping['pages'][$oldRef]
                ?? $this->idMapping['products'][$oldRef]
                ?? $this->idMapping['categories'][$oldRef] ?? null,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $settings
     * @return array{imported: int, skipped: int, warnings?: string[]}
     */
    private function importSitesettings(array $settings, bool $dryRun = false, bool $includeSecrets = false): array
    {
        $imported      = 0;
        $skipped       = 0;
        $secretSkipped = 0;
        $table         = VL_DB_PREFIX . 'settings';
        $keyCol        = $this->db->quoteIdentifier('key');
        $valueCol      = $this->db->quoteIdentifier('value');
        $typeCol       = $this->db->quoteIdentifier('type');

        foreach ($settings as $setting) {
            unset($setting['id']);

            if (!isset($setting['group_name'], $setting['key'])) {
                $skipped++;
                continue;
            }

            if (!$includeSecrets && $this->isSecretSetting((string) $setting['key'])) {
                $secretSkipped++;
                $skipped++;
                continue;
            }

            $existing = $this->db->fetchOne(
                'SELECT id FROM ' . $table . ' WHERE group_name = ? AND ' . $keyCol . ' = ?',
                [$setting['group_name'], $setting['key']]
            );

            if ($dryRun) {
                $imported++;
                continue;
            }

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            if ($existing) {
                $this->db->executeStatement(
                    'UPDATE ' . $table . ' SET ' . $valueCol . ' = ?, ' . $typeCol . ' = ?, is_autoload = ?, updated_at = ? WHERE id = ?',
                    [
                        $setting['value']       ?? null,
                        $setting['type']        ?? 'string',
                        $setting['is_autoload'] ?? true,
                        $now,
                        $existing,
                    ]
                );
            } else {
                $this->db->executeStatement(
                    'INSERT INTO ' . $table . ' (group_name, ' . $keyCol . ', ' . $valueCol . ', ' . $typeCol . ', is_autoload, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $setting['group_name'],
                        $setting['key'],
                        $setting['value']       ?? null,
                        $setting['type']        ?? 'string',
                        $setting['is_autoload'] ?? true,
                        $now,
                        $now,
                    ],
                    [
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::BOOLEAN,
                        ParameterType::STRING,
                        ParameterType::STRING,
                    ]
                );
            }
            $imported++;
        }

        $result = ['imported' => $imported, 'skipped' => $skipped];
        if ($secretSkipped > 0) {
            $result['warnings'] = [
                $secretSkipped . ' secret setting(s) skipped (API keys / secrets). Re-enter them on the target instance or re-import with secret inclusion enabled.',
            ];
        }
        return $result;
    }

    public function isSecretSetting(string $key): bool
    {
        if (in_array($key, self::SETTINGS_SKIP_EXACT, true)) {
            return true;
        }
        foreach (self::SETTINGS_SKIP_SUFFIXES as $suffix) {
            if ($suffix !== '' && str_ends_with($key, $suffix)) {
                return true;
            }
        }
        foreach (self::SETTINGS_SKIP_PREFIXES as $prefix) {
            if ($prefix !== '' && str_starts_with($key, $prefix)) {
                return true;
            }
        }
        foreach (self::SETTINGS_SKIP_CONTAINS as $needle) {
            if ($needle !== '' && str_contains($key, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function applyDeferredHierarchyFixups(): void
    {
        foreach ($this->pendingCategoryHierarchy as $p) {
            $updates = [];
            if ($p['oldParent'] !== null && isset($this->idMapping[$p['map']][$p['oldParent']])) {
                $newParent = $this->idMapping[$p['map']][$p['oldParent']];
                if ($newParent && $newParent !== $p['newId']) {
                    $updates['parent_id'] = $newParent;
                }
            }
            if ($p['oldTranslation'] !== null && isset($this->idMapping[$p['map']][$p['oldTranslation']])) {
                $newTranslation = $this->idMapping[$p['map']][$p['oldTranslation']];
                if ($newTranslation && $newTranslation !== $p['newId']) {
                    $updates['translation_of'] = $newTranslation;
                }
            }
            if ($updates !== []) {
                $this->updateFiltered($p['table'], $updates, ['id' => $p['newId']]);
            }
        }

        foreach ($this->pendingPostHierarchy as $p) {
            $updates = [];
            $postMap = $this->idMapping['pages'] + $this->idMapping['posts'];
            if ($p['oldParent'] !== null && isset($postMap[$p['oldParent']])) {
                $newParent = $postMap[$p['oldParent']];
                if ($newParent && $newParent !== $p['newId']) {
                    $updates['parent_id'] = $newParent;
                }
            }
            if ($p['oldTranslation'] !== null && isset($postMap[$p['oldTranslation']])) {
                $newTranslation = $postMap[$p['oldTranslation']];
                if ($newTranslation && $newTranslation !== $p['newId']) {
                    $updates['translation_of'] = $newTranslation;
                }
            }
            if ($p['oldSwap'] !== null && isset($postMap[$p['oldSwap']])) {
                $newSwap = $postMap[$p['oldSwap']];
                if ($newSwap && $newSwap !== $p['newId']) {
                    $updates['swap_post_id'] = $newSwap;
                }
            }
            if ($updates !== []) {
                $this->updateFiltered('posts', $updates, ['id' => $p['newId']]);
            }
        }
    }

    private function rewriteImportedUrls(string $oldSiteUrl): int
    {
        $newSiteUrl = self::currentAbsoluteUrl();
        $oldSiteUrl = rtrim($oldSiteUrl, '/');
        $newSiteUrl = rtrim($newSiteUrl, '/');
        if ($oldSiteUrl === '' || $newSiteUrl === '' || $oldSiteUrl === $newSiteUrl) {
            return 0;
        }
        // A relative site_url carries no domain to rewrite from.
        if (!str_contains($oldSiteUrl, '://')) {
            return 0;
        }

        $rewritten = 0;
        foreach ($this->insertedPostIds as $postId) {
            $row = $this->db->fetchAssociative(
                'SELECT body FROM ' . VL_DB_PREFIX . 'posts WHERE id = ?',
                [$postId]
            );
            if (!is_array($row) || !isset($row['body']) || !str_contains((string) $row['body'], $oldSiteUrl)) {
                continue;
            }
            $updated = str_replace($oldSiteUrl, $newSiteUrl, (string) $row['body']);
            $this->db->update(VL_DB_PREFIX . 'posts', ['body' => $updated], ['id' => $postId]);
            $rewritten++;
        }
        foreach ($this->insertedProductIds as $productId) {
            $row = $this->db->fetchAssociative(
                'SELECT description, short_description FROM ' . VL_DB_PREFIX . 'products WHERE id = ?',
                [$productId]
            );
            if (!is_array($row)) {
                continue;
            }
            $updates = [];
            foreach (['description', 'short_description'] as $col) {
                if (isset($row[$col]) && str_contains((string) $row[$col], $oldSiteUrl)) {
                    $updates[$col] = str_replace($oldSiteUrl, $newSiteUrl, (string) $row[$col]);
                }
            }
            if ($updates !== []) {
                $this->db->update(VL_DB_PREFIX . 'products', $updates, ['id' => $productId]);
                $rewritten++;
            }
        }
        return $rewritten;
    }

    /**
     * Absolute site URL for the current instance (used for export stamp
     * and post-import content URL rewriting). APP_URL is mandatory, so there
     * is no fallback to a bare host or a relative URL.
     */
    private static function currentAbsoluteUrl(): string
    {
        return __require_app_url();
    }

    private function resetRunState(): void
    {
        foreach (array_keys($this->idMapping) as $k) {
            $this->idMapping[$k] = [];
        }
        $this->pendingPostHierarchy     = [];
        $this->pendingCategoryHierarchy = [];
        $this->insertedPostIds          = [];
        $this->insertedProductIds       = [];
    }

    private function resolveUserId(mixed $raw): ?int
    {
        if ($raw !== null && $raw !== '' && isset($this->idMapping['users'][(int) $raw])) {
            $mapped = (int) $this->idMapping['users'][(int) $raw];
            if ($mapped > 0) {
                return $mapped;
            }
        }
        if ($raw !== null && $raw !== '') {
            $exists = $this->db->fetchOne(
                'SELECT id FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
                [(int) $raw]
            );
            if ($exists) {
                return (int) $exists;
            }
        }
        $fallback = $this->db->fetchOne('SELECT id FROM ' . VL_DB_PREFIX . 'users ORDER BY id ASC LIMIT 1');
        return $fallback !== false && $fallback !== null ? (int) $fallback : null;
    }

    /**
     * @return array<int, string> id => slug
     */
    private function roleSlugMap(): array
    {
        if (!$this->tableExists('roles')) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative('SELECT id, slug FROM ' . VL_DB_PREFIX . 'roles');
        } catch (\Exception) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (string) $row['slug'];
        }
        return $map;
    }

    /**
     * @return array<string, int> slug => id
     */
    private function roleIdMap(): array
    {
        if (!$this->tableExists('roles')) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllAssociative('SELECT id, slug FROM ' . VL_DB_PREFIX . 'roles');
        } catch (\Exception) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['slug']] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * Remap comma/JSON separated ID lists using a mapping table.
     * Unmapped IDs are dropped (safer than pointing at wrong rows).
     */
    private function remapIdList(mixed $value, array $mapping): string
    {
        if (is_array($value)) {
            $ids = array_map(intval(...), $value);
        } else {
            $decoded = json_decode((string) $value, true);
            if (is_array($decoded)) {
                $ids = array_map(intval(...), $decoded);
            } else {
                $ids = array_map(intval(...), preg_split('/[\s,;|]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            }
        }
        $remapped = [];
        foreach ($ids as $id) {
            if (isset($mapping[$id]) && $mapping[$id]) {
                $remapped[] = $mapping[$id];
            }
        }
        return json_encode(array_values(array_unique($remapped)));
    }

    private function tableExists(string $table): bool
    {
        try {
            return $this->db->createSchemaManager()->tableExists(VL_DB_PREFIX . $table);
        } catch (\Exception) {
            return true;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertFiltered(string $table, array $row): void
    {
        $this->db->insert(VL_DB_PREFIX . $table, $this->filterToColumns($table, $row));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $criteria
     */
    private function updateFiltered(string $table, array $data, array $criteria): void
    {
        $this->db->update(VL_DB_PREFIX . $table, $this->filterToColumns($table, $data), $criteria);
    }

    /**
     * Drop keys that do not exist as columns (cross-version exports).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function filterToColumns(string $table, array $row): array
    {
        if (!isset($this->columnCache[$table])) {
            try {
                $columns = $this->db->createSchemaManager()->listTableColumns(VL_DB_PREFIX . $table);
                $names   = [];
                foreach ($columns as $column) {
                    $names[strtolower($column->getName())] = true;
                }
                $this->columnCache[$table] = $names;
            } catch (\Exception) {
                return $row;
            }
        }
        $allowed  = $this->columnCache[$table];
        $filtered = [];
        foreach ($row as $k => $v) {
            if (isset($allowed[strtolower((string) $k)])) {
                $filtered[$k] = $v;
            }
        }
        return $filtered;
    }
}
