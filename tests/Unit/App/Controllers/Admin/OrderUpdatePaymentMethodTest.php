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
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;
use ValeApp\Tests\Support\OrderControllerCollaborators;

final class OrderUpdatePaymentMethodTest extends TestCase
{
    use OrderControllerCollaborators;

    private function makeOrder(string $paymentMethod): Order
    {
        $order = new Order('ORD-PAYMETHOD-1', ['email' => 'customer@example.com']);
        $order->setId(9);
        $order->setStatus('pending');
        $order->setPaymentMethod($paymentMethod);
        return $order;
    }

    private function makeController(
        OrderRepositoryInterface $orders,
        SessionManager $session,
        OrderLogRepositoryInterface $orderLogs,
        bool $stripeEnabled = true,
    ): OrderController {
        $twig = $this->createStub(Twig::class);

        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(static fn (string $key, array $replacements = []) => $key);

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => match ($key) {
                'stripe_enabled' => $stripeEnabled ? '1' : '0',
                default          => $default,
            }
        );

        $collaborators = $this->makeOrderCollaborators(
            $orders,
            $orderLogs,
            $this->createStub(EventDispatcherInterface::class),
        );

        return new OrderController(
            translator: $translator,
            twig: $twig,
            orders: $orders,
            session: $session,
            products: $this->createStub(ProductRepositoryInterface::class),
            coupons: $this->createStub(CouponRepositoryInterface::class),
            orderLogs: $orderLogs,
            settings: $settings,
            countryTaxRates: $this->createStub(CountryTaxRateRepositoryInterface::class),
            events: $this->createStub(EventDispatcherInterface::class),
            webhookService: new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class)),
            orderStatusEffects: $collaborators['effects'],
            changeOrderStatus: $collaborators['changeOrderStatus'],
            updateOrderTracking: $collaborators['updateOrderTracking'],
            orderEstimator: $collaborators['estimator'],
            carrierCatalog: $collaborators['carriers'],
            searchCache: new AdminSearchCache($this->createStub(CacheInterface::class)),
        );
    }

    private function makeSession(array &$flashes): SessionManager
    {
        $session = $this->createStub(SessionManager::class);
        $session->method('flash')->willReturnCallback(function (string $key, $value) use (&$flashes) {
            $flashes[$key] = $value;
        });
        return $session;
    }

    public function testInvalidMethodIsRejectedWithoutSaving(): void
    {
        $order  = $this->makeOrder('cash');
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);
        $orders->expects($this->never())->method('save');

        $flashes = [];
        $logs    = [];

        $controller = $this->makeController(
            $orders,
            $this->makeSession($flashes),
            $this->makeLogs($logs)
        );
        $request  = (new ServerRequestFactory())->createServerRequest('POST', '/admin/orders/9/payment-method');
        $request  = $request->withParsedBody(['payment_method' => 'bogus']);
        $response = $controller->updatePaymentMethod($request, (new ResponseFactory())->createResponse(), ['id' => '9']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/orders/9', $response->getHeaderLine('Location'));
        $this->assertSame('cash', $order->getPaymentMethod());
        $this->assertArrayHasKey('error', $flashes);
        $this->assertArrayNotHasKey('success', $flashes);
        $this->assertSame([], $logs);
    }

    public function testCashToStripeSavesAndLogs(): void
    {
        $order  = $this->makeOrder('cash');
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);
        $orders->expects($this->once())->method('save');

        $flashes = [];
        $logs    = [];

        $controller = $this->makeController(
            $orders,
            $this->makeSession($flashes),
            $this->makeLogs($logs)
        );
        $request  = (new ServerRequestFactory())->createServerRequest('POST', '/admin/orders/9/payment-method');
        $request  = $request->withParsedBody(['payment_method' => 'stripe']);
        $response = $controller->updatePaymentMethod($request, (new ResponseFactory())->createResponse(), ['id' => '9']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('stripe', $order->getPaymentMethod());
        $this->assertSame('Payment method updated.', $flashes['success'] ?? null);
        $this->assertCount(1, $logs);
        $this->assertSame('payment_method_changed', $logs[0][1]);
        $this->assertStringContainsString('"Cash" to "Stripe"', $logs[0][2]);
    }

    public function testSameMethodIsNoOp(): void
    {
        $order  = $this->makeOrder('stripe');
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);
        $orders->expects($this->never())->method('save');

        $flashes = [];
        $logs    = [];

        $controller = $this->makeController(
            $orders,
            $this->makeSession($flashes),
            $this->makeLogs($logs)
        );
        $request  = (new ServerRequestFactory())->createServerRequest('POST', '/admin/orders/9/payment-method');
        $request  = $request->withParsedBody(['payment_method' => 'stripe']);
        $response = $controller->updatePaymentMethod($request, (new ResponseFactory())->createResponse(), ['id' => '9']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $flashes);
        $this->assertSame([], $logs);
    }

    private function makeLogs(array &$captured): OrderLogRepositoryInterface
    {
        $logs = $this->createStub(OrderLogRepositoryInterface::class);
        $logs->method('add')->willReturnCallback(function (...$args) use (&$captured) {
            $captured[] = $args;
        });
        return $logs;
    }
}
