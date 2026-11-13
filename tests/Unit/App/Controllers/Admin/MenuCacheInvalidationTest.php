<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\MenuController;
use ValeApp\Application\Menu\SaveMenuUseCase;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Menu\Entity\Menu;
use ValeApp\Domain\Menu\Repository\MenuRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\PageCache;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Menus render in the header and footer of every page, so a menu write must drop
 * the whole page cache — otherwise guests keep seeing the old navigation until
 * the TTL expires.
 */
final class MenuCacheInvalidationTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/vale-menu-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir . '/*.html') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
    }

    public function testCreatingMenuFlushesPageCache(): void
    {
        [$controller, $cache] = $this->buildController();
        $this->seedCache($cache);

        $controller->store($this->request('POST', '/admin/menus', ['name' => 'Header']), new Response());

        self::assertSame([], $this->cachedFiles(), 'creating a menu must flush the page cache');
    }

    public function testUpdatingMenuFlushesPageCache(): void
    {
        [$controller, $cache] = $this->buildController();
        $this->seedCache($cache);

        $controller->update(
            $this->request('PUT', '/admin/menus/5', ['name' => 'Header']),
            new Response(),
            ['id' => '5'],
        );

        self::assertSame([], $this->cachedFiles(), 'updating a menu must flush the page cache');
    }

    public function testDeletingMenuFlushesPageCache(): void
    {
        [$controller, $cache] = $this->buildController();
        $this->seedCache($cache);

        $controller->destroy($this->request('DELETE', '/admin/menus/5'), new Response(), ['id' => '5']);

        self::assertSame([], $this->cachedFiles(), 'deleting a menu must flush the page cache');
    }

    public function testCloningMenuFlushesPageCache(): void
    {
        [$controller, $cache] = $this->buildController();
        $this->seedCache($cache);

        $controller->cloneMenu($this->request('POST', '/admin/menus/5/clone'), new Response(), ['id' => '5']);

        self::assertSame([], $this->cachedFiles(), 'cloning a menu must flush the page cache');
    }

    /**
     * @return array{0: MenuController, 1: PageCache}
     */
    private function buildController(): array
    {
        $menus = $this->createStub(MenuRepositoryInterface::class);

        $existing = new Menu('Header', new Slug('header'));
        $existing->setId(5);

        $menus->method('findById')->willReturnCallback(
            static fn (int $id): ?Menu => $id === 5 ? $existing : null,
        );
        $menus->method('save')->willReturn(99);
        $menus->method('findItemsByMenuId')->willReturn([]);

        $slugs = $this->createStub(SlugService::class);
        $slugs->method('unique')->willReturn(new Slug('menu-slug'));

        $cache = new PageCache($this->cacheDir, 3600, [], true);

        $controller = new MenuController(
            $this->createStub(Translator::class),
            $this->createStub(Twig::class),
            $menus,
            new SaveMenuUseCase($menus, $slugs),
            $this->createStub(SessionManager::class),
            $this->createStub(PostRepositoryInterface::class),
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(ProductRepositoryInterface::class),
            $this->createStub(ProductCategoryRepositoryInterface::class),
            $slugs,
            $this->createStub(ThemeManager::class),
            $this->createStub(ContentTypeRepositoryInterface::class),
            $this->createStub(DbalSettingsRepository::class),
            $cache,
        );

        return [$controller, $cache];
    }

    private function seedCache(PageCache $cache): void
    {
        foreach (['/', '/blog', '/about'] as $path) {
            $cache->put(site_url($path), '<html>cached</html>');
        }

        self::assertCount(3, $this->cachedFiles());
    }

    private function request(string $method, string $uri, array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri)->withParsedBody($body);
    }

    /**
     * @return string[]
     */
    private function cachedFiles(): array
    {
        return glob($this->cacheDir . '/*.html') ?: [];
    }
}
