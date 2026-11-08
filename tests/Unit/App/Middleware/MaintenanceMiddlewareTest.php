<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Middleware\MaintenanceMiddleware;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class MaintenanceMiddlewareTest extends TestCase
{
    private function middleware(string $maintenanceMode, ?Twig $twig = null): MaintenanceMiddleware
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $key === 'maintenance_mode' ? $maintenanceMode : $default,
        );

        return new MaintenanceMiddleware(
            $settings,
            $twig ?? $this->createStub(Twig::class),
        );
    }

    public function testHealthPathBypassesMaintenanceMode(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response(200));

        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/v1/health');

        $response = $this->middleware('1')->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testHealthPathBypassesMaintenanceModeUnderSubdirectory(): void
    {
        $previous        = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'http://localhost/vale';

        try {
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->willReturn(new Response(200));

            $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/vale/api/v1/health');

            $response = $this->middleware('1')->process($request, $handler);

            self::assertSame(200, $response->getStatusCode());
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_URL']);
            } else {
                $_ENV['APP_URL'] = $previous;
            }
        }
    }

    public function testOtherPathsStillBlockedDuringMaintenance(): void
    {
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturn(new Response(503));

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/some-page');

        $response = $this->middleware('1', $twig)->process($request, $handler);

        self::assertSame(503, $response->getStatusCode());
    }

    public function testHealthPathPassesThroughWhenMaintenanceOff(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(new Response(200));

        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/v1/health');

        $response = $this->middleware('0')->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }
}
