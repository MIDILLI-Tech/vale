<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\TwigFilter;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class TwigIsoDateTest extends TestCase
{
    private string $defaultTz;

    protected function setUp(): void
    {
        $this->defaultTz = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTz);
    }

    private function twigEnv(): \Twig\Environment
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(base_path('config/services.php'));
        $builder->addDefinitions([
            DbalSettingsRepository::class => new class () extends DbalSettingsRepository {
                public function __construct()
                {
                }

                public function get(string $key, ?string $default = null): ?string
                {
                    return $default;
                }

                public function bool(string $key, bool $default = false): bool
                {
                    return $default;
                }

                public function set(string $key, string $value, string $group = 'general', string $type = 'string'): void
                {
                }
            },
        ]);
        $container = $builder->build();

        /** @var Twig $twig */
        $twig = $container->get(Twig::class);

        return $twig->getEnvironment();
    }

    private function isoDate(): callable
    {
        $filter = $this->twigEnv()->getFilter('iso_date');
        self::assertInstanceOf(TwigFilter::class, $filter);
        $callable = $filter->getCallable();
        self::assertIsCallable($callable);

        return $callable;
    }

    public function testNaiveSummerStringGetsSiteOffset(): void
    {
        self::assertSame('2026-07-01T12:00:00+02:00', ($this->isoDate())('2026-07-01 12:00:00'));
    }

    public function testNaiveWinterStringGetsSiteOffset(): void
    {
        self::assertSame('2026-01-01T12:00:00+01:00', ($this->isoDate())('2026-01-01 12:00:00'));
    }

    public function testAwareObjectKeepsItsOwnOffset(): void
    {
        $utc = new \DateTimeImmutable('2026-07-01 12:00:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-07-01T12:00:00+00:00', ($this->isoDate())($utc));
    }

    public function testEmptyAndGarbageValues(): void
    {
        $iso = $this->isoDate();
        self::assertSame('', $iso(null));
        self::assertSame('', $iso(''));
        self::assertSame('not-a-date', $iso('not-a-date'));
    }

    public function testVtimePartialRendersVisitorAttributes(): void
    {
        $html = $this->twigEnv()->render('partials/_vtime.twig', [
            'value'    => '2026-07-01 12:00:00',
            'style'    => 'long',
            'withTime' => true,
        ]);

        self::assertStringContainsString('datetime="2026-07-01T12:00:00+02:00"', $html);
        self::assertStringContainsString('data-vtz', $html);
        self::assertStringContainsString('data-vtz-style="long"', $html);
        self::assertStringContainsString('data-vtz-time', $html);
    }

    public function testVtimePartialRendersNothingForEmptyValue(): void
    {
        $html = $this->twigEnv()->render('partials/_vtime.twig', ['value' => null]);

        self::assertSame('', trim($html));
    }
}
