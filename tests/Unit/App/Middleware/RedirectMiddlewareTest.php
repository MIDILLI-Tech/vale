<?php

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use ValeApp\App\Middleware\RedirectMiddleware;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Translation\Translator;

class RedirectMiddlewareTest extends TestCase
{
    private $redirectService;
    private $translator;
    private $settings;
    private $handler;

    protected function setUp(): void
    {
        $this->redirectService = $this->createStub(RedirectService::class);
        $this->translator      = $this->createStub(Translator::class);
        $this->settings        = $this->createStub(DbalSettingsRepository::class);
        $this->handler         = $this->createStub(RequestHandlerInterface::class);
    }

    protected function tearDown(): void
    {
        // The locale test below swaps the Translator/Settings singletons
        // (see reflection use there) — reset them so later tests observe a
        // clean global state instead of a leaked 'tr' locale.
        foreach ([Translator::class, DbalSettingsRepository::class] as $class) {
            $prop = new \ReflectionProperty($class, 'instance');
            $prop->setValue(null, null);
        }
    }

    public function testRedirectFoundWithLocale()
    {
        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        $this->handler->method('handle')->willThrowException(
            new HttpNotFoundException((new ServerRequestFactory())->createServerRequest('GET', '/tr/some-old-page'))
        );

        $this->translator->method('getSupportedLocales')->willReturn(['en', 'tr']);
        $this->translator->method('getLocale')->willReturn('tr');
        $this->settings->method('get')->willReturnMap([
            ['multi_language_content', '0', '1'],
            ['default_locale', 'en', 'en'],
            ['content_languages', '', 'en,tr'],
        ]);

        $this->redirectService = $this->createMock(RedirectService::class);
        $this->redirectService->expects($this->once())
            ->method('findRedirect')
            ->with('/some-old-page')
            ->willReturn('/new-page');

        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/tr/some-old-page');
        $middleware = new RedirectMiddleware($this->redirectService, $this->translator, $this->settings);

        // Ensure helpers can access instances
        new \ValeApp\Infrastructure\Translation\Translator('', 'tr', ['en', 'tr']);

        $settingsMock = $this->createStub(\ValeApp\Infrastructure\Persistence\DbalSettingsRepository::class);
        $settingsMock->method('get')->willReturnMap([
            ['multi_language_content', '0', '1'],
            ['default_locale', 'en', 'en'],
            ['content_languages', '', 'en,tr'],
        ]);

        $ref  = new \ReflectionClass(\ValeApp\Infrastructure\Persistence\DbalSettingsRepository::class);
        $inst = $ref->getProperty('instance');
        $inst->setValue(null, $settingsMock);

        $response = $middleware->process($request, $this->handler);

        $this->assertEquals(301, $response->getStatusCode());
        $this->assertEquals('/tr/new-page', $response->getHeaderLine('Location'));
    }

    public function testRedirectPreservesQueryString()
    {
        if (!defined('VL_DB_PREFIX')) {
            define('VL_DB_PREFIX', 'vl_');
        }

        $this->handler->method('handle')->willThrowException(
            new HttpNotFoundException((new ServerRequestFactory())->createServerRequest('GET', '/old-page'))
        );

        $this->translator->method('getSupportedLocales')->willReturn(['en', 'tr']);
        $this->translator->method('getLocale')->willReturn('en');
        $this->settings->method('get')->willReturnMap([
            ['multi_language_content', '0', '0'],
            ['default_locale', 'en', 'en'],
            ['content_languages', '', 'en,tr'],
        ]);

        $this->redirectService = $this->createMock(RedirectService::class);
        $this->redirectService->expects($this->once())
            ->method('findRedirect')
            ->with('/old-page')
            ->willReturn('/new-page');

        new \ValeApp\Infrastructure\Translation\Translator('', 'en', ['en', 'tr']);

        $settingsMock = $this->createStub(\ValeApp\Infrastructure\Persistence\DbalSettingsRepository::class);
        $settingsMock->method('get')->willReturnMap([
            ['multi_language_content', '0', '0'],
            ['default_locale', 'en', 'en'],
        ]);

        $ref  = new \ReflectionClass(\ValeApp\Infrastructure\Persistence\DbalSettingsRepository::class);
        $inst = $ref->getProperty('instance');
        $inst->setValue(null, $settingsMock);

        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/old-page?utm_source=news&page=2');
        $middleware = new RedirectMiddleware($this->redirectService, $this->translator, $this->settings);

        $response = $middleware->process($request, $this->handler);

        $this->assertEquals(301, $response->getStatusCode());
        $this->assertEquals('/new-page?utm_source=news&page=2', $response->getHeaderLine('Location'));
    }

    public function testNoRedirectFoundPassesThrough()
    {
        $this->handler->method('handle')->willReturn(new Response(200));

        $this->redirectService->method('findRedirect')->willReturn(null);

        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/en/some-page');
        $middleware = new RedirectMiddleware($this->redirectService, $this->translator, $this->settings);

        $response = $middleware->process($request, $this->handler);

        $this->assertEquals(200, $response->getStatusCode());
    }
}
