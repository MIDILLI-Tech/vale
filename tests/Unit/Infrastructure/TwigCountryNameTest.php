<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class TwigCountryNameTest extends TestCase
{
    public function testCountryNameFunctionRendersLocalizedCountry(): void
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
            CouponRepositoryInterface::class => new class () implements CouponRepositoryInterface {
                public function findById(int $id): ?Coupon
                {
                    return null;
                }

                public function findByCode(string $code): ?Coupon
                {
                    return null;
                }

                /** @return Coupon[] */
                public function findAll(): array
                {
                    return [];
                }

                public function save(Coupon $coupon): Coupon
                {
                    return $coupon;
                }

                public function delete(int $id): void
                {
                }

                public function incrementUses(int $id): void
                {
                }

                public function incrementUsesIfAvailable(int $id): bool
                {
                    return false;
                }

                public function decrementUses(int $id): void
                {
                }

                public function countUsesByEmail(string $code, string $email): int
                {
                    return 0;
                }
            },
        ]);
        $container = $builder->build();

        /** @var Twig $twig */
        $twig = $container->get(Twig::class);
        $env  = $twig->getEnvironment();

        $func = $env->getFunction('country_name');
        $this->assertNotNull($func);

        $callable = $func->getCallable();
        $this->assertIsCallable($callable);

        $resultTr = $callable('TR');
        $this->assertNotEmpty($resultTr);

        $resultEmpty = $callable('');
        $this->assertSame('', $resultEmpty);
    }
}
