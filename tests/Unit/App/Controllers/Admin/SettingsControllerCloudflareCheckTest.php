<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use ValeApp\App\Controllers\Admin\SettingsController;

final class SettingsControllerCloudflareCheckTest extends TestCase
{
    public function testCheckCloudflareDetectsHeaderFromRequest(): void
    {
        $reflector  = new \ReflectionClass(SettingsController::class);
        $controller = $reflector->newInstanceWithoutConstructor();

        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('GET', '/admin/analytics/check-cloudflare')
            ->withHeader('CF-RAY', '1234567890abcdef-FRA');

        $response       = new Response();
        $resultResponse = $controller->checkCloudflare($request, $response);

        $this->assertSame(200, $resultResponse->getStatusCode());
        $body = (string) $resultResponse->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['ok']);
        $this->assertTrue($data['is_cloudflare']);
        $this->assertSame('request_header', $data['detected_via']);
    }

    public function testCheckCloudflareReportsFalseWhenNoHeadersOnLocalhost(): void
    {
        $reflector  = new \ReflectionClass(SettingsController::class);
        $controller = $reflector->newInstanceWithoutConstructor();

        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('GET', '/admin/analytics/check-cloudflare', [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response       = new Response();
        $resultResponse = $controller->checkCloudflare($request, $response);

        $this->assertSame(200, $resultResponse->getStatusCode());
        $body = (string) $resultResponse->getBody();
        $data = json_decode($body, true);

        $this->assertTrue($data['ok']);
        $this->assertFalse($data['is_cloudflare']);
    }
}
