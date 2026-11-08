<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use ValeApp\App\Controllers\Admin\ExchangeRateController;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

final class ExchangeRateControllerTest extends TestCase
{
    public function testRefreshWithAjaxHeaderReturnsJson(): void
    {
        $saved     = [];
        $settings  = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null) => $saved[$key] ?? $default
        );
        $settings->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$saved): void {
                $saved[$key] = (string) $value;
            }
        );

        $service = new ExchangeRateService($settings);
        $session = $this->createStub(SessionManager::class);

        $controller = new ExchangeRateController($service, $session);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/exchange-rates/refresh')
            ->withHeader('X-Requested-With', 'XMLHttpRequest');

        $result = $controller->refresh($request, new Response());

        // No provider configured → JSON error, not a redirect.
        self::assertSame(422, $result->getStatusCode());
        self::assertSame('application/json', $result->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $result->getBody(), true);
        self::assertFalse($payload['ok']);
        self::assertStringContainsString('No exchange rate provider', $payload['message']);
    }

    public function testRefreshWithoutAjaxHeaderRedirects(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturn(null);
        $session = $this->createStub(SessionManager::class);

        $controller = new ExchangeRateController(new ExchangeRateService($settings), $session);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/exchange-rates/refresh');

        $result = $controller->refresh($request, new Response());

        self::assertSame(302, $result->getStatusCode());
        self::assertStringContainsString('/settings', $result->getHeaderLine('Location'));
    }
}
