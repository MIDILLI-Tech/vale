<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Front\CheckoutController;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Port\PaymentGatewayInterface;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class CheckoutResumePaymentTest extends TestCase
{
    private function makeOrder(string $status, ?string $paymentMethod): Order
    {
        $order = new Order('ORD-RESUME-1', ['email' => 'customer@example.com']);
        $order->setStatus($status);
        if ($paymentMethod !== null) {
            $order->setPaymentMethod($paymentMethod);
        }
        return $order;
    }

    private function makeController(OrderRepositoryInterface $orders, array $gateways = [], ?array &$rendered = null): CheckoutController
    {
        $twigEnv = new \Twig\Environment(new \Twig\Loader\ArrayLoader([
            'errors/generic.twig' => 'generic',
            'errors/404.twig'     => 'not found',
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
            gateways: $gateways,
            translator: $translator,
        );
    }

    public function testUnknownTokenReturns404(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn(null);

        $controller = $this->makeController($orders);
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/pay/nope');
        $response   = $controller->resumePayment($request, (new ResponseFactory())->createResponse(), ['token' => 'nope']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPaidOrderRedirectsToSuccess(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn($this->makeOrder('processing', 'stripe'));

        $controller = $this->makeController($orders);
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/pay/tok');
        $response   = $controller->resumePayment($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/success', $response->getHeaderLine('Location'));
    }

    public function testCancelledOrderReturns410(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn($this->makeOrder('cancelled', 'stripe'));

        $controller = $this->makeController($orders);
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/pay/tok');
        $response   = $controller->resumePayment($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertSame(410, $response->getStatusCode());
    }

    public function testPendingCashOrderWithoutGatewayRendersInfoPage(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'errlog');
        $oldLog  = ini_get('error_log');
        ini_set('error_log', $tmpFile);

        try {
            $orders = $this->createStub(OrderRepositoryInterface::class);
            $orders->method('findByPaymentToken')->willReturn($this->makeOrder('pending', 'cash'));

            $rendered   = null;
            $controller = $this->makeController($orders, [], $rendered);
            $request    = (new ServerRequestFactory())->createServerRequest('GET', '/pay/tok');
            $response   = $controller->resumePayment($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

            $this->assertSame(503, $response->getStatusCode());
            $this->assertNotNull($rendered);
            $this->assertSame('errors/generic.twig', $rendered['template']);
            $this->assertSame(503, $rendered['data']['error_code'] ?? null);
            $this->assertSame('Online payment unavailable', $rendered['data']['title'] ?? null);
            $this->assertSame('This order cannot be paid online. Please contact us to complete your purchase.', $rendered['data']['message'] ?? null);
        } finally {
            ini_set('error_log', $oldLog);
            @unlink($tmpFile);
        }
    }

    public function testMatchingGatewayRedirectsToProvider(): void
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findByPaymentToken')->willReturn($this->makeOrder('pending', 'cash'));

        $gateway = $this->createStub(PaymentGatewayInterface::class);
        $gateway->method('getName')->willReturn('cash');
        $gateway->method('createPayment')->willReturn(['redirect_url' => 'https://gateway.test/pay']);

        $controller = $this->makeController($orders, [$gateway]);
        $request    = (new ServerRequestFactory())->createServerRequest('GET', '/pay/tok');
        $response   = $controller->resumePayment($request, (new ResponseFactory())->createResponse(), ['token' => 'tok']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://gateway.test/pay', $response->getHeaderLine('Location'));
    }
}
