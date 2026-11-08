<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use ValeApp\App\Controllers\Front\CheckoutController;
use ValeApp\App\Controllers\Front\ShopController;
use ValeApp\Application\Seo\SeoState;
use ValeApp\Application\Shop\DeliveryEstimateService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Entity\ProductCategory;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductVoteRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class ShopControllerTest extends TestCase
{
    public function testIndexWithCategoryStringParamDoesNotThrowTypeError(): void
    {
        $twigEnv    = new Environment(new ArrayLoader(['shop/index.twig' => 'Shop']));
        $twig       = $this->createStub(Twig::class);
        $twig->method('getEnvironment')->willReturn($twigEnv);

        $responseFactory = new ResponseFactory();
        $twig->method('render')->willReturn($responseFactory->createResponse());

        $products        = $this->createStub(ProductRepositoryInterface::class);
        $categories      = $this->createMock(ProductCategoryRepositoryInterface::class);
        $votes           = $this->createStub(ProductVoteRepositoryInterface::class);
        $comments        = $this->createStub(CommentRepositoryInterface::class);
        $session         = $this->createStub(SessionManager::class);
        $media           = $this->createStub(MediaRepositoryInterface::class);
        $settings        = $this->createStub(DbalSettingsRepository::class);
        $seoMeta         = $this->createStub(SeoMetaRepositoryInterface::class);
        $countryTaxRates = $this->createStub(CountryTaxRateRepositoryInterface::class);
        $translator      = $this->createStub(Translator::class);
        $seoState        = new SeoState();
        $users           = $this->createStub(UserRepositoryInterface::class);
        $orders          = $this->createStub(OrderRepositoryInterface::class);
        $subscribers     = $this->createStub(SubscriberRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(function (string $key, $default = null) {
            return match ($key) {
                'multi_language_content'           => '1',
                'i18n_display_originals_fallback'  => '1',
                'i18n_fallback_content_notice'     => '1',
                default                            => $default,
            };
        });

        $products->method('findAll')->willReturn([]);
        $products->method('count')->willReturn(0);
        $categories->method('findAll')->willReturn([]);

        $category = new ProductCategory('Electronics', new Slug('electronics'));
        $category->setLanguage('en');

        // Expect findBySlug to be called with a Slug value object, NOT string 'electronics'
        $categories->expects($this->once())
            ->method('findBySlug')
            ->with($this->callback(fn ($arg) => $arg instanceof Slug && (string) $arg === 'electronics'))
            ->willReturn($category);

        $controller = new ShopController(
            $twig,
            $products,
            $categories,
            $votes,
            $comments,
            $session,
            $media,
            $settings,
            $seoMeta,
            $countryTaxRates,
            $translator,
            $seoState,
            $users,
            $orders,
            new DeliveryEstimateService($settings),
            $subscribers
        );

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/shop?category=electronics');
        $response = $controller->index($request, $responseFactory->createResponse());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testIndexPassesVariationPriceRangeForZeroPriceVariableProduct(): void
    {
        $twigEnv    = new Environment(new ArrayLoader(['shop/index.twig' => 'Shop']));
        $twig       = $this->createStub(Twig::class);
        $twig->method('getEnvironment')->willReturn($twigEnv);

        $responseFactory = new ResponseFactory();

        $captured = [];
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data = []) use ($responseFactory, &$captured) {
                $captured = $data;

                return $responseFactory->createResponse();
            }
        );

        $product = new Product('Variable Shirt', new Slug('variable-shirt'));
        $product->setId(7);
        $product->setPrice(0.0);
        $product->setType('variable');
        $product->setStockStatus('in_stock');

        $products    = $this->createStub(ProductRepositoryInterface::class);
        $categories  = $this->createStub(ProductCategoryRepositoryInterface::class);
        $votes       = $this->createStub(ProductVoteRepositoryInterface::class);
        $comments    = $this->createStub(CommentRepositoryInterface::class);
        $session     = $this->createStub(SessionManager::class);
        $media       = $this->createStub(MediaRepositoryInterface::class);
        $settings    = $this->createStub(DbalSettingsRepository::class);
        $seoMeta     = $this->createStub(SeoMetaRepositoryInterface::class);
        $taxRates    = $this->createStub(CountryTaxRateRepositoryInterface::class);
        $translator  = $this->createStub(Translator::class);
        $seoState    = new SeoState();
        $users       = $this->createStub(UserRepositoryInterface::class);
        $orders      = $this->createStub(OrderRepositoryInterface::class);
        $subscribers = $this->createStub(SubscriberRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(function (string $key, $default = null) {
            return match ($key) {
                'multi_language_content'          => '0',
                'routing_shop_prefix'             => '/shop',
                default                           => $default,
            };
        });

        $products->method('findAll')->willReturn([$product]);
        $products->method('count')->willReturn(1);
        $products->method('findVariationsByProductIds')->willReturn([
            7 => [
                ['id' => 1, 'product_id' => 7, 'price' => 25.0, 'sale_price' => null, 'sale_price_start' => null, 'sale_price_end' => null],
                ['id' => 2, 'product_id' => 7, 'price' => 10.0, 'sale_price' => null, 'sale_price_start' => null, 'sale_price_end' => null],
            ],
        ]);
        $votes->method('getSummaries')->willReturn([7 => ['average' => 0.0, 'count' => 0]]);
        $categories->method('findAll')->willReturn([]);

        $controller = new ShopController(
            $twig,
            $products,
            $categories,
            $votes,
            $comments,
            $session,
            $media,
            $settings,
            $seoMeta,
            $taxRates,
            $translator,
            $seoState,
            $users,
            $orders,
            new DeliveryEstimateService($settings),
            $subscribers
        );

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/shop');
        $response = $controller->index($request, $responseFactory->createResponse());

        $this->assertSame(200, $response->getStatusCode());

        $formatted = $captured['formattedProducts'] ?? [];
        $this->assertCount(1, $formatted);
        $this->assertSame(10.0, $formatted[0]['min_price']);
        $this->assertSame(25.0, $formatted[0]['max_price']);
    }

    public function testShowPassesMembershipAccessContext(): void
    {
        $product = new Product('VIP Product', new Slug('vip-product'));
        $product->setId(5);
        $product->setStatus('published');
        $product->setCommentStatus('disabled');
        $product->setAccessTags(['pro-plan']);

        $twigEnv = new Environment(new ArrayLoader(['shop/product.twig' => 'Product']));
        $twig    = $this->createMock(Twig::class);
        $twig->method('getEnvironment')->willReturn($twigEnv);
        $renderedContext = [];
        $responseFactory = new ResponseFactory();
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(function ($response, $template, $context) use ($responseFactory, &$renderedContext) {
                $renderedContext = $context;
                return $responseFactory->createResponse();
            });

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findBySlug')->willReturn($product);
        $products->method('findVariationsByProductId')->willReturn([]);
        $products->method('findGalleryImageIds')->willReturn([]);
        $products->method('findByIds')->willReturn([]);

        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturn(null);

        $media    = $this->createStub(MediaRepositoryInterface::class);
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(function (string $key, $default = null) {
            return in_array($key, ['multi_language_content'], true) ? '0' : $default;
        });

        $categories = $this->createStub(ProductCategoryRepositoryInterface::class);
        $votes      = $this->createStub(ProductVoteRepositoryInterface::class);
        $votes->method('getSummary')->willReturn(['average' => 0.0, 'count' => 0]);
        $comments = $this->createStub(CommentRepositoryInterface::class);
        $seoMeta  = $this->createStub(SeoMetaRepositoryInterface::class);
        $seoMeta->method('findByEntity')->willReturn(null);
        $countryTaxRates = $this->createStub(CountryTaxRateRepositoryInterface::class);
        $translator      = $this->createStub(Translator::class);
        $seoState        = new SeoState();
        $users           = $this->createStub(UserRepositoryInterface::class);
        $orders          = $this->createStub(OrderRepositoryInterface::class);
        $subscribers     = $this->createStub(SubscriberRepositoryInterface::class);

        $deliveryEstimate = new DeliveryEstimateService($settings);

        $controller = new ShopController(
            $twig,
            $products,
            $categories,
            $votes,
            $comments,
            $session,
            $media,
            $settings,
            $seoMeta,
            $countryTaxRates,
            $translator,
            $seoState,
            $users,
            $orders,
            $deliveryEstimate,
            $subscribers
        );

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/shop/vip-product');
        $request  = $request->withAttribute('locale', null);
        $response = $controller->show($request, $responseFactory->createResponse(), ['slug' => 'vip-product']);

        $this->assertSame(['pro-plan'], $renderedContext['access_tags']);
        $this->assertFalse($renderedContext['has_access']);
    }

    public function testAddToCartWithBuyNowRedirectsToCheckout(): void
    {
        $product = new Product('Test Product', new Slug('test-product'));
        $product->setId(10);
        $product->setStatus('published');
        $product->setStockStatus('in_stock');

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $session = $this->createMock(SessionManager::class);
        $session->method('get')->willReturn([]);
        $session->expects($this->once())
            ->method('set')
            ->with('cart', $this->anything());

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(function (string $key, $default = null) {
            return match ($key) {
                'routing_checkout_prefix' => '/checkout',
                'routing_shop_prefix'     => '/shop',
                default                   => $default,
            };
        });

        $twig            = $this->createStub(Twig::class);
        $categories      = $this->createStub(ProductCategoryRepositoryInterface::class);
        $votes           = $this->createStub(ProductVoteRepositoryInterface::class);
        $comments        = $this->createStub(CommentRepositoryInterface::class);
        $media           = $this->createStub(MediaRepositoryInterface::class);
        $seoMeta         = $this->createStub(SeoMetaRepositoryInterface::class);
        $countryTaxRates = $this->createStub(CountryTaxRateRepositoryInterface::class);
        $translator      = $this->createStub(Translator::class);
        $seoState        = new SeoState();
        $users           = $this->createStub(UserRepositoryInterface::class);
        $orders          = $this->createStub(OrderRepositoryInterface::class);
        $subscribers     = $this->createStub(SubscriberRepositoryInterface::class);

        $controller = new ShopController(
            $twig,
            $products,
            $categories,
            $votes,
            $comments,
            $session,
            $media,
            $settings,
            $seoMeta,
            $countryTaxRates,
            $translator,
            $seoState,
            $users,
            $orders,
            new DeliveryEstimateService($settings),
            $subscribers
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/cart/add')
            ->withParsedBody([
                'product_id' => '10',
                'qty'        => '1',
                'buy_now'    => '1',
            ]);

        $responseFactory = new ResponseFactory();
        $response        = $controller->addToCart($request, $responseFactory->createResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Location'));
        $this->assertStringEndsWith('/checkout', $response->getHeaderLine('Location'));
    }

    public function testCheckoutIndexWithGuestCheckoutDisabledRedirectsToLogin(): void
    {
        $session = $this->createMock(SessionManager::class);
        $session->method('get')->willReturnCallback(function (string $key, $default = null) {
            return match ($key) {
                'cart'    => ['10' => ['qty' => 1, 'custom' => [], 'selected' => []]],
                'user_id' => null,
                default   => $default,
            };
        });

        $storedRedirect = null;
        $session->expects($this->once())
            ->method('set')
            ->with('redirect_after_login', $this->callback(function ($url) use (&$storedRedirect) {
                $storedRedirect = $url;
                return is_string($url) && str_ends_with($url, '/checkout');
            }));

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(function (string $key, $default = null) {
            return match ($key) {
                'guest_checkout_enabled'  => '0',
                'routing_checkout_prefix' => '/checkout',
                'routing_shop_prefix'     => '/shop',
                default                   => $default,
            };
        });

        $twig            = $this->createStub(Twig::class);
        $products        = $this->createStub(ProductRepositoryInterface::class);
        $orders          = $this->createStub(OrderRepositoryInterface::class);
        $forms           = $this->createStub(FormRepositoryInterface::class);
        $coupons         = $this->createStub(CouponRepositoryInterface::class);
        $countryTaxRates = $this->createStub(CountryTaxRateRepositoryInterface::class);
        $events          = $this->createStub(EventDispatcherInterface::class);
        $webhookClass    = OutboundWebhookService::class;
        $webhookService  = (new \ReflectionClass($webhookClass))->newInstanceWithoutConstructor();
        $translator      = $this->createStub(Translator::class);

        $controller = new CheckoutController(
            $twig,
            $products,
            $orders,
            $session,
            $settings,
            $forms,
            $coupons,
            $countryTaxRates,
            $events,
            $webhookService,
            [],
            null,
            null,
            null,
            null,
            null,
            null,
            $translator
        );

        $request         = (new ServerRequestFactory())->createServerRequest('GET', '/checkout');
        $responseFactory = new ResponseFactory();
        $response        = $controller->index($request, $responseFactory->createResponse());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Location'));
        $this->assertStringEndsWith('/login', $response->getHeaderLine('Location'));
        $this->assertNotNull($storedRedirect);
        $this->assertStringEndsWith('/checkout', $storedRedirect);
    }
}
