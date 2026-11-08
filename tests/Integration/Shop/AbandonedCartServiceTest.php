<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Shop;

use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Environment as TwigEnvironment;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Shop\AbandonedCartService;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Database\Connection;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class AbandonedCartServiceTest extends TestCase
{
    private static string $testDbPath;
    private static DbalConnection $db;

    /** @var list<MailMessage> */
    private array $sent = [];

    public static function setUpBeforeClass(): void
    {
        self::$testDbPath = sys_get_temp_dir() . '/vl_abandoned_cart_test_' . bin2hex(random_bytes(8)) . '.sqlite';

        $_ENV['APP_KEY']   = bin2hex(random_bytes(32));
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_NAME']   = self::$testDbPath;
        putenv('APP_KEY=' . $_ENV['APP_KEY']);
        putenv('DB_DRIVER=sqlite');
        putenv('DB_NAME=' . self::$testDbPath);

        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        $phinxApp = new \Phinx\Console\PhinxApplication();
        $wrapper  = new \Phinx\Wrapper\TextWrapper($phinxApp, [
            'configuration' => dirname(__DIR__, 3) . '/phinx.php',
            'environment'   => 'default',
        ]);
        $wrapper->getMigrate();

        self::$db = Connection::createFromEnv();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$db)) {
            try {
                self::$db->close();
            } catch (\Throwable) {
            }
        }
        gc_collect_cycles();
        clearstatcache();
        @unlink(self::$testDbPath);
        @unlink(self::$testDbPath . '-wal');
        @unlink(self::$testDbPath . '-shm');
    }

    protected function setUp(): void
    {
        self::$db->executeStatement('DELETE FROM ' . VL_DB_PREFIX . 'abandoned_carts');
        $this->sent = [];
    }

    /**
     * @param array<string, string> $values
     */
    private function makeSettings(array $values = []): SettingsRepositoryInterface
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $values[$key] ?? $default,
        );
        $settings->method('bool')->willReturnCallback(
            static fn (string $key, bool $default = false): bool => (bool) ($values[$key] ?? $default),
        );

        return $settings;
    }

    private function makeService(SettingsRepositoryInterface $settings): AbandonedCartService
    {
        $sent   = &$this->sent;
        $mailer = $this->createStub(MailInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (MailMessage $message) use (&$sent): void {
                $sent[] = $message;
            },
        );
        $mailer->method('getMaxBatchSize')->willReturn(50);
        $mailer->method('driverName')->willReturn('test');

        $twigEnv = $this->createStub(TwigEnvironment::class);
        $twigEnv->method('render')->willReturn('<p>reminder</p>');
        $twig = $this->createStub(Twig::class);
        $twig->method('getEnvironment')->willReturn($twigEnv);

        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturn('You left something in your cart!');

        $mailService = new MailService(
            $mailer,
            $twig,
            $settings,
            $translator,
            $this->createStub(ThemeManager::class),
        );

        return new AbandonedCartService(self::$db, $mailService, $settings);
    }

    private function findRow(string $email): array|false
    {
        return self::$db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'abandoned_carts WHERE email = ?',
            [$email],
        );
    }

    public function testTouchCartCreatesGuestRowWithNormalizedEmail(): void
    {
        $service = $this->makeService($this->makeSettings());

        $service->touchCart(null, 'Guest@Example.COM', json_encode([['qty' => 2]]), 50.0, 'EUR', 'en');

        $row = $this->findRow('guest@example.com');
        self::assertNotFalse($row);
        self::assertNull($row['user_id']);
        self::assertSame(0, (int) $row['reminder_count']);
        self::assertNull($row['recovered_at']);
    }

    public function testTouchCartUpdatesExistingRowWithoutResettingActiveReminderCycle(): void
    {
        $service = $this->makeService($this->makeSettings());

        $service->touchCart(null, 'guest@example.com', json_encode([['qty' => 1]]), 10.0, 'EUR');

        $remindedAt = date('Y-m-d H:i:s', time() - 3600);
        self::$db->update(VL_DB_PREFIX . 'abandoned_carts', [
            'reminder_count'   => 2,
            'last_reminded_at' => $remindedAt,
        ], ['email' => 'guest@example.com']);

        $service->touchCart(null, 'guest@example.com', json_encode([['qty' => 3]]), 30.0, 'EUR');

        $row = $this->findRow('guest@example.com');
        self::assertNotFalse($row);
        self::assertSame(2, (int) $row['reminder_count'], 'An active reminder cycle must not be reset.');
        self::assertSame($remindedAt, $row['last_reminded_at']);
    }

    public function testTouchCartStartsFreshCycleAfterRecovery(): void
    {
        $service = $this->makeService($this->makeSettings());

        $service->touchCart(null, 'guest@example.com', json_encode([['qty' => 1]]), 10.0, 'EUR');
        $service->markRecoveredByEmail('guest@example.com');

        $recovered = $this->findRow('guest@example.com');
        self::assertNotFalse($recovered);
        self::assertNotNull($recovered['recovered_at']);

        self::$db->update(VL_DB_PREFIX . 'abandoned_carts', [
            'reminder_count'   => 3,
            'last_reminded_at' => date('Y-m-d H:i:s'),
        ], ['email' => 'guest@example.com']);

        $service->touchCart(null, 'guest@example.com', json_encode([['qty' => 2]]), 20.0, 'EUR');

        $row = $this->findRow('guest@example.com');
        self::assertNotFalse($row);
        self::assertNull($row['recovered_at'], 'A re-abandoned cart must become active again.');
        self::assertSame(0, (int) $row['reminder_count']);
        self::assertNull($row['last_reminded_at']);
    }

    public function testMarkRecoveredMarksRowsByUserId(): void
    {
        $service = $this->makeService($this->makeSettings());

        $service->touchCart(7, 'member@example.com', json_encode([['qty' => 1]]), 10.0, 'EUR');
        $service->markRecovered(7);

        $row = $this->findRow('member@example.com');
        self::assertNotFalse($row);
        self::assertNotNull($row['recovered_at']);
    }

    public function testMarkRecoveredByEmailIsCaseInsensitive(): void
    {
        $service = $this->makeService($this->makeSettings());

        $service->touchCart(null, 'guest@example.com', json_encode([['qty' => 1]]), 10.0, 'EUR');
        $service->markRecoveredByEmail('GUEST@EXAMPLE.COM');

        $row = $this->findRow('guest@example.com');
        self::assertNotFalse($row);
        self::assertNotNull($row['recovered_at']);
    }

    public function testProcessDueReturnsZeroWhenDisabled(): void
    {
        $service = $this->makeService($this->makeSettings(['abandoned_cart_enabled' => '0']));

        $service->touchCart(null, 'guest@example.com', json_encode([['qty' => 1]]), 10.0, 'EUR');
        self::$db->update(VL_DB_PREFIX . 'abandoned_carts', [
            'updated_at' => date('Y-m-d H:i:s', time() - 10800),
        ], ['email' => 'guest@example.com']);

        self::assertSame(0, $service->processDue());
        self::assertCount(0, $this->sent);
    }

    public function testProcessDueSendsReminderForDueCart(): void
    {
        $service = $this->makeService($this->makeSettings([
            'abandoned_cart_enabled'                 => '1',
            'abandoned_cart_delay_hours'             => '2',
            'abandoned_cart_max_reminders'           => '3',
            'abandoned_cart_reminder_interval_hours' => '24',
        ]));

        $service->touchCart(null, 'guest@example.com', json_encode([['qty' => 2]]), 50.0, 'EUR');
        self::$db->update(VL_DB_PREFIX . 'abandoned_carts', [
            'updated_at' => date('Y-m-d H:i:s', time() - 10800),
        ], ['email' => 'guest@example.com']);

        self::assertSame(1, $service->processDue());
        self::assertCount(1, $this->sent);

        $row = $this->findRow('guest@example.com');
        self::assertNotFalse($row);
        self::assertSame(1, (int) $row['reminder_count']);
        self::assertNotNull($row['last_reminded_at']);
    }

    public function testProcessDueSkipsRecoveredCarts(): void
    {
        $service = $this->makeService($this->makeSettings([
            'abandoned_cart_enabled'                 => '1',
            'abandoned_cart_delay_hours'             => '2',
            'abandoned_cart_max_reminders'           => '3',
            'abandoned_cart_reminder_interval_hours' => '24',
        ]));

        $service->touchCart(null, 'guest@example.com', json_encode([['qty' => 1]]), 10.0, 'EUR');
        $service->markRecoveredByEmail('guest@example.com');
        self::$db->update(VL_DB_PREFIX . 'abandoned_carts', [
            'updated_at' => date('Y-m-d H:i:s', time() - 10800),
        ], ['email' => 'guest@example.com']);

        self::assertSame(0, $service->processDue());
        self::assertCount(0, $this->sent);
    }

    public function testProcessDueSkipsCartsAtMaxReminders(): void
    {
        $service = $this->makeService($this->makeSettings([
            'abandoned_cart_enabled'                 => '1',
            'abandoned_cart_delay_hours'             => '2',
            'abandoned_cart_max_reminders'           => '3',
            'abandoned_cart_reminder_interval_hours' => '24',
        ]));

        $service->touchCart(null, 'guest@example.com', json_encode([['qty' => 1]]), 10.0, 'EUR');
        self::$db->update(VL_DB_PREFIX . 'abandoned_carts', [
            'reminder_count'   => 3,
            'last_reminded_at' => date('Y-m-d H:i:s', time() - 172800),
            'updated_at'       => date('Y-m-d H:i:s', time() - 10800),
        ], ['email' => 'guest@example.com']);

        self::assertSame(0, $service->processDue());
        self::assertCount(0, $this->sent);
    }
}
