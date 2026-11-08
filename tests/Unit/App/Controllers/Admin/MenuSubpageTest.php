<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\MenuController;
use ValeApp\App\Middleware\LocaleMiddleware;
use ValeApp\Application\Menu\SaveMenuUseCase;
use ValeApp\Domain\Content\Entity\Category;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Menu\Entity\MenuItem;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class MenuSubpageTest extends TestCase
{
    public function testMenuControllerContentItemsBuildsSubpagePath(): void
    {
        $translator   = $this->createStub(Translator::class);
        $twig         = $this->createStub(Twig::class);
        $menus        = $this->createStub(MenuRepositoryInterface::class);
        $slugs        = $this->createStub(SlugService::class);
        $saveMenu     = new SaveMenuUseCase($menus, $slugs);
        $session      = $this->createStub(SessionManager::class);
        $posts        = $this->createStub(PostRepositoryInterface::class);
        $categories   = $this->createStub(CategoryRepositoryInterface::class);
        $products     = $this->createStub(\ValeApp\Domain\Shop\Repository\ProductRepositoryInterface::class);
        $themes       = $this->createStub(ThemeManager::class);
        $contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $settings     = $this->createStub(DbalSettingsRepository::class);

        $subPage = new Post('Sub Page', new Slug('sub-page'), 1);

        $posts->method('findAll')->willReturn([$subPage]);
        $posts->method('buildPagePath')->willReturn('parent-page/sub-page');

        $controller = new MenuController(
            $translator,
            $twig,
            $menus,
            $saveMenu,
            $session,
            $posts,
            $categories,
            $products,
            $slugs,
            $themes,
            $contentTypes,
            $settings
        );

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/menus/content-items?type=page');
        $response = new Response();

        $res = $controller->contentItems($request, $response);
        $res->getBody()->rewind();
        $json = json_decode($res->getBody()->getContents(), true);

        $this->assertCount(1, $json);
        $this->assertStringContainsString('parent-page/sub-page', $json[0]['url']);
    }

    public function testMenuControllerContentItemsSkipsUntitledCategories(): void
    {
        $controller = $this->makeContentItemsController(
            [new Category('', new Slug('untitled')), new Category('Tech', new Slug('tech'))],
        );

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/menus/content-items?type=category');
        $response = new Response();

        $res = $controller->contentItems($request, $response);
        $res->getBody()->rewind();
        $json = json_decode($res->getBody()->getContents(), true);

        $this->assertCount(1, $json);
        $this->assertSame('Tech', $json[0]['label']);
    }

    public function testMenuControllerContentItemsSearchesCategorySlug(): void
    {
        $controller = $this->makeContentItemsController(
            [new Category('News', new Slug('blog-news'))],
        );

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/menus/content-items?type=category&q=blog');
        $response = new Response();

        $res = $controller->contentItems($request, $response);
        $res->getBody()->rewind();
        $json = json_decode($res->getBody()->getContents(), true);

        $this->assertCount(1, $json);
        $this->assertSame('News', $json[0]['label']);
    }

    private function makeContentItemsController(array $allCategories): MenuController
    {
        $translator   = $this->createStub(Translator::class);
        $twig         = $this->createStub(Twig::class);
        $menus        = $this->createStub(MenuRepositoryInterface::class);
        $slugs        = $this->createStub(SlugService::class);
        $saveMenu     = new SaveMenuUseCase($menus, $slugs);
        $session      = $this->createStub(SessionManager::class);
        $posts        = $this->createStub(PostRepositoryInterface::class);
        $categories   = $this->createStub(CategoryRepositoryInterface::class);
        $products     = $this->createStub(\ValeApp\Domain\Shop\Repository\ProductRepositoryInterface::class);
        $themes       = $this->createStub(ThemeManager::class);
        $contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $settings     = $this->createStub(DbalSettingsRepository::class);

        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $default
        );

        $categories->method('findAll')->willReturn($allCategories);

        return new MenuController(
            $translator,
            $twig,
            $menus,
            $saveMenu,
            $session,
            $posts,
            $categories,
            $products,
            $slugs,
            $themes,
            $contentTypes,
            $settings
        );
    }

    public function testLocaleMiddlewareResolvesSubpageUrlsForPageMenuItems(): void
    {
        $translator   = $this->createStub(Translator::class);
        $settings     = $this->createStub(DbalSettingsRepository::class);
        $session      = $this->createStub(SessionManager::class);
        $twig         = $this->createStub(Twig::class);
        $menus        = $this->createStub(MenuRepositoryInterface::class);
        $themeManager = $this->createStub(ThemeManager::class);
        $users        = $this->createStub(UserRepositoryInterface::class);
        $posts        = $this->createStub(PostRepositoryInterface::class);
        $handler      = $this->createStub(RequestHandlerInterface::class);

        $handler->method('handle')->willReturn(new Response());

        $translator->method('getSupportedLocales')->willReturn(['en']);

        $subPage = new Post('Sub Page', new Slug('sub-page'), 1);

        $navMenu = new Menu('Header Menu', new Slug('header'));
        $navMenu->setId(1);

        $menuItem = new MenuItem(1, 'Sub Page');
        $menuItem->setType('page');
        $menuItem->setReferenceId(42);

        $themeManager->method('getDefinedMenus')->willReturn(['header' => 'Header']);
        $menus->method('findAllByLocation')->willReturnCallback(function (string $loc) use ($navMenu) {
            return $loc === 'header' ? [$navMenu] : [];
        });
        $menus->method('findItemsByMenuId')->willReturn([$menuItem]);

        $posts->method('findById')->willReturn($subPage);
        $posts->method('buildPagePath')->willReturn('parent-page/sub-page');

        $env = $this->createStub(\Twig\Environment::class);
        $twig->method('getEnvironment')->willReturn($env);

        $middleware = new LocaleMiddleware(
            $translator,
            $settings,
            $session,
            $twig,
            $menus,
            $themeManager,
            $users,
            $posts
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $middleware->process($request, $handler);

        $this->assertStringContainsString('parent-page/sub-page', $menuItem->getUrl());
    }
}
