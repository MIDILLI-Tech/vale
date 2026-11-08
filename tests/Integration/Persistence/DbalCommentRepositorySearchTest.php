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
use ValeApp\Domain\Comment\Entity\Comment;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Persistence\DbalCommentRepository;

/**
 * The admin comments listing shows author, the "On" column (entity title) and
 * the replied-to author. Search must cover those columns as well — client-side
 * mode matched them via rendered text, so server-side results are kept
 * identical, and the "On" column is sortable by entity title.
 */
final class DbalCommentRepositorySearchTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $dbFile = sys_get_temp_dir() . '/vl_commentsearch_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = $dbFile;
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . $dbFile);

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        self::$db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $config                        = Config::fromPhp(dirname(__DIR__, 3) . '/phinx.php');
        $envs                          = $config['environments'];
        $envs['default']['connection'] = self::$db->getNativeConnection();
        $config['environments']        = $envs;

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

    private function repo(): CommentRepositoryInterface
    {
        return new DbalCommentRepository(self::$db);
    }

    private function insertPost(string $title, string $slug): int
    {
        self::$db->insert(VL_DB_PREFIX . 'posts', [
            'content_type' => 'post',
            'author_id'    => 1,
            'title'        => $title,
            'slug'         => $slug,
        ]);

        return (int) self::$db->lastInsertId();
    }

    private function insertProduct(string $name, string $slug): int
    {
        self::$db->insert(VL_DB_PREFIX . 'products', [
            'name' => $name,
            'slug' => $slug,
        ]);

        return (int) self::$db->lastInsertId();
    }

    private function insertComment(
        string $entityType,
        int $entityId,
        string $authorName,
        string $body,
        ?int $parentId = null,
        string $status = 'approved'
    ): int {
        self::$db->insert(VL_DB_PREFIX . 'comments', [
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'parent_id'    => $parentId,
            'author_name'  => $authorName,
            'author_email' => strtolower(str_replace(' ', '-', $authorName)) . '@example.com',
            'body'         => $body,
            'status'       => $status,
        ]);

        return (int) self::$db->lastInsertId();
    }

    public function testSearchMatchesPostTitle(): void
    {
        $post = $this->insertPost('Kamelya Bakımı', 'kamelya-bakimi');
        $this->insertComment('post', $post, 'Yorumsuz', 'güzel yorum');
        $other = $this->insertPost('Papatya Rehberi', 'papatya-rehberi');
        $this->insertComment('post', $other, 'Başkası', 'ilgisiz yorum');

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
        $comments = $this->repo()->findAll(new Pagination(1, 50), ['search' => 'kamelya']);
        self::assertCount(1, $comments);
        self::assertSame('Yorumsuz', $comments[0]->getAuthorName());
    }

    public function testSearchMatchesProductName(): void
    {
        $product = $this->insertProduct('Kamelya Çayı', 'kamelya-cayi');
        $this->insertComment('product', $product, 'Alıcı', 'memnun kaldım');
        $other   = $this->insertProduct('Papatya Çayı', 'papatya-cayi');
        $this->insertComment('product', $other, 'Diğer Alıcı', 'idare eder');

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
    }

    public function testSearchMatchesParentAuthorName(): void
    {
        $post   = $this->insertPost('Genel Başlık', 'genel-baslik');
        $parent = $this->insertComment('post', $post, 'Kamelya Yazar', 'üst yorum');
        $this->insertComment('post', $post, 'Cevap Veren', 'alt yorum', $parent);

        self::assertSame(2, $this->repo()->count(['search' => 'kamelya']));
        $comments = $this->repo()->findAll(new Pagination(1, 50), ['search' => 'kamelya']);
        self::assertCount(2, $comments);
    }

    public function testSearchStillMatchesAuthorEmailAndBody(): void
    {
        $post = $this->insertPost('Sıradışı Başlık', 'siradisi-baslik');
        $this->insertComment('post', $post, 'Yazar Bir', 'kamelya kokulu yorum');
        $this->insertComment('post', $post, 'Yazar Iki', 'başka yorum');
        self::$db->update(
            VL_DB_PREFIX . 'comments',
            ['author_email' => 'kamelya@example.com'],
            ['author_name' => 'Yazar Iki'],
        );

        self::assertSame(2, $this->repo()->count(['search' => 'kamelya']));
    }

    public function testSearchCombinesWithEntityTypeFilter(): void
    {
        $post    = $this->insertPost('Kamelya Yazısı', 'kamelya-yazisi');
        $product = $this->insertProduct('Kamelya Ürünü', 'kamelya-urunu');
        $this->insertComment('post', $post, 'Yorumcu', 'güzel');
        $this->insertComment('product', $product, 'Alıcı', 'güzel');

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya', 'entity_type' => 'product']));
        self::assertSame(2, $this->repo()->count(['search' => 'kamelya']));
    }

    public function testSearchWithoutMatchesReturnsEmpty(): void
    {
        $post = $this->insertPost('Kamelya Bakımı', 'kamelya-bakimi');
        $this->insertComment('post', $post, 'Yorumsuz', 'güzel yorum');

        self::assertSame(0, $this->repo()->count(['search' => 'inception']));
        self::assertSame([], $this->repo()->findAll(new Pagination(1, 50), ['search' => 'inception']));
    }

    public function testEmptySearchKeepsPreviousBehaviour(): void
    {
        $post = $this->insertPost('Genel Başlık', 'genel-baslik');
        $this->insertComment('post', $post, 'Yazar Bir', 'birinci');
        $this->insertComment('post', $post, 'Yazar Iki', 'ikinci');

        self::assertSame(2, $this->repo()->count());
        self::assertCount(2, $this->repo()->findAll(new Pagination(1, 50)));
    }

    public function testFindAllSortsByEntityTitle(): void
    {
        $zebraPost = $this->insertPost('Zebra Yazısı', 'zebra-yazisi');
        $applePost = $this->insertPost('Apple Yazısı', 'apple-yazisi');
        $product   = $this->insertProduct('Kamelya Çayı', 'kamelya-cayi');

        $this->insertComment('post', $zebraPost, 'Yorumcu Z', 'yorum');
        $this->insertComment('post', $applePost, 'Yorumcu A', 'yorum');
        $this->insertComment('product', $product, 'Alıcı', 'yorum');

        $asc = $this->repo()->findAll(new Pagination(1, 50, 'entity_title', 'asc'));
        self::assertSame(
            ['Yorumcu A', 'Alıcı', 'Yorumcu Z'],
            array_map(fn (Comment $c) => $c->getAuthorName(), $asc),
        );

        $desc = $this->repo()->findAll(new Pagination(1, 50, 'entity_title', 'desc'));
        self::assertSame(
            ['Yorumcu Z', 'Alıcı', 'Yorumcu A'],
            array_map(fn (Comment $c) => $c->getAuthorName(), $desc),
        );
    }

    public function testFindAllSortsByEntityTitleWithMissingEntity(): void
    {
        $post = $this->insertPost('Gerçek Yazı', 'gercek-yazi');
        $this->insertComment('post', $post, 'Gerçek Yorum', 'yorum');
        $this->insertComment('post', 999999, 'Hayali Yorum', 'yorum');

        $asc = $this->repo()->findAll(new Pagination(1, 50, 'entity_title', 'asc'));

        self::assertCount(2, $asc);
        self::assertSame('Hayali Yorum', $asc[0]->getAuthorName());
        self::assertSame('Gerçek Yorum', $asc[1]->getAuthorName());
    }

    public function testFindAllFallsBackForUnknownSortColumn(): void
    {
        $post = $this->insertPost('Genel Başlık', 'genel-baslik');
        $this->insertComment('post', $post, 'Birinci', 'yorum');
        $this->insertComment('post', $post, 'İkinci', 'yorum');

        $items = $this->repo()->findAll(new Pagination(1, 50, 'not_a_column', 'asc'));

        self::assertCount(2, $items);
        self::assertSame(
            ['İkinci', 'Birinci'],
            array_map(fn (Comment $c) => $c->getAuthorName(), $items),
        );
    }
}
