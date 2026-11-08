<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\OrderController;
use ValeApp\Application\Shop\BankTransferDetailsService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
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

final class OrderGenerateBankTransferLinkTest extends TestCase
{
    use OrderControllerCollaborators;

    private function makeOrder(string $paymentMethod): Order
    {
        $order = new Order('ORD-BTLINK-1', ['email' => 'customer@example.com']);
        $order->setId(11);
        $order->setStatus('pending');
        $order->setPaymentMethod($paymentMethod);
        return $order;
    }

    private function makeController(
        OrderRepositoryInterface $orders,
        SessionManager $session,
        ?OrderLogRepositoryInterface $orderLogs = null,
    ): OrderController {
        $twig = $this->createStub(Twig::class);

        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(static fn (string $key, array $replacements = []) => $key);

        $collaborators = $this->makeOrderCollaborators(
            $orders,
            $orderLogs ?? $this->createStub(OrderLogRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        return new OrderController(
            translator: $translator,
            twig: $twig,
            orders: $orders,
            session: $session,
            products: $this->createStub(ProductRepositoryInterface::class),
            coupons: $this->createStub(CouponRepositoryInterface::class),
            orderLogs: $orderLogs ?? $this->createStub(OrderLogRepositoryInterface::class),
            settings: $this->createStub(DbalSettingsRepository::class),
            countryTaxRates: $this->createStub(CountryTaxRateRepositoryInterface::class),
            events: $this->createStub(EventDispatcherInterface::class),
            webhookService: new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class)),
            orderStatusEffects: $collaborators['effects'],
            changeOrderStatus: $collaborators['changeOrderStatus'],
            updateOrderTracking: $collaborators['updateOrderTracking'],
            orderEstimator: $collaborators['estimator'],
            carrierCatalog: $collaborators['carriers'],
            bankTransferDetails: new BankTransferDetailsService($this->createStub(SettingsRepositoryInterface::class)),
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

    #[\PHPUnit\Framework\Attributes\DataProvider('bankTransferMethodProvider')]
    public function testBankTransferOrderGeneratesInstructionLink(string $method): void
    {
        $order  = $this->makeOrder($method);
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);
        $orders->expects($this->once())->method('save');

        $logged = [];
        $logs   = $this->createStub(OrderLogRepositoryInterface::class);
        $logs->method('add')->willReturnCallback(function (...$args) use (&$logged) {
            $logged[] = $args;
        });

        $flashes    = [];
        $controller = $this->makeController($orders, $this->makeSession($flashes), $logs);
        $request    = (new ServerRequestFactory())->createServerRequest('POST', '/admin/orders/11/payment-link');
        $response   = $controller->generatePaymentLink($request, (new ResponseFactory())->createResponse(), ['id' => '11']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $order->getPaymentToken());
        $this->assertArrayHasKey('payment_link', $flashes);
        $this->assertStringContainsString('/pay/' . $order->getPaymentToken(), (string) $flashes['payment_link']);
        $this->assertSame('Bank transfer instruction link generated.', $flashes['success'] ?? null);
        $this->assertCount(1, $logged);
        $this->assertSame('payment_link_generated', $logged[0][1]);
        $this->assertStringContainsString('Bank transfer instruction link generated by admin:', (string) $logged[0][2]);
    }

    public static function bankTransferMethodProvider(): array
    {
        return [
            'checkout slug'     => ['banktransfer'],
            'legacy admin slug' => ['bank_transfer'],
        ];
    }
}
