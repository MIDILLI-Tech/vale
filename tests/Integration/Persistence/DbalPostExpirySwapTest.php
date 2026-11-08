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
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Persistence\DbalPostRepository;

/**
 * Post expiry + scheduled swap against a real Phinx-migrated schema in an
 * in-memory SQLite database (also proves the migration runs on SQLite).
 */
final class DbalPostExpirySwapTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = sys_get_temp_dir() . '/vl_postswap_' . bin2hex(random_bytes(8)) . '.sqlite';
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

    private function makePage(string $title, string $slug, string $status = 'draft'): Post
    {
        $post = new Post($title, new Slug($slug), 1);
        $post->setContentType('page');
        $post->setStatus($status);
        return $this->repo()->save($post);
    }

    public function testSavePersistsExpiryAndSwapFields(): void
    {
        $post = $this->makePage('Duyuru', 'duyuru', 'published');
        $post->setExpiresAt(new \DateTimeImmutable('2026-11-28 00:00:00'));
        $post->setSwapPostId(99);
        $this->repo()->save($post);

        $reloaded = $this->repo()->findById($post->getId());
        self::assertNotNull($reloaded);
        self::assertSame('2026-11-28 00:00:00', $reloaded->getExpiresAt()?->format('Y-m-d H:i:s'));
        self::assertSame(99, $reloaded->getSwapPostId());
    }

    public function testFindDueForExpiryOnlyReturnsDuePublished(): void
    {
        $due = $this->makePage('Due', 'due', 'published');
        $due->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $this->repo()->save($due);

        $future = $this->makePage('Future', 'future', 'published');
        $future->setExpiresAt(new \DateTimeImmutable('+1 hour'));
        $this->repo()->save($future);

        $draft = $this->makePage('Draft', 'draft-page', 'draft');
        $draft->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $this->repo()->save($draft);

        $this->makePage('No Expiry', 'no-expiry', 'published');

        $ids = array_map(static fn (Post $p): int => $p->getId(), $this->repo()->findDueForExpiry());
        self::assertSame([$due->getId()], $ids);
    }

    public function testPerformSwapHandsOverSlugAtomically(): void
    {
        $live = $this->makePage('Kampanya', 'kampanya', 'published');
        $next = $this->makePage('Kampanya Yeni', 'kampanya-copy', 'draft');

        $this->repo()->performSwap($live, $next, new Slug('kampanya-expired-test'));

        $oldLive = $this->repo()->findById($live->getId());
        $newLive = $this->repo()->findById($next->getId());

        self::assertSame('expired', $oldLive->getStatus());
        self::assertTrue($oldLive->isExpiredStatus());
        self::assertSame('kampanya-expired-test', (string) $oldLive->getSlug());
        self::assertNull($oldLive->getExpiresAt());
        self::assertNull($oldLive->getSwapPostId());

        self::assertSame('published', $newLive->getStatus());
        self::assertSame('kampanya', (string) $newLive->getSlug());

        $bySlug = $this->repo()->findBySlug(new Slug('kampanya'), 'page');
        self::assertNotNull($bySlug);
        self::assertSame('Kampanya Yeni', $bySlug->getTitle());
    }
}
