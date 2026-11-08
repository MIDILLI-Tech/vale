<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\PostController;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Application\Seo\SaveSeoMetaUseCase;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class PostListJsonShopLinksTest extends TestCase
{
    /** @param array<string, string> $settings */
    private function makeController(
        array $settings,
        ?ContentTypeRepositoryInterface $contentTypes = null,
        ?PostRepositoryInterface $posts = null,
    ): PostController {
        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(
            static fn (string $text, array $replacements = []): string => $text
        );

        $settingsRepo = $this->createStub(DbalSettingsRepository::class);
        $settingsRepo->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default
        );

        $posts = $posts ?? $this->createStub(PostRepositoryInterface::class);
        $posts->method('count')->willReturn(0);
        $posts->method('findAll')->willReturn([]);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('count')->willReturn(0);
        $products->method('findAll')->willReturn([]);

        // Final collaborators cannot be doubled — instantiate without constructor.
        $withoutConstructor = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new PostController(
            $translator,
            $this->createStub(Twig::class),
            $posts,
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            $withoutConstructor(SavePostUseCase::class),
            $this->createStub(SaveSeoMetaUseCase::class),
            $this->createStub(SeoMetaRepositoryInterface::class),
            $this->createStub(SessionManager::class),
            $products,
            $this->createStub(RevisionRepositoryInterface::class),
            $settingsRepo,
            $contentTypes ?? $this->createStub(ContentTypeRepositoryInterface::class),
            $this->createStub(PlanRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $withoutConstructor(OutboundWebhookService::class),
            $this->createStub(SlugService::class),
            $this->createStub(RedirectService::class),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchItems(PostController $controller, string $uri): array
    {
        $request  = (new ServerRequestFactory())->createServerRequest('GET', $uri);
        $response = new Response();

        $res = $controller->listJson($request, $response);
        $res->getBody()->rewind();

        /** @var array{items: array<int, array<string, mixed>>} $json */
        $json = json_decode($res->getBody()->getContents(), true);

        return $json['items'];
    }

    public function testShopLinksAreIncludedWhenShopIsEnabled(): void
    {
        $items = $this->fetchItems($this->makeController([]), '/admin/posts/list-json');

        $shopItems = array_values(array_filter($items, static fn (array $i): bool => $i['type'] === 'shop'));

        self::assertCount(4, $shopItems);
        self::assertSame('Shop Homepage', $shopItems[0]['title']);
        self::assertSame('Cart', $shopItems[1]['title']);
        self::assertSame('Checkout', $shopItems[2]['title']);
        self::assertSame('Membership Plans', $shopItems[3]['title']);
        self::assertStringContainsString('/shop', $shopItems[0]['url']);
        self::assertStringContainsString('/cart', $shopItems[1]['url']);
        self::assertStringContainsString('/checkout', $shopItems[2]['url']);
    }

    public function testHomepageLinkIsAlwaysIncluded(): void
    {
        foreach ([[], ['shop_enabled' => '0']] as $settings) {
            $items = $this->fetchItems($this->makeController($settings), '/admin/posts/list-json');

            $homeItems = array_values(array_filter($items, static fn (array $i): bool => $i['type'] === 'home'));

            self::assertCount(1, $homeItems);
            self::assertSame('Homepage', $homeItems[0]['title']);
        }
    }

    public function testShopLinksRespectCustomRoutingPrefixes(): void
    {
        $items = $this->fetchItems($this->makeController([
            'routing_shop_prefix'     => '/store',
            'routing_cart_prefix'     => '/basket',
            'routing_checkout_prefix' => '/pay-now',
        ]), '/admin/posts/list-json');

        $shopItems = array_values(array_filter($items, static fn (array $i): bool => $i['type'] === 'shop'));

        self::assertStringContainsString('/store', $shopItems[0]['url']);
        self::assertStringContainsString('/basket', $shopItems[1]['url']);
        self::assertStringContainsString('/pay-now', $shopItems[2]['url']);
    }

    public function testShopLinksAreSkippedWhenShopIsDisabled(): void
    {
        $items = $this->fetchItems(
            $this->makeController(['shop_enabled' => '0']),
            '/admin/posts/list-json'
        );

        $shopItems = array_filter($items, static fn (array $i): bool => $i['type'] === 'shop');

        self::assertCount(0, $shopItems);
    }

    public function testShopLinksAreSkippedForPagePicker(): void
    {
        $items = $this->fetchItems($this->makeController([]), '/admin/posts/list-json?type=page');

        $shopItems = array_filter($items, static fn (array $i): bool => $i['type'] === 'shop');

        self::assertCount(0, $shopItems);
    }

    public function testShopLinksAreFilteredBySearchQuery(): void
    {
        $items = $this->fetchItems($this->makeController([]), '/admin/posts/list-json?q=cart');

        $shopItems = array_values(array_filter($items, static fn (array $i): bool => $i['type'] === 'shop'));

        self::assertCount(1, $shopItems);
        self::assertSame('Cart', $shopItems[0]['title']);
    }

    public function testMembershipLinkIsSkippedWhenSubscriptionsAreDisabled(): void
    {
        $items = $this->fetchItems(
            $this->makeController(['subscriptions_enabled' => '0']),
            '/admin/posts/list-json'
        );

        $titles = array_column(
            array_filter($items, static fn (array $i): bool => $i['type'] === 'shop'),
            'title'
        );

        self::assertNotContains('Membership Plans', $titles);
        self::assertContains('Cart', $titles);
    }

    public function testActiveCustomTypesAreIncludedForLinkPicker(): void
    {
        $cpt = new ContentType('Documentation', new Slug('docs'));

        $contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $contentTypes->method('findActiveCustom')->willReturn([$cpt]);
        $contentTypes->method('findBySlug')->willReturn($cpt);

        $doc = new Post('Getting Started', new Slug('getting-started'), 1);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('count')->willReturnCallback(
            static function (array $filters): int {
                return ($filters['content_type'] ?? '') === 'docs' ? 1 : 0;
            }
        );
        $posts->method('findAll')->willReturnCallback(
            function (Pagination $pagination, array $filters) use ($doc): array {
                return ($filters['content_type'] ?? '') === 'docs' ? [$doc] : [];
            }
        );

        $controller = $this->makeController([], $contentTypes, $posts);
        $items      = $this->fetchItems($controller, '/admin/posts/list-json');

        $docsItems = array_values(array_filter($items, static fn (array $i): bool => $i['type'] === 'docs'));

        self::assertCount(1, $docsItems);
        self::assertSame('Getting Started', $docsItems[0]['title']);
        self::assertSame('/docs/getting-started', $docsItems[0]['url']);
    }
}
