<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use ValeApp\Infrastructure\Persistence\DbalMediaRepository;

/**
 * findUnused() must treat a media row as used when any of its real files
 * appears in content — the main path, every rendition recorded in the
 * thumbnails JSON (webp carousel/media+text picks included), or a legacy
 * <base>-<size>.<ext>/<base>-<size>.webp name — or when it is backed by an
 * FK reference. Runs against a real Phinx-migrated schema in an in-memory
 * SQLite database.
 */
final class DbalMediaRepositoryFindUnusedTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        // phinx.php reads DB_DRIVER/DB_NAME to pick adapter + dsn. The sqlite
        // adapter is required, but the actual dsn is never used: the PDO of the
        // in-memory connection below is injected via the environment's
        // 'connection' option so Phinx migrates the very same database the
        // repository will query (SQLite :memory: is per-connection).
        $dbFile = sys_get_temp_dir() . '/vl_findunused_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = $dbFile;
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . $dbFile);

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        self::$db = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $configPath                            = dirname(__DIR__, 3) . '/phinx.php';
        $config                                = Config::fromPhp($configPath);
        $environments                          = $config['environments'];
        $environments['default']['connection'] = self::$db->getNativeConnection();
        $config['environments']                = $environments;

        $manager = new Manager($config, new ArrayInput([]), new NullOutput());
        $manager->migrate('default');
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db)) {
            try {
                self::$db->close();
            } catch (\Throwable) {
            }
        }
    }

    protected function setUp(): void
    {
        self::$db->beginTransaction();
    }

    protected function tearDown(): void
    {
        try {
            self::$db->rollBack();
        } catch (\Throwable) {
        }
    }

    private function repo(): DbalMediaRepository
    {
        return new DbalMediaRepository(self::$db);
    }

    /**
     * @param array<string, string>|null $thumbnails Thumbnails JSON (relative rendition paths)
     */
    private function insertMedia(
        string $path,
        ?array $thumbnails = null,
        ?string $contentHash = null
    ): int {
        $data = [
            'user_id'       => 1,
            'filename'      => basename($path),
            'original_name' => basename($path),
            'mime_type'     => 'image/' . (pathinfo($path, PATHINFO_EXTENSION) ?: 'png'),
            'size'          => 1234,
            'path'          => $path,
            'thumbnails'    => $thumbnails !== null ? json_encode($thumbnails) : null,
        ];
        if ($contentHash !== null) {
            $data['content_hash'] = $contentHash;
        }

        self::$db->insert(VL_DB_PREFIX . 'media', $data);

        return (int) self::$db->lastInsertId();
    }

    private function insertPost(string $body, ?int $featuredImageId = null): int
    {
        $data = [
            'author_id' => 1,
            'title'     => 'Post ' . bin2hex(random_bytes(4)),
            'slug'      => 'post-' . bin2hex(random_bytes(4)),
            'body'      => $body,
        ];
        if ($featuredImageId !== null) {
            $data['featured_image_id'] = $featuredImageId;
        }

        self::$db->insert(VL_DB_PREFIX . 'posts', $data);

        return (int) self::$db->lastInsertId();
    }

    private function insertProduct(
        ?int $featuredImageId = null,
        string $description = '',
        string $shortDescription = ''
    ): int {
        $data = [
            'name' => 'Product ' . bin2hex(random_bytes(4)),
            'slug' => 'product-' . bin2hex(random_bytes(4)),
        ];
        if ($featuredImageId !== null) {
            $data['featured_image_id'] = $featuredImageId;
        }
        if ($description !== '') {
            $data['description'] = $description;
        }
        if ($shortDescription !== '') {
            $data['short_description'] = $shortDescription;
        }

        self::$db->insert(VL_DB_PREFIX . 'products', $data);

        return (int) self::$db->lastInsertId();
    }

    private function insertBlogCategory(string $description = ''): int
    {
        $data = [
            'name' => 'Category ' . bin2hex(random_bytes(4)),
            'slug' => 'category-' . bin2hex(random_bytes(4)),
        ];
        if ($description !== '') {
            $data['description'] = $description;
        }

        self::$db->insert(VL_DB_PREFIX . 'categories', $data);

        return (int) self::$db->lastInsertId();
    }

    private function insertProductCategory(string $description = ''): int
    {
        $data = [
            'name' => 'ProdCat ' . bin2hex(random_bytes(4)),
            'slug' => 'prodcat-' . bin2hex(random_bytes(4)),
        ];
        if ($description !== '') {
            $data['description'] = $description;
        }

        self::$db->insert(VL_DB_PREFIX . 'product_categories', $data);

        return (int) self::$db->lastInsertId();
    }

    private function insertNewsletterCampaign(string $bodyHtml, string $bodyHtmlB = ''): int
    {
        $data = [
            'subject'   => 'Campaign ' . bin2hex(random_bytes(4)),
            'body_html' => $bodyHtml,
        ];
        if ($bodyHtmlB !== '') {
            $data['body_html_b'] = $bodyHtmlB;
        }

        self::$db->insert(VL_DB_PREFIX . 'newsletter_campaigns', $data);

        return (int) self::$db->lastInsertId();
    }

    private function insertAutomationStep(string $bodyHtml, string $bodyHtmlB = ''): int
    {
        $data = [
            'sequence_id' => 1,
            'subject'     => 'Step ' . bin2hex(random_bytes(4)),
            'body_html'   => $bodyHtml,
        ];
        if ($bodyHtmlB !== '') {
            $data['body_html_b'] = $bodyHtmlB;
        }

        self::$db->insert(VL_DB_PREFIX . 'automation_steps', $data);

        return (int) self::$db->lastInsertId();
    }

    /** @return int[] unused media ids in ascending order */
    private function unusedIds(): array
    {
        $ids = array_map('intval', array_keys($this->repo()->findUnused()));
        sort($ids);

        return $ids;
    }

    public function testCompletelyUnreferencedMediaIsReportedUnused(): void
    {
        $a = $this->insertMedia('2026/09/aaa.png', ['2026/09/aaa-medium.webp']);
        $b = $this->insertMedia('2026/09/bbb.jpg', null, 'abc123hash');

        $result = $this->repo()->findUnused();

        self::assertSame([$a, $b], $this->unusedIds());
        self::assertSame('2026/09/aaa.png', $result[$a]['path']);
        self::assertSame('aaa.png', $result[$a]['original_name']);
        self::assertSame(1234, $result[$a]['size']);
        self::assertSame('image/png', $result[$a]['mime_type']);
        self::assertSame('2026/09/bbb.jpg', $result[$b]['path']);
    }

    public function testFkReferencesKeepMediaOutOfUnused(): void
    {
        $postFeatured    = $this->insertMedia('2026/09/post-featured.png');
        $productFeatured = $this->insertMedia('2026/09/product-featured.png');
        $gallery         = $this->insertMedia('2026/09/gallery.png');
        $download        = $this->insertMedia('2026/09/download.pdf');
        $variationFile   = $this->insertMedia('2026/09/variation.pdf');
        $variationImage  = $this->insertMedia('2026/09/var-img.png');
        $prodCatImage    = $this->insertMedia('2026/09/prod-cat.png');
        $seoOgImage      = $this->insertMedia('2026/09/seo-og.png');
        $free            = $this->insertMedia('2026/09/free.png');

        $postId    = $this->insertPost(
            '',
            $postFeatured
        );
        $productId = $this->insertProduct($productFeatured);
        self::$db->insert(
            VL_DB_PREFIX . 'product_images',
            ['product_id' => $productId, 'media_id' => $gallery]
        );
        self::$db->insert(
            VL_DB_PREFIX . 'product_download_files',
            ['product_id' => $productId, 'media_id' => $download]
        );
        self::$db->insert(
            VL_DB_PREFIX . 'product_variation_download_files',
            ['variation_id' => 1, 'media_id' => $variationFile]
        );
        self::$db->insert(
            VL_DB_PREFIX . 'product_variations',
            ['product_id' => $productId, 'price' => 10.0, 'image_id' => $variationImage]
        );
        self::$db->insert(
            VL_DB_PREFIX . 'product_categories',
            ['name' => 'Cat', 'slug' => 'cat', 'image_id' => $prodCatImage]
        );
        self::$db->insert(
            VL_DB_PREFIX . 'seo_meta',
            ['entity_type' => 'post', 'entity_id' => $postId, 'og_image_id' => $seoOgImage]
        );

        self::assertSame([$free], $this->unusedIds());

        $referenced = array_map('intval', array_keys($this->repo()->findReferencedIds()));
        sort($referenced);
        $expected = [
            $postFeatured, $productFeatured, $gallery, $download,
            $variationFile, $variationImage, $prodCatImage, $seoOgImage,
        ];
        sort($expected);
        self::assertSame($expected, $referenced);
    }

    public function testMainPathInPostBodyMarksMediaUsed(): void
    {
        $media = $this->insertMedia('2026/09/hero.png', []);

        $this->insertPost('<p><img src="https://example.com/uploads/2026/09/hero.png"></p>');

        self::assertSame([], $this->unusedIds());
    }

    public function testWebpRenditionInContentMarksMediaUsedWithoutMainPath(): void
    {
        // Carousel / media+text blocks store the webp rendition URL, not the main file.
        $media = $this->insertMedia(
            '2026/09/gallery.png',
            [
                '2026/09/gallery-medium.webp',
                '2026/09/gallery-small.webp',
            ]
        );

        $this->insertPost(
            '<p>[carousel images="https://example.com/uploads/2026/09/gallery-medium.webp"]</p>'
        );

        self::assertSame([], $this->unusedIds());
    }

    public function testLegacySizedVariantsCountForRowsWithoutTrackedThumbnails(): void
    {
        $legacy = $this->insertMedia('2026/09/legacy.png', null);
        $other  = $this->insertMedia('2026/09/other.png', null);

        // Only a -large.webp rendition appears; main + small/medium are never referenced.
        $this->insertPost('<img src="https://example.com/uploads/2026/09/legacy-large.webp">');
        // A lookalike file name (hero-screenshot.png) must NOT protect 2026/09/hero.png.
        $this->insertPost('<img src="https://example.com/uploads/2026/09/hero-screenshot.png">');

        $hero = $this->insertMedia('2026/09/hero.png', null);

        $expected = [$hero, $other];
        sort($expected);
        self::assertSame($expected, $this->unusedIds());
    }

    public function testContentInAnyScannedEntityColumnMarksMediaUsed(): void
    {
        // One dedicated media per scanned content column; each is referenced only
        // by the row that exercises that column.
        $rows = [
            fn (string $p) => $this->insertProduct(null, '<img src="/uploads/' . $p . '">'),
            fn (string $p) => $this->insertProduct(null, '', '<img src="/uploads/' . $p . '">'),
            fn (string $p) => $this->insertBlogCategory('Banner: uploads/' . $p),
            fn (string $p) => $this->insertProductCategory('Banner: uploads/' . $p),
            fn (string $p) => $this->insertNewsletterCampaign('<img src="/' . $p . '">'),
            fn (string $p) => $this->insertNewsletterCampaign('', '<img src="/' . $p . '">'),
            fn (string $p) => $this->insertAutomationStep('<img src="/uploads/' . $p . '">'),
            fn (string $p) => $this->insertAutomationStep('', '<img src="/uploads/' . $p . '">'),
        ];

        $usedMedia = [];
        foreach ($rows as $i => $insertRow) {
            $path         = '2026/09/column-' . $i . '.png';
            $usedMedia[]  = $this->insertMedia($path, null);
            $insertRow($path);
        }
        $stray = $this->insertMedia('2026/09/never-used.png', null);

        $unusedRows = $this->repo()->findUnused();

        foreach ($usedMedia as $mediaId) {
            self::assertArrayNotHasKey(
                $mediaId,
                $unusedRows,
                'media referenced from a scanned content column must not be reported unused',
            );
        }
        self::assertArrayHasKey($stray, $unusedRows);
        self::assertCount(1, $unusedRows);
    }

    public function testContentMediaIsUnusedWhenOnlyFolderPathAppears(): void
    {
        $media = $this->insertMedia('2026/09/isolated.png', null);

        // "uploads/2026/09/" alone or an unrelated sibling file is not a reference.
        $this->insertPost(
            '<p>Folder: /uploads/2026/09/</p>'
            . '<img src="https://example.com/uploads/2026/09/sibling.webp">'
        );

        self::assertSame([$media], $this->unusedIds());
    }
}
