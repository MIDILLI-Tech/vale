<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use ValeApp\App\Middleware\SecurityHeadersMiddleware;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    private function processRequest(string $url = 'http://localhost/blog/hello'): ResponseInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $request = (new ServerRequestFactory())->createServerRequest('GET', $url);

        return (new SecurityHeadersMiddleware())->process($request, $handler);
    }

    public function testCspFrameSourcesAllowExternalCommentSystemOrigins(): void
    {
        $csp = $this->processRequest()->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString("frame-src 'self'", $csp);
        self::assertStringContainsString('https://disqus.com https://*.disqus.com', $csp);
        self::assertStringContainsString('https://giscus.app', $csp);
        self::assertStringContainsString('https://*.facebook.com', $csp);
    }

    public function testCspFrameSourcesAllowMediaEmbedAndPaymentOrigins(): void
    {
        $csp = $this->processRequest()->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString('https://www.youtube.com', $csp);
        self::assertStringContainsString('https://player.vimeo.com', $csp);
        self::assertStringContainsString('https://js.stripe.com', $csp);
    }

    public function testCspFormActionAllowsPaymentHandoffOrigins(): void
    {
        $csp = $this->processRequest()->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString("form-action 'self'", $csp);
        self::assertStringContainsString('https://www.paypal.com', $csp);
        self::assertStringContainsString('https://www.sandbox.paypal.com', $csp);
        self::assertStringContainsString('https://checkout.mollie.com', $csp);
        self::assertStringContainsString('https://pay.checkout.com', $csp);
        self::assertStringContainsString('https://secure.2checkout.com', $csp);
    }

    public function testSecurityHeadersAreAlwaysPresent(): void
    {
        $response = $this->processRequest();

        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }
}
