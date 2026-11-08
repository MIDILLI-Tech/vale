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
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Persistence\DbalTagRepository;

/**
 * count()/findAllWithUsageCount() must accept the search filter the admin
 * tags page sends and produce valid SQL on every engine. Regression: the
 * server-side tags search once navigated with ?search= while the
 * repository ignored the term, so large libraries showed unfiltered rows.
 */
final class DbalTagRepositorySearchTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $dbFile = sys_get_temp_dir() . '/vl_tagsearch_' . bin2hex(random_bytes(8)) . '.sqlite';

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

    private function repo(): DbalTagRepository
    {
        return new DbalTagRepository(self::$db);
    }

    private function insertTag(string $name, string $slug, string $contentType = 'post'): int
    {
        self::$db->insert(VL_DB_PREFIX . 'tags', [
            'name'         => $name,
            'slug'         => $slug,
            'content_type' => $contentType,
        ]);

        return (int) self::$db->lastInsertId();
    }

    public function testSearchMatchesName(): void
    {
        $this->insertTag('Kamelya', 'kamelya');
        $this->insertTag('Unrelated', 'unrelated');

        self::assertSame(1, $this->repo()->count(null, null, false, 'kamelya'));

        $rows = $this->repo()->findAllWithUsageCount(
            new Pagination(1, 50),
            null,
            null,
            false,
            'kamelya',
        );
        self::assertCount(1, $rows);
        self::assertSame('Kamelya', $rows[0]['tag']->getName());
        self::assertSame(0, $rows[0]['usageCount']);
    }

    public function testSearchMatchesSlugCaseInsensitively(): void
    {
        $this->insertTag('Blue Pill', 'blue-pill');

        self::assertSame(1, $this->repo()->count(null, null, false, 'BLUE-PILL'));
    }

    public function testSearchCombinesWithContentType(): void
    {
        $this->insertTag('Kamelya', 'kamelya', 'post');
        $this->insertTag('Kamelya', 'kamelya-page', 'page');

        self::assertSame(1, $this->repo()->count('post', null, false, 'kamelya'));
        self::assertSame(2, $this->repo()->count(null, null, false, 'kamelya'));
    }

    public function testNullSearchKeepsPreviousBehaviour(): void
    {
        $this->insertTag('Kamelya', 'kamelya');
        $this->insertTag('Unrelated', 'unrelated');

        self::assertSame(2, $this->repo()->count());
        self::assertCount(2, $this->repo()->findAllWithUsageCount(new Pagination(1, 50)));
    }

    public function testSearchWithoutMatchesReturnsEmpty(): void
    {
        $this->insertTag('Kamelya', 'kamelya');

        self::assertSame(0, $this->repo()->count(null, null, false, ' inception '));
        self::assertSame(
            [],
            $this->repo()->findAllWithUsageCount(
                new Pagination(1, 50),
                null,
                null,
                false,
                'inception',
            ),
        );
    }
}
