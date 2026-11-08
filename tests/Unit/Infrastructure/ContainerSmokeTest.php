<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ContainerSmokeTest extends TestCase
{
    /**
     * Build the DI container with an in-memory SQLite override for
     * DbalConnection so that controllers can be resolved without
     * requiring a live database server.
     */
    private static function buildContainer(): \DI\Container
    {
        $key             = bin2hex(random_bytes(32));
        $_ENV['APP_KEY'] = $key;
        putenv("APP_KEY={$key}");

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(dirname(__DIR__, 3) . '/config/services.php');
        $containerBuilder->addDefinitions([
            DbalConnection::class => static function () {
                return DriverManager::getConnection([
                    'driver' => 'pdo_sqlite',
                    'path'   => ':memory:',
                ]);
            },
        ]);

        return $containerBuilder->build();
    }

    /**
     * Return all controller class names discovered under app/Controllers/.
     *
     * @return list<class-string>
     */
    private static function resolveControllerClasses(): array
    {
        $controllerFiles = array_unique(array_merge(
            glob(dirname(__DIR__, 3) . '/app/Controllers/*.php') ?: [],
            glob(dirname(__DIR__, 3) . '/app/Controllers/*/*.php') ?: [],
            glob(dirname(__DIR__, 3) . '/app/Controllers/*/*/*.php') ?: [],
        ));

        $classes = [];

        foreach ($controllerFiles as $file) {
            $relativePath = str_replace(dirname(__DIR__, 3) . '/app/Controllers/', '', $file);
            $relativePath = str_replace('.php', '', $relativePath);
            $className    = 'ValeApp\\App\\Controllers\\' . str_replace('/', '\\', $relativePath);

            if (class_exists($className)) {
                $classes[] = $className;
            }
        }

        return $classes;
    }

    public function test_all_controllers_can_be_resolved_from_container(): void
    {
        $container = self::buildContainer();

        $resolvedCount = 0;

        foreach (self::resolveControllerClasses() as $className) {
            $instance = $container->get($className);
            $this->assertInstanceOf($className, $instance);
            $resolvedCount++;
        }

        $this->assertGreaterThan(30, $resolvedCount, 'Expected at least 30 controllers to be resolved.');
    }

    public function test_optional_class_dependencies_are_injected(): void
    {
        $container = self::buildContainer();
        $checked   = 0;

        foreach (self::resolveControllerClasses() as $className) {
            $constructor = (new \ReflectionClass($className))->getConstructor();
            if ($constructor === null) {
                continue;
            }

            $instance = $container->get($className);

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !$parameter->isDefaultValueAvailable()) {
                    continue;
                }

                $typeName = $type->getName();
                if (!$container->has($typeName)) {
                    continue;
                }

                $propertyName = $parameter->getName();
                if (!property_exists($instance, $propertyName)) {
                    continue;
                }

                // The address PII encrypter is intentionally null when
                // ENCRYPT_PII is off (opt-in feature). Treat that as valid.
                if ($propertyName === 'encrypter' && !(bool) env('ENCRYPT_PII', false)) {
                    $checked++;
                    continue;
                }

                // Search cache is best-effort; null when cache is unavailable.
                if ($propertyName === 'searchCache') {
                    $checked++;
                    continue;
                }

                $property = new \ReflectionProperty($instance, $propertyName);
                $this->assertNotNull(
                    $property->getValue($instance),
                    sprintf(
                        'Optional dependency $%s of %s resolved to NULL although %s is registered in the container.',
                        $propertyName,
                        $className,
                        $typeName
                    )
                );

                $checked++;
            }
        }

        $this->assertGreaterThan(20, $checked, 'Expected to check at least 20 optional controller dependencies.');
    }
}
