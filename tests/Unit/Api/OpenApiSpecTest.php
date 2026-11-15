<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the served OpenAPI document and routes/api.php from drifting apart.
 *
 * Every named /api/v1 route must have exactly one operation (same path, same
 * method, operationId equal to the route name), and every operation must point
 * back at a route. Each operation also has to declare x-vale-abilities so the
 * ability model stays machine readable.
 */
final class OpenApiSpecTest extends TestCase
{
    private const ROUTES_FILE = __DIR__ . '/../../../routes/api.php';
    private const SPEC_FILE   = __DIR__ . '/../../../resources/api/openapi.json';

    public function testSpecIsValidJson(): void
    {
        $raw = file_get_contents(self::SPEC_FILE);
        self::assertIsString($raw);
        self::assertNotSame('', $raw);
        self::assertIsArray(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testEveryRouteHasAnOperation(): void
    {
        $operations = $this->operations();

        foreach ($this->routes() as $route) {
            $key = $route['method'] . ' ' . $route['path'];
            self::assertArrayHasKey(
                $key,
                $operations,
                "Route {$key} ({$route['name']}) is missing from resources/api/openapi.json."
            );
            self::assertSame(
                $route['name'],
                $operations[$key]['operationId'] ?? null,
                "OperationId of {$key} must match the Slim route name."
            );
        }
    }

    public function testEveryOperationMapsToARoute(): void
    {
        $routes = [];
        foreach ($this->routes() as $route) {
            $routes[$route['method'] . ' ' . $route['path']] = $route['name'];
        }

        foreach ($this->operations() as $key => $operation) {
            self::assertArrayHasKey(
                $key,
                $routes,
                "Operation {$key} has no matching route in routes/api.php."
            );
            self::assertSame($routes[$key], $operation['operationId'] ?? null);
        }
    }

    public function testDeclaredAbilitiesMatchTheRoutes(): void
    {
        $operations = $this->operations();

        foreach ($this->routes() as $route) {
            $key      = $route['method'] . ' ' . $route['path'];
            $expected = $this->routeAbilities($route['chain']);
            $declared = $operations[$key]['x-vale-abilities'];

            if ($expected === null) {
                self::assertSame([], $declared, "{$key} has no ability middleware, x-vale-abilities must be empty.");
                continue;
            }

            self::assertSame(
                $expected,
                $declared,
                "x-vale-abilities of {$key} does not match the ability enforced in routes/api.php."
            );
        }
    }

    public function testOperationsDeclareAbilities(): void
    {
        foreach ($this->operations() as $key => $operation) {
            self::assertArrayHasKey(
                'x-vale-abilities',
                $operation,
                "Operation {$key} must declare x-vale-abilities."
            );
            self::assertIsArray($operation['x-vale-abilities'], "x-vale-abilities of {$key} must be an array.");

            if (($operation['security'] ?? null) === []) {
                self::assertSame(
                    [],
                    $operation['x-vale-abilities'],
                    "Public operation {$key} cannot require an ability."
                );
            }
        }
    }

    /**
     * Ability strings enforced by the route chain, or null when the route has
     * no ApiAbilityMiddleware (public or token-only endpoints).
     *
     * @return list<string>|null
     */
    private function routeAbilities(string $chain): ?array
    {
        if (preg_match_all('/\$handler,\s*\'([a-z]+:[a-z_]+)\'/', $chain, $matches) > 0) {
            return array_values(array_unique($matches[1]));
        }

        if (preg_match('/\$contentTypeMiddleware\(\s*\'([a-z_]+)\',\s*\'([a-z]+)\'\)/', $chain, $matches) === 1) {
            return [$matches[2] . ':' . $matches[1] . 's'];
        }

        return null;
    }

    /**
     * @return list<array{method: string, path: string, name: string, chain: string}>
     */
    private function routes(): array
    {
        $source = file_get_contents(self::ROUTES_FILE);
        self::assertIsString($source);

        // Each registration is split into its own chunk so the whole chain
        // (including the ->add(ability) calls that follow ->setName) is
        // available for the ability comparison.
        $chunks = preg_split('/\n\s*(?=\$group->(?:get|post|put|patch|delete)\()/', $source) ?: [];

        $routes = [];
        foreach ($chunks as $chunk) {
            if (preg_match('/^\s*\$group->(get|post|put|patch|delete)\(\s*\'([^\']+)\'/', $chunk, $head) !== 1) {
                continue;
            }
            if (preg_match('/->setName\(\'([^\']+)\'\)/', $chunk, $named) !== 1) {
                continue;
            }

            $routes[] = [
                'method' => strtoupper($head[1]),
                'path'   => '/api/v1' . $head[2],
                'name'   => $named[1],
                'chain'  => $chunk,
            ];
        }

        self::assertNotEmpty($routes, 'No named /api/v1 route found in routes/api.php.');

        return $routes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function operations(): array
    {
        $spec = json_decode((string) file_get_contents(self::SPEC_FILE), true, 512, JSON_THROW_ON_ERROR);

        $operations = [];
        foreach ($spec['paths'] ?? [] as $path => $methods) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (!isset($methods[$method])) {
                    continue;
                }
                $operations[strtoupper($method) . ' ' . $path] = $methods[$method];
            }
        }

        return $operations;
    }
}
