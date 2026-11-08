<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Front\CheckoutController;
use ValeApp\Application\Shop\BankTransferDetailsService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\OrderLog;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class CheckoutBankTransferClaimTest extends TestCase
{
    private function makeOrder(string $status = 'pending', string $method = 'banktransfer'): Order
    {
        $order = new Order('ORD-BTCLAIM-1', ['email' => 'customer@example.com']);
        $order->setId(21);
        $order->setStatus($status);
        $order->setPaymentMethod($method);
        $order->setPaymentToken(str_repeat('a', 64));
        return $order;
    }

    private function makeController(
        OrderRepositoryInterface $orders,
        ?OrderLogRepositoryInterface $orderLogs = null,
        ?array &$rendered = null,
    ): CheckoutController {
        $twigEnv = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'errors/generic.twig'                  => 'generic',
            'errors/404.twig'                      => 'not found',
            'shop/bank-transfer-instructions.twig' => 'instructions',
        ]));
        $twig = $this->createStub(Twig::class);
        $twig->method('getEnvironment')->willReturn($twigEnv);
        $twig->method('render')->willReturnCallback(function ($response, string $template, array $data = []) use (&$rendered) {
            $rendered = ['template' => $template, 'data' => $data];
            return $response;
        });

        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(static fn (string $key, array $replacements = []) => $key);

        return new CheckoutController(
            twig: $twig,
            products: $this->createStub(ProductRepositoryInterface::class),
            orders: $orders,
            session: $this->createStub(SessionManager::class),
            settings: $this->createStub(DbalSettingsRepository::class),
            forms: $this->createStub(FormRepositoryInterface::class),
            coupons: $this->createStub(CouponRepositoryInterface::class),
            countryTaxRates: $this->createStub(CountryTaxRateRepositoryInterface::class),
            events: $this->createStub(EventDispatcherInterface::class),
            webhookService: new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class)),
            gateways: [],
            orderLogs: $orderLogs,
            translator: $translator,
            bankTransferDetails: new BankTransferDetailsService(
                $this->makeSettings(['banktransfer_iban' => 'TR0000000000000000'])
            ),
        );
    }

    private function makeSettings(array $values): SettingsRepositoryInterface
    {
        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $values[$key] ?? $default
        );
        return $settings;
    }

    public function testResumePaymentRendersInstructionsForBankTransfer(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn($this->makeOrder());

        $logs       = $this->createStub(OrderLogRepositoryInterface::class);
        $logs->method('findByOrderId')->willReturn([]);

        $rendered   = null;
        $controller = $this->makeController($orders, $logs, $rendered);
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/pay/tok');
        $response   = $controller->resumePayment($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($rendered);
        $this->assertSame('shop/bank-transfer-instructions.twig', $rendered['template']);
        $this->assertSame('TR0000000000000000', $rendered['data']['banktransfer_iban'] ?? null);
        $this->assertFalse($rendered['data']['already_claimed'] ?? true);
    }

    public function testResumePaymentMarksAlreadyClaimedWhenLogExists(): void
    {
        $order  = $this->makeOrder();
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn($order);

        $logs = $this->createStub(OrderLogRepositoryInterface::class);
        $logs->method('findByOrderId')->willReturn([
            new OrderLog(21, 'customer_claimed_payment'),
        ]);

        $rendered   = null;
        $controller = $this->makeController($orders, $logs, $rendered);
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/pay/tok');
        $controller->resumePayment($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertTrue($rendered['data']['already_claimed'] ?? false);
    }

    public function testClaimUnknownTokenReturns404(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn(null);

        $controller = $this->makeController($orders);
        $request    = (new ServerRequestFactory())->createServerRequest('POST', '/pay/tok/claim');
        $response   = $controller->claimBankTransfer($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testClaimNonBankTransferOrderReturns404(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn($this->makeOrder(method: 'stripe'));

        $controller = $this->makeController($orders);
        $request    = (new ServerRequestFactory())->createServerRequest('POST', '/pay/tok/claim');
        $response   = $controller->claimBankTransfer($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPaidOrderClaimRedirectsWithoutLogging(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn($this->makeOrder(status: 'processing'));

        $logged = [];
        $logs   = $this->createStub(OrderLogRepositoryInterface::class);
        $logs->method('add')->willReturnCallback(function (...$args) use (&$logged) {
            $logged[] = $args;
        });

        $controller = $this->makeController($orders, $logs);
        $request    = (new ServerRequestFactory())->createServerRequest('POST', '/pay/tok/claim');
        $response   = $controller->claimBankTransfer($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/pay/tok', $response->getHeaderLine('Location'));
        $this->assertSame([], $logged);
    }

    public function testPendingOrderClaimLogsAndRedirects(): void
    {
        $order  = $this->makeOrder();
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn($order);

        $logged = [];
        $logs   = $this->createStub(OrderLogRepositoryInterface::class);
        $logs->method('findByOrderId')->willReturn([]);
        $logs->method('add')->willReturnCallback(function (...$args) use (&$logged) {
            $logged[] = $args;
        });

        $controller = $this->makeController($orders, $logs);
        $request    = (new ServerRequestFactory())->createServerRequest('POST', '/pay/tok/claim');
        $response   = $controller->claimBankTransfer($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('claimed=1', $response->getHeaderLine('Location'));
        $this->assertCount(1, $logged);
        $this->assertSame($order->getId(), $logged[0][0]);
        $this->assertSame('customer_claimed_payment', $logged[0][1]);
    }

    public function testRecentClaimIsNotLoggedTwice(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn($this->makeOrder());

        $logged = [];
        $logs   = $this->createStub(OrderLogRepositoryInterface::class);
        $logs->method('findByOrderId')->willReturn([
            new OrderLog(21, 'customer_claimed_payment', null, new \DateTimeImmutable('-1 hour')),
        ]);
        $logs->method('add')->willReturnCallback(function (...$args) use (&$logged) {
            $logged[] = $args;
        });

        $controller = $this->makeController($orders, $logs);
        $request    = (new ServerRequestFactory())->createServerRequest('POST', '/pay/tok/claim');
        $response   = $controller->claimBankTransfer($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $logged);
    }
}
