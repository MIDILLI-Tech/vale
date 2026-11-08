<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use ValeApp\App\Middleware\CsrfMiddleware;
use ValeApp\Infrastructure\Security\CsrfManager;
use ValeApp\Infrastructure\Session\SessionManager;

final class CsrfMiddlewareTest extends TestCase
{
    private CsrfManager $csrf;
    private CsrfMiddleware $middleware;

    protected function setUp(): void
    {
        // Use PHP's native session in CLI (in-memory for tests)
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];

        $session          = new SessionManager(['lifetime' => 120, 'secure' => false, 'httponly' => true, 'samesite' => 'Lax']);
        $this->csrf       = new CsrfManager($session, ['token_name' => '_csrf_token', 'header_name' => 'X-CSRF-Token']);
        $this->middleware = new CsrfMiddleware($this->csrf);
    }

    public function testGetRequestPassesThrough(): void
    {
        $request = $this->requestWithMethodAndPath('GET', '/any/path');
        $handler = $this->passThroughHandler();

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPostToApiPathSkipsCsrf(): void
    {
        $request = $this->requestWithMethodAndPath('POST', '/api/v1/posts');
        $handler = $this->passThroughHandler();

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPostToWebhooksStripeSkipsCsrf(): void
    {
        $request = $this->requestWithMethodAndPath('POST', '/webhooks/stripe');
        $handler = $this->passThroughHandler();

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPostToWebhooksPaypalSkipsCsrf(): void
    {
        $request = $this->requestWithMethodAndPath('POST', '/webhooks/paypal');
        $handler = $this->passThroughHandler();

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPostToUnsubscribePathSkipsCsrf(): void
    {
        $request = $this->requestWithMethodAndPath('POST', '/newsletter/unsubscribe/abc123');
        $handler = $this->passThroughHandler();

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPostToLocalizedUnsubscribePathSkipsCsrf(): void
    {
        $request = $this->requestWithMethodAndPath('POST', '/tr/newsletter/unsubscribe/abc123');
        $handler = $this->passThroughHandler();

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPostToAdminPathWithValidTokenPasses(): void
    {
        $token   = $this->csrf->getToken(); // generates & stores token in session
        $request = $this->requestWithMethodAndPath('POST', '/admin/settings', ['_csrf_token' => $token]);
        $handler = $this->passThroughHandler();

        $response = $this->middleware->process($request, $handler);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testPostWithInvalidCsrfTokenReturns403(): void
    {
        $this->csrf->getToken(); // initialize token in session
        $request = $this->requestWithMethodAndPath('POST', '/admin/settings', ['_csrf_token' => 'invalid_garbage']);
        $handler = $this->passThroughHandler();

        $response = $this->middleware->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $this->csrf->getToken(); // initialize
        $request = $this->requestWithMethodAndPath('DELETE', '/admin/posts/1', ['_csrf_token' => 'wrong']);
        $handler = $this->passThroughHandler();

        $response = $this->middleware->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $bodyParams
     */
    private function requestWithMethodAndPath(string $method, string $path, array $bodyParams = []): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getParsedBody')->willReturn($bodyParams);
        $request->method('getHeaderLine')->willReturn('');

        return $request;
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(200));
        return $handler;
    }
}
