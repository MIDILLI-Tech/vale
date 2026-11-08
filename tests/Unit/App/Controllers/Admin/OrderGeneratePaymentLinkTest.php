<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\OrderController;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Payment\StripeGateway;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;
use ValeApp\Tests\Support\OrderControllerCollaborators;

final class OrderGeneratePaymentLinkTest extends TestCase
{
    use OrderControllerCollaborators;

    private function makeOrder(string $paymentMethod): Order
    {
        $order = new Order('ORD-PAYLINK-1', ['email' => 'customer@example.com']);
        $order->setId(9);
        $order->setStatus('pending');
        $order->setPaymentMethod($paymentMethod);
        return $order;
    }

    private function makeController(
        OrderRepositoryInterface $orders,
        SessionManager $session,
        ?StripeGateway $stripe = null,
    ): OrderController {
        $twig = $this->createStub(Twig::class);

        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(static fn (string $key, array $replacements = []) => $key);

        $collaborators = $this->makeOrderCollaborators(
            $orders,
            $this->createStub(OrderLogRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        return new OrderController(
            translator: $translator,
            twig: $twig,
            orders: $orders,
            session: $session,
            products: $this->createStub(ProductRepositoryInterface::class),
            coupons: $this->createStub(CouponRepositoryInterface::class),
            orderLogs: $this->createStub(OrderLogRepositoryInterface::class),
            settings: $this->createStub(DbalSettingsRepository::class),
            countryTaxRates: $this->createStub(CountryTaxRateRepositoryInterface::class),
            events: $this->createStub(EventDispatcherInterface::class),
            webhookService: new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class)),
            orderStatusEffects: $collaborators['effects'],
            changeOrderStatus: $collaborators['changeOrderStatus'],
            updateOrderTracking: $collaborators['updateOrderTracking'],
            orderEstimator: $collaborators['estimator'],
            carrierCatalog: $collaborators['carriers'],
            stripeGateway: $stripe,
            searchCache: new AdminSearchCache($this->createStub(CacheInterface::class)),
        );
    }

    public function testOfflineOrderIsRejectedWithoutGeneratingLink(): void
    {
        $order  = $this->makeOrder('cash');
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);
        $orders->expects($this->never())->method('save');

        $flashes = [];
        $session = $this->createStub(SessionManager::class);
        $session->method('flash')->willReturnCallback(function (string $key, $value) use (&$flashes) {
            $flashes[$key] = $value;
        });

        $controller = $this->makeController($orders, $session, null);
        $request    = (new ServerRequestFactory())->createServerRequest('POST', '/admin/orders/9/payment-link');
        $response   = $controller->generatePaymentLink($request, (new ResponseFactory())->createResponse(), ['id' => '9']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/orders/9', $response->getHeaderLine('Location'));
        $this->assertArrayHasKey('error', $flashes);
        $this->assertArrayNotHasKey('payment_link', $flashes);
        $this->assertNull($order->getPaymentToken());
    }

    public function testOnlineOrderGeneratesTokenAndLink(): void
    {
        $order  = $this->makeOrder('stripe');
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);
        $orders->expects($this->once())->method('save');

        $stripe = new StripeGateway('sk_test_dummy');

        $flashes = [];
        $session = $this->createStub(SessionManager::class);
        $session->method('flash')->willReturnCallback(function (string $key, $value) use (&$flashes) {
            $flashes[$key] = $value;
        });

        $controller = $this->makeController($orders, $session, $stripe);
        $request    = (new ServerRequestFactory())->createServerRequest('POST', '/admin/orders/9/payment-link');
        $response   = $controller->generatePaymentLink($request, (new ResponseFactory())->createResponse(), ['id' => '9']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $order->getPaymentToken());
        $this->assertArrayHasKey('payment_link', $flashes);
        $this->assertStringContainsString('/pay/' . $order->getPaymentToken(), (string) $flashes['payment_link']);
    }
}
