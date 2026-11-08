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
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Persistence\DbalSubscriberRepository;

/**
 * The admin subscribers search must cover email, name and tags with the same
 * query regardless of dataset size, and the tags column must be sortable.
 * Regression: server-side search once ignored tags, so the same term matched
 * on small (client-side) tables but not once the row count crossed the limit.
 */
final class DbalSubscriberRepositorySearchTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $dbFile = sys_get_temp_dir() . '/vl_subsearch_' . bin2hex(random_bytes(8)) . '.sqlite';

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

    private function repo(): SubscriberRepositoryInterface
    {
        return new DbalSubscriberRepository(self::$db);
    }

    private function insertSubscriber(string $email, array $tags = []): int
    {
        self::$db->insert(VL_DB_PREFIX . 'newsletter_subscribers', [
            'email'   => $email,
            'name'    => '',
            'status'  => 'active',
            'tier'    => 'free',
            'token'   => bin2hex(random_bytes(16)),
        ]);
        $id = (int) self::$db->lastInsertId();
        foreach ($tags as $tag) {
            self::$db->insert(VL_DB_PREFIX . 'newsletter_subscriber_tags', [
                'subscriber_id' => $id,
                'tag'           => $tag,
            ]);
        }

        return $id;
    }

    public function testSearchMatchesTag(): void
    {
        $this->insertSubscriber('tagged@example.com', ['promo']);
        $this->insertSubscriber('plain@example.com');

        self::assertSame(1, $this->repo()->count(['search' => 'promo']));
        self::assertCount(1, $this->repo()->findAll(new Pagination(1, 50), ['search' => 'promo']));
    }

    public function testSearchMatchesTagCaseInsensitively(): void
    {
        $this->insertSubscriber('tagged@example.com', ['Summer-Sale']);

        self::assertSame(1, $this->repo()->count(['search' => 'summer-sale']));
    }

    public function testSearchStillMatchesEmailAndName(): void
    {
        $this->insertSubscriber('kamelya@example.com', ['promo']);
        self::$db->update(
            VL_DB_PREFIX . 'newsletter_subscribers',
            ['name' => 'Kamelya Yılmaz'],
            ['email' => 'kamelya@example.com'],
        );

        self::assertSame(1, $this->repo()->count(['search' => 'kamelya']));
        self::assertSame(1, $this->repo()->count(['search' => 'promo']));
    }

    public function testSearchCombinesWithStatusFilter(): void
    {
        $this->insertSubscriber('active-promo@example.com', ['promo']);
        $this->insertSubscriber('pending-promo@example.com', ['promo']);
        self::$db->update(
            VL_DB_PREFIX . 'newsletter_subscribers',
            ['status' => 'pending'],
            ['email' => 'pending-promo@example.com'],
        );

        self::assertSame(1, $this->repo()->count(['search' => 'promo', 'status' => 'pending']));
    }

    public function testSearchWithoutMatchesReturnsEmpty(): void
    {
        $this->insertSubscriber('tagged@example.com', ['promo']);

        self::assertSame(0, $this->repo()->count(['search' => 'inception']));
        self::assertSame([], $this->repo()->findAll(new Pagination(1, 50), ['search' => 'inception']));
    }

    public function testEmptySearchKeepsPreviousBehaviour(): void
    {
        $this->insertSubscriber('one@example.com', ['promo']);
        $this->insertSubscriber('two@example.com');

        self::assertSame(2, $this->repo()->count());
        self::assertCount(2, $this->repo()->findAll(new Pagination(1, 50)));
    }

    public function testFindAllSortsByTagsAscending(): void
    {
        $this->insertSubscriber('zeta@example.com', ['zebra']);
        $this->insertSubscriber('alpha@example.com', ['apple']);
        $this->insertSubscriber('none@example.com');

        $rows = $this->repo()->findAll(new Pagination(1, 50, 'tags', 'asc'));

        self::assertCount(3, $rows);
        self::assertSame('none@example.com', $rows[0]->getEmail());
        self::assertSame('alpha@example.com', $rows[1]->getEmail());
        self::assertSame('zeta@example.com', $rows[2]->getEmail());
    }

    public function testFindAllSortsByTagsDescending(): void
    {
        $this->insertSubscriber('zeta@example.com', ['zebra']);
        $this->insertSubscriber('alpha@example.com', ['apple']);

        $rows = $this->repo()->findAll(new Pagination(1, 50, 'tags', 'desc'));

        self::assertCount(2, $rows);
        self::assertSame('zeta@example.com', $rows[0]->getEmail());
        self::assertSame('alpha@example.com', $rows[1]->getEmail());
    }

    public function testFindAllSortsByFirstTagWhenMultiple(): void
    {
        $this->insertSubscriber('multi@example.com', ['mango', 'apricot']);
        $this->insertSubscriber('single@example.com', ['banana']);

        $rows = $this->repo()->findAll(new Pagination(1, 50, 'tags', 'asc'));

        self::assertSame(['multi@example.com', 'single@example.com'], array_map(
            fn (Subscriber $s) => $s->getEmail(),
            $rows,
        ));
    }

    public function testFindAllFallsBackForUnknownSortColumn(): void
    {
        $this->insertSubscriber('zeta@example.com');
        $this->insertSubscriber('alpha@example.com');

        $rows = $this->repo()->findAll(new Pagination(1, 50, 'not_a_column', 'asc'));

        self::assertCount(2, $rows);
        self::assertSame(
            ['alpha@example.com', 'zeta@example.com'],
            array_map(fn (Subscriber $s) => $s->getEmail(), $rows),
        );
    }
}
