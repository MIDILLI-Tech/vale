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
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Persistence\DbalPostRepository;

/**
 * Post search covers title, excerpt and slug — body (LONGTEXT) is
 * intentionally excluded to avoid full-table scans on large catalogs.
 */
final class DbalPostRepositorySearchTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = sys_get_temp_dir() . '/vl_postsearch_' . bin2hex(random_bytes(8)) . '.sqlite';
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . $_ENV['DB_NAME']);

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        self::$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $config                                = Config::fromPhp(dirname(__DIR__, 3) . '/phinx.php');
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

    private function repo(): DbalPostRepository
    {
        return new DbalPostRepository(self::$db);
    }

    private function makePost(string $title, string $slug, ?string $excerpt = null, ?string $body = null, int $authorId = 1): Post
    {
        $post = new Post($title, new Slug($slug), $authorId);
        $post->setContentType('post');
        $post->setStatus('published');
        $post->setExcerpt($excerpt);
        $post->setBody($body);
        return $this->repo()->save($post);
    }

    private function makePage(string $title, string $slug, ?int $parentId = null, string $language = 'en', int $authorId = 1): Post
    {
        $page = new Post($title, new Slug($slug), $authorId);
        $page->setContentType('page');
        $page->setStatus('published');
        $page->setParentId($parentId);
        $page->setLanguage($language);
        return $this->repo()->save($page);
    }

    private function insertUser(string $name): int
    {
        self::$db->insert(VL_DB_PREFIX . 'users', [
            'email'    => strtolower(str_replace(' ', '-', $name)) . '@example.com',
            'password' => 'hashed',
            'name'     => $name,
        ]);

        return (int) self::$db->lastInsertId();
    }

    private function insertCategory(string $name, string $slug): int
    {
        self::$db->insert(VL_DB_PREFIX . 'categories', ['name' => $name, 'slug' => $slug]);

        return (int) self::$db->lastInsertId();
    }

    private function insertTag(string $name, string $slug): int
    {
        self::$db->insert(VL_DB_PREFIX . 'tags', ['name' => $name, 'slug' => $slug]);

        return (int) self::$db->lastInsertId();
    }

    private function assignCategory(int $postId, int $categoryId): void
    {
        self::$db->insert(VL_DB_PREFIX . 'post_categories', ['post_id' => $postId, 'category_id' => $categoryId]);
    }

    private function assignTag(int $postId, int $tagId): void
    {
        self::$db->insert(VL_DB_PREFIX . 'post_tags', ['post_id' => $postId, 'tag_id' => $tagId]);
    }

    public function testSearchMatchesTitle(): void
    {
        $this->makePost('Kamelya Bakımı', 'unrelated-slug');
        $this->makePost('Unrelated Title', 'another-slug');

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
        $items = $this->repo()->findAll(new Pagination(1, 20), ['search' => 'kamelya']);
        self::assertCount(1, $items);
        self::assertSame('Kamelya Bakımı', $items[0]->getTitle());
    }

    public function testSearchMatchesExcerptAndSlug(): void
    {
        $this->makePost('Plain Title', 'plain-slug', 'Kamelya özeti burada');
        $this->makePost('Other Title', 'kamelya-item-slug');

        self::assertSame(2, $this->repo()->count(['search' => 'kamelya']));
    }

    public function testSearchIgnoresBody(): void
    {
        $this->makePost('Plain Title', 'plain-slug', null, 'Bu gövdede kamelya geçiyor ama bulunmamalı');

        self::assertSame(0, $this->repo()->count(['search' => 'kamelya']));
        self::assertSame([], $this->repo()->findAll(new Pagination(1, 20), ['search' => 'kamelya']));
    }

    public function testSearchBodyIncludesBodyWhenFlagSet(): void
    {
        $this->makePost('Plain Title', 'plain-slug', null, 'Bu gövdede kamelya geçiyor');

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya', 'search_body' => true]));
        $items = $this->repo()->findAll(new Pagination(1, 20), ['search' => 'kamelya', 'search_body' => true]);
        self::assertCount(1, $items);
        self::assertSame('Plain Title', $items[0]->getTitle());
    }

    public function testSearchWithNullExcerptDoesNotError(): void
    {
        $this->makePost('Kamelya Başlık', 'kamelya-null-excerpt', null, null);

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
    }

    public function testPageSearchMatchesParentPageTitle(): void
    {
        $parent = $this->makePage('Kurumsal', 'kurumsal');
        $this->makePage('Hakkımızda', 'hakkimizda', $parent->getId());
        $this->makePage('İletişim', 'iletisim');

        self::assertSame(2, $this->repo()->count(['search' => 'kurumsal', 'content_type' => 'page']));
        $items = $this->repo()->findAll(new Pagination(1, 20), ['search' => 'kurumsal', 'content_type' => 'page']);
        self::assertCount(2, $items);
        self::assertSame(
            ['Hakkımızda', 'Kurumsal'],
            array_map(fn (Post $page) => $page->getTitle(), $items),
        );
    }

    public function testPostSearchIgnoresParentPageTitles(): void
    {
        $parent = $this->makePage('Kurumsal', 'kurumsal-page');
        $post   = new Post('Yazı Başlığı', new Slug('post-body'), 1);
        $post->setContentType('post');
        $post->setStatus('published');
        $post->setParentId($parent->getId());
        $this->repo()->save($post);

        self::assertSame(0, $this->repo()->count(['search' => 'kurumsal', 'content_type' => 'post']));
    }

    public function testFindAllSortsPagesByParentTitle(): void
    {
        $zebra  = $this->makePage('Zebra Üst', 'zebra-parent');
        $apple  = $this->makePage('Apple Üst', 'apple-parent');
        $this->makePage('Zebra Çocuk', 'zebra-child', $zebra->getId());
        $this->makePage('Apple Çocuk', 'apple-child', $apple->getId());
        $this->makePage('Tepsiz', 'no-parent');

        $items = $this->repo()->findAll(
            new Pagination(1, 20, 'parent', 'asc'),
            ['content_type' => 'page'],
        );

        self::assertCount(5, $items);
        // Parent-less pages sort first (empty parent title), ties break by id DESC.
        self::assertSame(
            ['Tepsiz', 'Apple Üst', 'Zebra Üst', 'Apple Çocuk', 'Zebra Çocuk'],
            array_map(fn (Post $page) => $page->getTitle(), $items),
        );
    }

    public function testFindAllSortsByParentTitleDescending(): void
    {
        $zebra = $this->makePage('Zebra Üst', 'zebra-parent');
        $apple = $this->makePage('Apple Üst', 'apple-parent');
        $this->makePage('Zebra Çocuk', 'zebra-child', $zebra->getId());
        $this->makePage('Apple Çocuk', 'apple-child', $apple->getId());

        $items = $this->repo()->findAll(
            new Pagination(1, 20, 'parent', 'desc'),
            ['content_type' => 'page'],
        );

        self::assertSame(
            ['Zebra Çocuk', 'Apple Çocuk', 'Apple Üst', 'Zebra Üst'],
            array_map(fn (Post $page) => $page->getTitle(), $items),
        );
    }

    public function testFindAllSortsByLanguage(): void
    {
        $this->makePage('English Page', 'en-page', null, 'en');
        $this->makePage('Türkçe Sayfa', 'tr-page', null, 'tr');

        $items = $this->repo()->findAll(
            new Pagination(1, 20, 'language', 'asc'),
            ['content_type' => 'page'],
        );

        self::assertSame(
            ['English Page', 'Türkçe Sayfa'],
            array_map(fn (Post $page) => $page->getTitle(), $items),
        );
    }

    public function testSearchMatchesAuthorName(): void
    {
        $author = $this->insertUser('Kamelya Yazar');
        $this->makePost('Başlıksız İçerik', 'author-post-1', null, null, $author);
        $this->makePost('Diğer İçerik', 'author-post-2', null, null, 999);

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
        $items = $this->repo()->findAll(new Pagination(1, 20), ['search' => 'kamelya']);
        self::assertCount(1, $items);
        self::assertSame('Başlıksız İçerik', $items[0]->getTitle());
    }

    public function testSearchMatchesCategoryName(): void
    {
        $category = $this->insertCategory('Kamelya Haberleri', 'kamelya-haberleri');
        $post     = $this->makePost('Sıradışı Başlık', 'category-post');
        $this->assignCategory($post->getId(), $category);
        $this->makePost('İlgisiz Başlık', 'other-post');

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
        $items = $this->repo()->findAll(new Pagination(1, 20), ['search' => 'kamelya']);
        self::assertCount(1, $items);
        self::assertSame('Sıradışı Başlık', $items[0]->getTitle());
    }

    public function testSearchMatchesTagName(): void
    {
        $tag  = $this->insertTag('Kamelya Etiketi', 'kamelya-etiketi');
        $post = $this->makePost('Sıradışı Başlık', 'tag-post');
        $this->assignTag($post->getId(), $tag);
        $this->makePost('İlgisiz Başlık', 'other-post');

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
        $items = $this->repo()->findAll(new Pagination(1, 20), ['search' => 'kamelya']);
        self::assertCount(1, $items);
        self::assertSame('Sıradışı Başlık', $items[0]->getTitle());
    }

    public function testPageSearchIgnoresAuthorCategoryAndTagNames(): void
    {
        $author   = $this->insertUser('Kamelya Yazar');
        $category = $this->insertCategory('Kamelya Haberleri', 'kamelya-cat');
        $tag      = $this->insertTag('Kamelya Etiketi', 'kamelya-tag');
        $page     = $this->makePage('Sade Sayfa', 'plain-page', null, 'en', $author);
        $this->assignCategory($page->getId(), $category);
        $this->assignTag($page->getId(), $tag);

        self::assertSame(0, $this->repo()->count(['search' => 'kamelya', 'content_type' => 'page']));
    }

    public function testFindAllFiltersByTagSlug(): void
    {
        $tag      = $this->insertTag('Feed Tag', 'feed-tag');
        $tagged   = $this->makePost('Tagged Post', 'tagged-post');
        $this->assignTag($tagged->getId(), $tag);
        $this->makePost('Untagged Post', 'untagged-post');

        $items = $this->repo()->findAll(new Pagination(1, 20), ['tag' => 'feed-tag']);

        self::assertCount(1, $items);
        self::assertSame('Tagged Post', $items[0]->getTitle());
    }

    public function testFindAllFiltersByTagNameCaseInsensitive(): void
    {
        $tag    = $this->insertTag('Feed Tag', 'feed-tag');
        $tagged = $this->makePost('Tagged Post', 'tagged-post');
        $this->assignTag($tagged->getId(), $tag);
        $this->makePost('Untagged Post', 'untagged-post');

        $items = $this->repo()->findAll(new Pagination(1, 20), ['tag' => 'FEED TAG']);

        self::assertCount(1, $items);
        self::assertSame('Tagged Post', $items[0]->getTitle());
    }

    public function testFindAllFiltersByCategoryAndTagCombined(): void
    {
        $category = $this->insertCategory('Feed Category', 'feed-cat');
        $tag      = $this->insertTag('Feed Tag', 'feed-tag');
        $both     = $this->makePost('Both Post', 'both-post');
        $this->assignCategory($both->getId(), $category);
        $this->assignTag($both->getId(), $tag);
        $catOnly = $this->makePost('Category Only', 'category-only');
        $this->assignCategory($catOnly->getId(), $category);

        $items = $this->repo()->findAll(new Pagination(1, 20), ['category' => 'feed-cat', 'tag' => 'feed-tag']);

        self::assertCount(1, $items);
        self::assertSame('Both Post', $items[0]->getTitle());
    }

    public function testFindAllSortsByAuthorId(): void
    {
        $this->makePost('Beşinci Yazar', 'author-five', null, null, 5);
        $this->makePost('Dokuzuncu Yazar', 'author-nine', null, null, 9);

        $items = $this->repo()->findAll(new Pagination(1, 20, 'author_id', 'asc'));

        self::assertSame(
            ['Beşinci Yazar', 'Dokuzuncu Yazar'],
            array_map(fn (Post $post) => $post->getTitle(), $items),
        );
    }

    public function testFindAllSortsByCategoriesAscending(): void
    {
        $zebra = $this->insertCategory('Zebra Kategorisi', 'zebra-cat');
        $apple = $this->insertCategory('Apple Kategorisi', 'apple-cat');

        $zebraPost = $this->makePost('Zebra Ürün', 'zebra-post');
        $this->assignCategory($zebraPost->getId(), $zebra);
        $applePost = $this->makePost('Apple Ürün', 'apple-post');
        $this->assignCategory($applePost->getId(), $apple);
        $this->makePost('Kategorisiz', 'no-category');

        $items = $this->repo()->findAll(new Pagination(1, 20, 'categories', 'asc'));

        self::assertCount(3, $items);
        self::assertSame(
            ['Kategorisiz', 'Apple Ürün', 'Zebra Ürün'],
            array_map(fn (Post $post) => $post->getTitle(), $items),
        );
    }

    public function testFindAllSortsByFirstCategoryWhenMultiple(): void
    {
        $apple  = $this->insertCategory('Apple Kategorisi', 'apple-cat');
        $mango  = $this->insertCategory('Mango Kategorisi', 'mango-cat');
        $banana = $this->insertCategory('Banana Kategorisi', 'banana-cat');

        $multi = $this->makePost('Çoklu Kategori', 'multi-category');
        $this->assignCategory($multi->getId(), $apple);
        $this->assignCategory($multi->getId(), $mango);
        $single = $this->makePost('Tekli Kategori', 'single-category');
        $this->assignCategory($single->getId(), $banana);

        $items = $this->repo()->findAll(new Pagination(1, 20, 'categories', 'asc'));

        self::assertSame(
            ['Çoklu Kategori', 'Tekli Kategori'],
            array_map(fn (Post $post) => $post->getTitle(), $items),
        );
    }

    public function testFindAllSortsByTagsAscending(): void
    {
        $zebra = $this->insertTag('Zebra Etiketi', 'zebra-tag');
        $apple = $this->insertTag('Apple Etiketi', 'apple-tag');

        $zebraPost = $this->makePost('Zebra İçerik', 'zebra-post');
        $this->assignTag($zebraPost->getId(), $zebra);
        $applePost = $this->makePost('Apple İçerik', 'apple-post');
        $this->assignTag($applePost->getId(), $apple);
        $this->makePost('Etiketsiz', 'no-tag');

        $items = $this->repo()->findAll(new Pagination(1, 20, 'tags', 'asc'));

        self::assertCount(3, $items);
        self::assertSame(
            ['Etiketsiz', 'Apple İçerik', 'Zebra İçerik'],
            array_map(fn (Post $post) => $post->getTitle(), $items),
        );
    }
}
