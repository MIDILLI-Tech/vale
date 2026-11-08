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
use ValeApp\Infrastructure\Persistence\DbalCampaignRepository;

/**
 * Campaign search matches subject, B-subject and sender email. HTML
 * bodies are only included when $searchBody is set (admin opt-in).
 */
final class DbalCampaignRepositorySearchTest extends TestCase
{
    private static DbalConnection $db;

    public static function setUpBeforeClass(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = sys_get_temp_dir() . '/vl_campaignsearch_' . bin2hex(random_bytes(8)) . '.sqlite';
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

    private function repo(): DbalCampaignRepository
    {
        return new DbalCampaignRepository(self::$db);
    }

    private function insertCampaign(string $subject, string $bodyHtml, array $overrides = []): void
    {
        self::$db->insert(VL_DB_PREFIX . 'newsletter_campaigns', array_merge([
            'subject'   => $subject,
            'body_html' => $bodyHtml,
        ], $overrides));
    }

    public function testSearchMatchesSubject(): void
    {
        $this->insertCampaign('Kamelya indirimi', '<p>Merhaba</p>');
        $this->insertCampaign('Diğer bülten', '<p>Selam</p>');

        self::assertSame(1, $this->repo()->count('kamelya'));
        $items = $this->repo()->findAll(new Pagination(1, 20), 'kamelya');
        self::assertCount(1, $items);
    }

    public function testSearchIgnoresBodyByDefault(): void
    {
        $this->insertCampaign('Plain Subject', '<p>Kamelya fırsatı</p>');

        self::assertSame(0, $this->repo()->count('kamelya'));
        self::assertSame([], $this->repo()->findAll(new Pagination(1, 20), 'kamelya'));
    }

    public function testSearchBodyIncludesHtmlWhenFlagSet(): void
    {
        $this->insertCampaign('Plain Subject', '<p>Kamelya fırsatı</p>');

        self::assertSame(1, $this->repo()->count('kamelya', true));
        self::assertCount(1, $this->repo()->findAll(new Pagination(1, 20), 'kamelya', true));
    }
}
