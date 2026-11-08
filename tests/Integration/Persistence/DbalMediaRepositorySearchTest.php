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
use ValeApp\Infrastructure\Persistence\DbalMediaRepository;

/**
 * findAll()/count() must accept the search and mime_type filters the media
 * browsers send (browse-json always combines type=image/ with ?q=) and
 * produce valid SQL on every engine. Regression: the search clause once
 * opened a parenthesis it never closed, so any request carrying ?q= died
 * with a syntax error.
 */
final class DbalMediaRepositorySearchTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $dbFile = sys_get_temp_dir() . '/vl_mediasearch_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = $dbFile;
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . $dbFile);

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

    private function repo(): DbalMediaRepository
    {
        return new DbalMediaRepository(self::$db);
    }

    private function insertMedia(string $originalName, string $mimeType, array $overrides = []): int
    {
        $data = array_merge([
            'user_id'       => 1,
            'filename'      => 'file-' . bin2hex(random_bytes(4)),
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'size'          => 1234,
            'path'          => '2026/09/' . $originalName,
        ], $overrides);

        self::$db->insert(VL_DB_PREFIX . 'media', $data);

        return (int) self::$db->lastInsertId();
    }

    public function testSearchMatchesOriginalName(): void
    {
        $this->insertMedia('kamelya-product.png', 'image/png');
        $this->insertMedia('unrelated.jpg', 'image/jpeg');

        $filters = ['search' => 'kamelya'];

        self::assertSame(1, $this->repo()->count($filters));
        self::assertCount(1, $this->repo()->findAll(new Pagination(1, 40), $filters));
    }

    public function testSearchMatchesAltTextAndI18nColumns(): void
    {
        $this->insertMedia('one.png', 'image/png', ['alt_text' => 'Kamelya bahçe']);
        $this->insertMedia('two.png', 'image/png', ['alt_text_i18n' => json_encode(['tr' => 'kamelya manzarası'], JSON_UNESCAPED_UNICODE)]);
        $this->insertMedia('three.png', 'image/png', ['description_i18n' => json_encode(['de' => 'Kamelya Zimmer'], JSON_UNESCAPED_UNICODE)]);
        $this->insertMedia('four.png', 'image/png', ['title' => 'Kamelya Suite']);

        self::assertSame(4, $this->repo()->count(['search' => 'kamelya']));
        // i18n JSON columns are searched too, even when the term appears only there
        self::assertSame(1, $this->repo()->count(['search' => 'manzarası']));
        self::assertSame(1, $this->repo()->count(['search' => 'Zimmer']));
    }

    public function testMimeFilterCombinesWithSearch(): void
    {
        // The exact combination the editor media picker sends: type=image/ + q=
        $this->insertMedia('kam-1.png', 'image/png');
        $this->insertMedia('kam-2.pdf', 'application/pdf');

        $filters = ['mime_type' => 'image/', 'search' => 'kam'];

        self::assertSame(1, $this->repo()->count($filters));

        $items = $this->repo()->findAll(new Pagination(1, 40), $filters);
        self::assertCount(1, $items);
        self::assertSame('kam-1.png', $items[0]->getOriginalName());
    }

    public function testMimeFilterPrefixMatching(): void
    {
        $this->insertMedia('a.png', 'image/png');
        $this->insertMedia('b.jpg', 'image/jpeg');
        $this->insertMedia('c.pdf', 'application/pdf');

        $items = $this->repo()->findAll(new Pagination(1, 40), ['mime_type' => 'image/']);
        self::assertCount(2, $items);
    }

    public function testMimeFilterCommaSeparatedPrefixes(): void
    {
        // The product gallery picker sends type=image/,video/
        $this->insertMedia('a.png', 'image/png');
        $this->insertMedia('b.mp4', 'video/mp4');
        $this->insertMedia('c.pdf', 'application/pdf');
        $this->insertMedia('d.mp3', 'audio/mpeg');

        $filters = ['mime_type' => 'image/,video/'];

        self::assertSame(2, $this->repo()->count($filters));

        $items = $this->repo()->findAll(new Pagination(1, 40), $filters);
        self::assertCount(2, $items);
        $names = array_map(fn ($m) => $m->getOriginalName(), $items);
        sort($names);
        self::assertSame(['a.png', 'b.mp4'], $names);
    }

    public function testSearchWithoutMatchesReturnsEmpty(): void
    {
        $this->insertMedia('sunset.png', 'image/png');

        self::assertSame(0, $this->repo()->count(['search' => 'kam']));
        self::assertSame([], $this->repo()->findAll(new Pagination(1, 40), ['search' => 'kam']));
    }
}
