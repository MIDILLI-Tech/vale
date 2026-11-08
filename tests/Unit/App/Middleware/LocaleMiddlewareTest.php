<?php

namespace ValeApp\Tests\Unit\App\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Middleware\LocaleMiddleware;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Menu\Entity\MenuItem;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

class LocaleMiddlewareTest extends TestCase
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
    }

    public function testLocaleFromUrlPrefix()
    {
        $this->translator = $this->createMock(Translator::class);
        $this->translator->method('getSupportedLocales')->willReturn(['en', 'tr', 'fr']);
        $this->settings->method('get')->willReturnMap([
            ['multi_language_content', '0', '1'],
        ]);

        $env = $this->createStub(\Twig\Environment::class);
        $this->twig->method('getEnvironment')->willReturn($env);

        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/tr/some-page');
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

        $this->translator->expects($this->once())->method('setLocale')->with('tr');

        $response = $middleware->process($request, $this->handler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testLocaleFromQueryParam()
    {
        $session = $this->createMock(SessionManager::class);
        $this->translator->method('getSupportedLocales')->willReturn(['en', 'tr', 'fr']);
        $this->settings->method('get')->willReturnMap([
            ['multi_language_content', '0', '1'],
        ]);

        $env = $this->createStub(\Twig\Environment::class);
        $this->twig->method('getEnvironment')->willReturn($env);

        $session->expects($this->once())->method('set')->with('user_locale', 'fr');

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/some-page')
            ->withQueryParams(['lang' => 'fr']);

        $middleware = new LocaleMiddleware(
            $this->translator,
            $this->settings,
            $session,
            $this->twig,
            $this->menus,
            $this->themeManager,
            $this->users,
            $this->posts
        );

        $response = $middleware->process($request, $this->handler);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/some-page', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('vl_locale=fr', $response->getHeaderLine('Set-Cookie'));
    }

    public function testQueryParamPersistsLoggedInUserLocale()
    {
        $session = $this->createMock(SessionManager::class);
        $users   = $this->createMock(UserRepositoryInterface::class);
        $user    = $this->createMock(\ValeApp\Domain\User\Entity\User::class);

        $this->translator->method('getSupportedLocales')->willReturn(['en', 'tr', 'fr']);
        $this->settings->method('get')->willReturnMap([
            ['multi_language_content', '0', '1'],
        ]);

        $env = $this->createStub(\Twig\Environment::class);
        $this->twig->method('getEnvironment')->willReturn($env);

        $session->method('get')->with('user_id')->willReturn(123);
        $users->method('findById')->with(123)->willReturn($user);
        $user->method('getLocale')->willReturn('en');

        $user->expects($this->once())->method('setLocale')->with('tr');
        $users->expects($this->once())->method('save')->with($user);
        $session->expects($this->once())->method('set')->with('user_locale', 'tr');

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/some-page')
            ->withQueryParams(['lang' => 'tr']);

        $middleware = new LocaleMiddleware(
            $this->translator,
            $this->settings,
            $session,
            $this->twig,
            $this->menus,
            $this->themeManager,
            $users,
            $this->posts
        );

        $response = $middleware->process($request, $this->handler);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertStringContainsString('vl_locale=tr', $response->getHeaderLine('Set-Cookie'));
    }

    public function testDisabledI18nDoesNothing()
    {
        $this->translator = $this->createMock(Translator::class);
        $this->translator->method('getSupportedLocales')->willReturn(['en', 'tr', 'fr']);
        $this->settings->method('get')->willReturnMap([
            ['multi_language_content', '0', '0'],
        ]);

        $env = $this->createStub(\Twig\Environment::class);
        $this->twig->method('getEnvironment')->willReturn($env);

        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/tr/some-page');
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

        $this->translator->expects($this->never())->method('setLocale')->with('tr');

        $middleware->process($request, $this->handler);
    }

    private function menuGlobalsFor(array $settingsMap): array
    {
        $this->translator->method('getSupportedLocales')->willReturn(['en']);
        $this->settings->method('get')->willReturnMap($settingsMap);

        $captured = [];
        $env      = $this->createStub(\Twig\Environment::class);
        $env->method('addGlobal')->willReturnCallback(function (string $name, mixed $value) use (&$captured): void {
            $captured[$name] = $value;
        });
        $this->twig->method('getEnvironment')->willReturn($env);

        $menu = Menu::fromRow(['id' => 1, 'name' => 'Main Menu', 'slug' => 'main-menu', 'location' => 'primary']);

        $shopItem = new MenuItem(1, 'Shop');
        $shopItem->setType('shop');
        $shopItem->setUrl('/legacy-shop');

        $memberItem = new MenuItem(1, 'Membership Plans');
        $memberItem->setType('membership');
        $memberItem->setUrl('/legacy-membership');

        $customItem = new MenuItem(1, 'About');
        $customItem->setType('custom');
        $customItem->setUrl('/about-us');

        $this->menus->method('findAllByLocation')->willReturnCallback(
            fn (string $location): array => $location === 'primary' ? [$menu] : []
        );
        $this->menus->method('findItemsByMenuId')->willReturn([$shopItem, $memberItem, $customItem]);

        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/some-page');
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
        $middleware->process($request, $this->handler);

        return $captured;
    }

    public function testMembershipMenuItemResolvesToConfiguredPrefix()
    {
        $this->translator = $this->createStub(Translator::class);
        $captured         = $this->menuGlobalsFor([
            ['multi_language_content', '0', '0'],
            ['routing_shop_prefix', '/shop', '/shop'],
            ['routing_membership_prefix', '/membership', '/membership'],
            ['shop_enabled', '1', '1'],
            ['subscriptions_enabled', '1', '1'],
        ]);

        $primary = $captured['menu_primary'] ?? null;
        $this->assertIsArray($primary);
        $this->assertCount(3, $primary);

        $this->assertSame('Shop', $primary[0]->getLabel());
        $this->assertSame('/shop', $primary[0]->getUrl());

        $this->assertSame('Membership Plans', $primary[1]->getLabel());
        $this->assertSame('/membership', $primary[1]->getUrl());
    }

    public function testDisabledFeaturesAreFilteredFromMenuItems()
    {
        $this->translator = $this->createStub(Translator::class);
        $captured         = $this->menuGlobalsFor([
            ['multi_language_content', '0', '0'],
            ['routing_shop_prefix', '/shop', '/shop'],
            ['routing_membership_prefix', '/membership', '/membership'],
            ['shop_enabled', '1', '0'],
            ['subscriptions_enabled', '1', '0'],
        ]);

        $primary = $captured['menu_primary'] ?? null;
        $this->assertIsArray($primary);
        $this->assertCount(1, $primary);
        $this->assertSame('About', $primary[0]->getLabel());
    }
}
