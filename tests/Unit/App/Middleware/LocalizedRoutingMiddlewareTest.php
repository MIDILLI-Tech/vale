<?php

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Middleware\LocaleMiddleware;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

class LocalizedRoutingMiddlewareTest extends TestCase
{
    private $settings;
    private $translator;
    private $session;
    private $twig;
    private $menus;
    private $themeManager;
    private $users;
    private $posts;
    private $handler;

    protected function setUp(): void
    {
        $this->settings     = $this->createStub(DbalSettingsRepository::class);
        $this->translator   = $this->createStub(Translator::class);
        $this->session      = $this->createStub(SessionManager::class);
        $this->twig         = $this->createStub(Twig::class);
        $this->menus        = $this->createStub(MenuRepositoryInterface::class);
        $this->themeManager = $this->createStub(ThemeManager::class);
        $this->users        = $this->createStub(UserRepositoryInterface::class);
        $this->posts        = $this->createStub(PostRepositoryInterface::class);
        $this->handler      = $this->createStub(RequestHandlerInterface::class);

        $this->handler->method('handle')->willReturn(new Response());

        // Set the static instance for helper functions
        $reflection   = new \ReflectionClass(DbalSettingsRepository::class);
        $instanceProp = $reflection->getProperty('instance');
        $instanceProp->setValue(null, $this->settings);

        $translatorReflection   = new \ReflectionClass(Translator::class);
        $translatorInstanceProp = $translatorReflection->getProperty('instance');
        $translatorInstanceProp->setValue(null, $this->translator);
    }

    public function testLocalizedPrefixRedirection()
    {
        $this->translator->method('getSupportedLocales')->willReturn(['en', 'tr']);
        $this->translator->method('getLocale')->willReturn('tr');
        $this->settings->method('get')->willReturnMap([
            ['multi_language_content', '0', '1'],
            ['default_locale', 'en', 'en'],
            ['routing_blog_prefix', '/blog', '/blog'],
            ['routing_blog_prefix_tr', null, '/yazilar'],
        ]);

        $env = $this->createStub(\Twig\Environment::class);
        $this->twig->method('getEnvironment')->willReturn($env);

        // Accessing /tr/blog should redirect to /tr/yazilar
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/tr/blog');
        $middleware = new LocaleMiddleware(
            $this->translator,
            $this->settings,
            $this->session,
            $this->twig,
            $this->menus,
            $this->themeManager,
            $this->users,
            $this->posts
        );

        $response = $middleware->process($request, $this->handler);
        $this->assertEquals(301, $response->getStatusCode());
        $this->assertStringContainsString('/tr/yazilar', $response->getHeaderLine('Location'));
    }

    public function testLocalizedPrefixRewriting()
    {
        $this->translator->method('getSupportedLocales')->willReturn(['en', 'tr']);
        $this->translator->method('getLocale')->willReturn('tr');
        $this->settings->method('get')->willReturnMap([
            ['multi_language_content', '0', '1'],
            ['default_locale', 'en', 'en'],
            ['routing_blog_prefix', '/blog', '/blog'],
            ['routing_blog_prefix_tr', null, '/yazilar'],
        ]);

        $env = $this->createStub(\Twig\Environment::class);
        $this->twig->method('getEnvironment')->willReturn($env);

        // Accessing /tr/yazilar should be rewritten to /blog for Slim
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/tr/yazilar/some-post');
        $middleware = new LocaleMiddleware(
            $this->translator,
            $this->settings,
            $this->session,
            $this->twig,
            $this->menus,
            $this->themeManager,
            $this->users,
            $this->posts
        );

        $capturedRequest = null;
        $this->handler->method('handle')->willReturnCallback(function ($req) use (&$capturedRequest) {
            $capturedRequest = $req;
            return new Response();
        });

        $middleware->process($request, $this->handler);

        $this->assertNotNull($capturedRequest);
        $this->assertEquals('/blog/some-post', $capturedRequest->getUri()->getPath());
    }
}
