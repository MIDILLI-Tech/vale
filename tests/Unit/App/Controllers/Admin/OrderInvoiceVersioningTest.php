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
use ValeApp\Domain\Shop\Entity\OrderLog;
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

final class OrderInvoiceVersioningTest extends TestCase
{
    use OrderControllerCollaborators;

    private function makeOrder(): Order
    {
        $order = new Order('ORD-2026-1003', [
            'first_name' => 'Michael',
            'last_name'  => 'Smith',
            'email'      => 'm.smith@example.com',
            'city'       => 'Düsseldorf',
            'country'    => 'DE',
        ]);
        $order->setId(3);
        $order->setStatus('processing');
        $order->setCurrency('EUR');
        $order->setSubtotal(45.0);
        $order->setShipping(5.0);
        $order->setTax(7.98);
        $order->setDiscount(0.0);
        $order->setTotal(50.0);
        return $order;
    }

    private function makeLog(int $id, string $event, array $snapshot): OrderLog
    {
        return OrderLog::fromRow([
            'id'         => $id,
            'order_id'   => 3,
            'event'      => $event,
            'note'       => json_encode($snapshot),
            'created_at' => '2026-09-26 11:20:19',
            'type'       => 'system',
            'user_id'    => 1,
            'added_by'   => 'Admin',
        ]);
    }

    private function baseSnapshot(): array
    {
        return [
            'invoice_number' => 'INV-ORD-2026-1003',
            'issued_at'      => '2026-09-26 11:20:19',
            'currency'       => 'EUR',
            'subtotal'       => 45.0,
            'shipping'       => 5.0,
            'tax'            => 7.98,
            'discount'       => 0.0,
            'total'          => 50.0,
        ];
    }

    /**
     * @return array{controller:OrderController,added:\ArrayObject<int,array{event:string,note:string}>,flashes:\ArrayObject<string,mixed>,rendered:\ArrayObject<string,mixed>,orderLogs:OrderLogRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeController(Order $order, array $logs, bool $expectAdd = false): array
    {
        $orders = $this->createStub(OrderRepositoryInterface::class);
        $orders->method('findById')->willReturn($order);

        $added = new \ArrayObject();
        if ($expectAdd) {
            $orderLogs = $this->createMock(OrderLogRepositoryInterface::class);
            $orderLogs->expects($this->once())->method('add')->willReturnCallback(
                function (int $orderId, string $event, ?string $note) use ($added): void {
                    $added[] = ['event' => $event, 'note' => (string) $note];
                }
            );
        } else {
            $orderLogs = $this->createStub(OrderLogRepositoryInterface::class);
        }
        $orderLogs->method('findByOrderId')->willReturn($logs);

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                'tax_rate'         => '19',
                'tax_default_rate' => '19',
                'tax_regime'       => 'normal_vat',
                default            => $default,
            }
        );

        $countryTaxRates = $this->createStub(CountryTaxRateRepositoryInterface::class);
        $countryTaxRates->method('findByCountry')->willReturn(null);
        $countryTaxRates->method('findFallback')->willReturn(null);

        $flashes = new \ArrayObject();
        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => match ($key) {
                'user_id'   => 1,
                'user_name' => 'Admin',
                default     => $default,
            }
        );
        $session->method('flash')->willReturnCallback(
            function (string $key, mixed $value = null) use (&$flashes): void {
                $flashes[$key] = $value;
            }
        );

        $rendered = new \ArrayObject();
        $twig     = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data = []) use ($rendered) {
                foreach ($data as $key => $value) {
                    $rendered[$key] = $value;
                }
                return $response;
            }
        );

        $translator = $this->createStub(Translator::class);
        $translator->method('get')->willReturnCallback(static fn (string $key): string => $key);

        $collaborators = $this->makeOrderCollaborators(
            $orders,
            $orderLogs,
            $this->createStub(EventDispatcherInterface::class),
        );

        $controller = new OrderController(
            translator: $translator,
            twig: $twig,
            orders: $orders,
            session: $session,
            products: $this->createStub(ProductRepositoryInterface::class),
            coupons: $this->createStub(CouponRepositoryInterface::class),
            orderLogs: $orderLogs,
            settings: $settings,
            countryTaxRates: $countryTaxRates,
            events: $this->createStub(EventDispatcherInterface::class),
            webhookService: new OutboundWebhookService($this->createStub(WebhookRepositoryInterface::class)),
            orderStatusEffects: $collaborators['effects'],
            changeOrderStatus: $collaborators['changeOrderStatus'],
            updateOrderTracking: $collaborators['updateOrderTracking'],
            orderEstimator: $collaborators['estimator'],
            carrierCatalog: $collaborators['carriers'],
            searchCache: new AdminSearchCache($this->createStub(CacheInterface::class)),
        );

        return compact('controller', 'added', 'flashes', 'rendered', 'orderLogs');
    }

    public function testFirstIssueStoresRevisionOneWithoutSuffix(): void
    {
        $order = $this->makeOrder();
        $ctx   = $this->makeController($order, [], true);

        $request  = (new ServerRequestFactory())->createServerRequest('POST', '/admin/orders/3/invoice/issue');
        $response = $ctx['controller']->issueInvoice($request, (new ResponseFactory())->createResponse(), ['id' => '3']);

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, $ctx['added']);
        self::assertSame('invoice_issued', $ctx['added'][0]['event']);
        $snapshot = json_decode($ctx['added'][0]['note'], true);
        self::assertSame(1, $snapshot['revision']);
        self::assertSame('INV-ORD-2026-1003', $snapshot['invoice_number']);
        self::assertStringContainsString('INV-ORD-2026-1003', (string) ($ctx['flashes']['success'] ?? ''));
    }

    public function testReissueStoresRevisionTwoWithSuffix(): void
    {
        $order = $this->makeOrder();
        $ctx   = $this->makeController($order, [$this->makeLog(11, 'invoice_issued', $this->baseSnapshot())], true);

        $request  = (new ServerRequestFactory())->createServerRequest('POST', '/admin/orders/3/invoice/issue');
        $response = $ctx['controller']->issueInvoice($request, (new ResponseFactory())->createResponse(), ['id' => '3']);

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, $ctx['added']);
        self::assertSame('invoice_reissued', $ctx['added'][0]['event']);
        $snapshot = json_decode($ctx['added'][0]['note'], true);
        self::assertSame(2, $snapshot['revision']);
        self::assertSame('INV-ORD-2026-1003-R2', $snapshot['invoice_number']);
    }

    public function testViewInvoiceDefaultsToLatest(): void
    {
        $order = $this->makeOrder();
        $ctx   = $this->makeController($order, [
            $this->makeLog(11, 'invoice_issued', $this->baseSnapshot()),
            $this->makeLog(12, 'invoice_reissued', $this->baseSnapshot()),
        ]);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/orders/3/invoice');
        $response = $ctx['controller']->viewInvoice($request, (new ResponseFactory())->createResponse(), ['id' => '3']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('INV-ORD-2026-1003-R2', $ctx['rendered']['invoice']['invoice_number']);
        self::assertTrue($ctx['rendered']['is_latest']);
        self::assertNull($ctx['rendered']['latest_invoice']);
        self::assertSame('INV-ORD-2026-1003', $ctx['rendered']['prev_number']);
    }

    public function testViewInvoiceResolvesOldVersionByLogId(): void
    {
        $order = $this->makeOrder();
        $ctx   = $this->makeController($order, [
            $this->makeLog(11, 'invoice_issued', $this->baseSnapshot()),
            $this->makeLog(12, 'invoice_reissued', $this->baseSnapshot()),
        ]);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/orders/3/invoice')
            ->withQueryParams(['log' => 11]);
        $response = $ctx['controller']->viewInvoice($request, (new ResponseFactory())->createResponse(), ['id' => '3']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('INV-ORD-2026-1003', $ctx['rendered']['invoice']['invoice_number']);
        self::assertFalse($ctx['rendered']['is_latest']);
        self::assertSame('INV-ORD-2026-1003-R2', $ctx['rendered']['latest_invoice']['invoice_number']);
        self::assertNull($ctx['rendered']['prev_number']);
    }

    public function testViewInvoiceUnknownLogFallsBackToLatest(): void
    {
        $order = $this->makeOrder();
        $ctx   = $this->makeController($order, [
            $this->makeLog(11, 'invoice_issued', $this->baseSnapshot()),
        ]);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/admin/orders/3/invoice')
            ->withQueryParams(['log' => 999]);
        $response = $ctx['controller']->viewInvoice($request, (new ResponseFactory())->createResponse(), ['id' => '3']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('INV-ORD-2026-1003', $ctx['rendered']['invoice']['invoice_number']);
        self::assertTrue($ctx['rendered']['is_latest']);
    }
}
