<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use ValeApp\App\Controllers\Api\OrderApiController;
use ValeApp\Application\Shop\ChangeOrderStatusUseCase;
use ValeApp\Application\Shop\DeliveryEstimateService;
use ValeApp\Application\Shop\OrderShippingEstimator;
use ValeApp\Application\Shop\OrderStatusEffects;
use ValeApp\Application\Shop\OrderTrackingNotifier;
use ValeApp\Application\Shop\UpdateOrderTrackingUseCase;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\OrderLog;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Shop\ShippingCarrierCatalog;

final class OrderApiControllerTest extends TestCase
{
    private $orderRepo;
    private $orderLogRepo;
    private array $events      = [];
    private array $savedOrders = [];
    private OrderApiController $controller;

    protected function setUp(): void
    {
        $this->orderRepo    = $this->createStub(OrderRepositoryInterface::class);
        $this->orderLogRepo = $this->createStub(OrderLogRepositoryInterface::class);
        $this->events       = [];
        $this->savedOrders  = [];

        $this->controller = $this->makeController();
    }

    private function makeController(): OrderApiController
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturnCallback(
            fn (int $id) => $id === 7 ? $this->makeProduct() : null
        );
        $products->method('findBySku')->willReturnCallback(
            fn (string $sku) => $sku === 'LUMI-PRO' ? $this->makeProduct() : null
        );
        $products->method('findVariationsByProductId')->willReturnCallback(
            fn (int $productId) => $productId === 7 ? $this->variationRows() : []
        );

        $this->orderRepo->method('save')->willReturnCallback(function (Order $order) {
            if ($order->getId() === null) {
                $order->setId(99);
            }
            $this->savedOrders[] = $order;
            return $order;
        });
        $this->orderRepo->method('generateOrderNumber')->willReturn('VL-2001');
        $this->orderRepo->method('count')->willReturn(1);

        $events = $this->createStub(EventDispatcherInterface::class);
        $events->method('doAction')->willReturnCallback(function (string $event, ...$args) {
            $this->events[] = $event;
        });

        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => match ($key) {
                'currency' => 'EUR',
                default    => $default,
            }
        );

        $effects = new OrderStatusEffects(
            $this->orderRepo,
            $this->createStub(CouponRepositoryInterface::class),
            $this->createStub(SubscriberRepositoryInterface::class),
            $this->createStub(DownloadRepositoryInterface::class),
            null,
        );

        $changeOrderStatus = new ChangeOrderStatusUseCase(
            $this->orderRepo,
            $this->orderLogRepo,
            $events,
            $effects,
        );

        $estimator = new OrderShippingEstimator(
            $products,
            new DeliveryEstimateService($settings),
        );
        $notifier = new OrderTrackingNotifier(
            $this->orderLogRepo,
            new ShippingCarrierCatalog($settings),
            $estimator,
            null,
            null,
        );
        $updateTracking = new UpdateOrderTrackingUseCase(
            $this->orderRepo,
            $this->orderLogRepo,
            $events,
            $notifier,
        );

        return new OrderApiController(
            orders: $this->orderRepo,
            orderLogs: $this->orderLogRepo,
            products: $products,
            coupons: $this->createStub(CouponRepositoryInterface::class),
            events: $events,
            effects: $effects,
            changeOrderStatus: $changeOrderStatus,
            updateOrderTracking: $updateTracking,
            settings: $this->makeSettings(),
            searchCache: null,
        );
    }

    private function makeSettings(): DbalSettingsRepository
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => match ($key) {
                'currency' => 'EUR',
                default    => $default,
            }
        );
        return $settings;
    }

    private function makeProduct(): Product
    {
        return Product::fromRow([
            'id'    => 7,
            'name'  => 'LumiFind Pro',
            'slug'  => 'lumifind-pro',
            'sku'   => 'LUMI-PRO',
            'price' => '49.00',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function variationRows(): array
    {
        return [
            [
                'id'         => 11,
                'sku'        => 'LUMI-PRO-RED',
                'price'      => '59.00',
                'sale_price' => null,
                'attributes' => json_encode(['Color' => 'Red']),
            ],
        ];
    }

    public function testShowReturnsOrderSummary(): void
    {
        $order    = $this->makeOrder();
        $lookedUp = [];
        $this->orderRepo->method('findById')->willReturnCallback(
            static function (int $id) use ($order, &$lookedUp): ?Order {
                $lookedUp[] = $id;
                return $id === 42 ? $order : null;
            }
        );

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/orders/42');
        $response = $this->controller->show($request, (new ResponseFactory())->createResponse(), ['id' => '42']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([42], $lookedUp);
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(42, $body['data']['id']);
        self::assertSame('VL-1001', $body['data']['order_number']);
        self::assertSame('processing', $body['data']['status']);
        self::assertSame('buyer@example.com', $body['data']['customer_email']);
        self::assertSame('LUMI-PRO', $body['data']['items'][0]['sku']);
        self::assertSame(1, $body['data']['items'][0]['quantity']);
    }

    public function testShowReturns404IfNotFound(): void
    {
        $this->orderRepo->method('findById')->willReturn(null);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/orders/999');
        $response = $this->controller->show($request, (new ResponseFactory())->createResponse(), ['id' => '999']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testIndexReturnsPaginatedMeta(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());
        $this->orderRepo->method('findAll')->willReturnCallback(
            fn ($pagination, array $filters = []) => $filters === [] ? [$this->makeOrder()] : []
        );

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/orders?page=2&per_page=50');
        $response = $this->controller->index($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(42, $body['data'][0]['id']);
        self::assertSame(2, $body['meta']['page']);
        self::assertSame(50, $body['meta']['per_page']);
        self::assertSame(1, $body['meta']['total']);
        self::assertSame(1, $body['meta']['total_pages']);
    }

    public function testIndexPassesStatusAndUpdatedSinceFilters(): void
    {
        $captured = null;
        $this->orderRepo->method('findById')->willReturn(null);
        $this->orderRepo->method('findAll')->willReturnCallback(
            function ($pagination, array $filters = []) use (&$captured): array {
                $captured = $filters;
                return [];
            }
        );

        $request = (new RequestFactory())->createRequest(
            'GET',
            '/api/v1/orders?status=processing&updated_since=2026-10-01'
        );
        $this->controller->index($request, (new ResponseFactory())->createResponse());

        self::assertSame('processing', $captured['status']);
        self::assertSame('2026-10-01', $captured['updated_since']);
    }

    public function testNotesReturnsOnlyNoteTypeEntries(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());
        $this->orderLogRepo->method('findByOrderId')->willReturn([
            $this->makeLog(1, 'status_changed', 'Status changed.', 'system'),
            $this->makeLog(2, 'admin_note', 'License key sent.', 'note'),
        ]);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/orders/42/notes');
        $response = $this->controller->notes($request, (new ResponseFactory())->createResponse(), ['id' => '42']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(1, $body['data']);
        self::assertSame(2, $body['data'][0]['id']);
        self::assertSame('License key sent.', $body['data'][0]['note']);
        self::assertSame('API', $body['data'][0]['added_by']);
    }

    public function testNotesReturns404IfOrderMissing(): void
    {
        $this->orderRepo->method('findById')->willReturn(null);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/orders/404/notes');
        $response = $this->controller->notes($request, (new ResponseFactory())->createResponse(), ['id' => '404']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testStoreNoteReturns404IfOrderMissing(): void
    {
        $this->orderRepo    = $this->createStub(OrderRepositoryInterface::class);
        $this->orderRepo->method('findById')->willReturn(null);
        $this->orderLogRepo = $this->createMock(OrderLogRepositoryInterface::class);
        $this->orderLogRepo->expects(self::never())->method('add');

        $controller = $this->makeController();

        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders/999/notes')
            ->withParsedBody(['note' => 'key sent: LUMI-ABC']);
        $response = $controller->storeNote($request, (new ResponseFactory())->createResponse(), ['id' => '999']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testStoreNoteRejectsEmptyNote(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());
        $this->orderLogRepo = $this->createMock(OrderLogRepositoryInterface::class);
        $this->orderLogRepo->expects(self::never())->method('add');

        $controller = $this->makeController();

        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders/5/notes')
            ->withParsedBody(['note' => '   ']);
        $response = $controller->storeNote($request, (new ResponseFactory())->createResponse(), ['id' => '5']);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('A note is required.', $body['error']);
    }

    public function testStoreNoteRejectsTooLongNote(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());
        $this->orderLogRepo = $this->createMock(OrderLogRepositoryInterface::class);
        $this->orderLogRepo->expects(self::never())->method('add');

        $controller = $this->makeController();

        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders/5/notes')
            ->withParsedBody(['note' => str_repeat('x', 5001)]);
        $response = $controller->storeNote($request, (new ResponseFactory())->createResponse(), ['id' => '5']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testStoreNoteWritesOrderLog(): void
    {
        $order                = $this->makeOrder();
        $captured             = [];
        $this->orderRepo->method('findById')->willReturn($order);
        $this->orderLogRepo = $this->createMock(OrderLogRepositoryInterface::class);
        $this->orderLogRepo->expects(self::once())->method('add')
            ->willReturnCallback(function (
                int $orderId,
                string $event,
                ?string $note,
                string $type,
                ?int $userId,
                ?string $addedBy
            ) use (&$captured): void {
                $captured = [$orderId, $event, $note, $type, $userId, $addedBy];
            });

        $controller = $this->makeController();

        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders/42/notes')
            ->withParsedBody(['note' => 'Sent License Key: LUMI-ABC-123']);
        $response = $controller->storeNote($request, (new ResponseFactory())->createResponse(), ['id' => '42']);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame([42, 'admin_note', 'Sent License Key: LUMI-ABC-123', 'note', null, 'API'], $captured);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(42, $body['data']['order_id']);
        self::assertSame('API', $body['data']['added_by']);
    }

    public function testStoreCreatesManualOrderWithServerComputedTotal(): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders')
            ->withParsedBody([
                'billing'        => ['first_name' => 'Ada', 'email' => 'Ada@Example.com', 'country' => 'TR'],
                'items'          => [['product_id' => 7, 'qty' => 2]],
                'shipping'       => 10.0,
                'status'         => 'pending',
                'payment_method' => 'banktransfer',
            ]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $this->savedOrders);

        $order = $this->savedOrders[0];
        self::assertSame('VL-2001', $order->getOrderNumber());
        self::assertSame(98.0, $order->getSubtotal());
        self::assertSame(10.0, $order->getShipping());
        self::assertSame(108.0, $order->getTotal());
        self::assertSame('banktransfer', $order->getPaymentMethod());
        self::assertSame('EUR', $order->getCurrency());
        self::assertSame('Ada@Example.com', $order->getBilling()['email']);
        self::assertSame('LumiFind Pro', $order->getItems()[0]['name']);
        self::assertSame('LUMI-PRO', $order->getItems()[0]['sku']);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Order created', $body['message']);
        self::assertSame('VL-2001', $body['data']['order_number']);
    }

    public function testStoreResolvesVariationPriceAndAttributes(): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders')
            ->withParsedBody([
                'billing' => ['first_name' => 'Ada', 'email' => 'ada@example.com'],
                'items'   => [[
                    'product_id'   => 7,
                    'variation_id' => 11,
                    'variation'    => ['Color' => 'Red'],
                    'qty'          => 1,
                ]],
            ]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
        $item = $this->savedOrders[0]->getItems()[0];
        self::assertSame(59.0, $item['price']);
        self::assertSame('LUMI-PRO-RED', $item['sku']);
        self::assertSame(11, $item['variation_id']);
        self::assertSame(['Color' => 'Red'], json_decode((string) $item['variation_data'], true));
    }

    public function testStoreCreatesInitialNoteAndFiresOrderPaidForPaidStatus(): void
    {
        $added = [];
        $this->orderRepo->method('findById')->willReturn(null);
        $this->orderLogRepo->method('add')->willReturnCallback(
            function (
                int $orderId,
                string $event,
                ?string $note,
                string $type,
                ?int $userId,
                ?string $addedBy
            ) use (&$added): void {
                $added[] = [$event, $note, $type, $addedBy];
            }
        );

        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders')
            ->withParsedBody([
                'billing' => ['first_name' => 'Ada', 'email' => 'ada@example.com'],
                'items'   => [['name' => 'Custom engraving', 'price' => 25.0]],
                'status'  => 'completed',
                'note'    => 'Engraving approved by phone.',
            ]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['order_created', 'Manual order created via REST API. Initial status: "Completed".', 'system', 'API'],
            $added[0]
        );
        self::assertSame(['admin_note', 'Engraving approved by phone.', 'note', 'API'], $added[1]);
        self::assertContains('order.paid', $this->events);
        self::assertNotNull($this->savedOrders[0]->getPaidAt());
        self::assertSame(
            'Engraving approved by phone.',
            json_decode((string) $response->getBody(), true)['data']['note']
        );
    }

    public function testStoreRejectsMissingBillingFields(): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders')
            ->withParsedBody([
                'items' => [['product_id' => 7, 'qty' => 1]],
            ]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Validation failed', $body['error']);
        self::assertArrayHasKey('billing.first_name', $body['errors']);
        self::assertArrayHasKey('billing.email', $body['errors']);
    }

    public function testStoreRejectsGatewayPaymentMethod(): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders')
            ->withParsedBody([
                'billing'        => ['first_name' => 'Ada', 'email' => 'ada@example.com'],
                'items'          => [['product_id' => 7, 'qty' => 1]],
                'payment_method' => 'stripe',
            ]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('payment_method', json_decode((string) $response->getBody(), true)['errors']);
    }

    public function testStoreRejectsUnknownVariation(): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/orders')
            ->withParsedBody([
                'billing' => ['first_name' => 'Ada', 'email' => 'ada@example.com'],
                'items'   => [['product_id' => 7, 'variation_id' => 999, 'qty' => 1]],
            ]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey(
            'items.0.variation_id',
            json_decode((string) $response->getBody(), true)['errors']
        );
    }

    public function testUpdateChangesStatusThroughSharedUseCase(): void
    {
        $order = $this->makeOrder();
        $order->setStatus('pending');
        $this->orderRepo->method('findById')->willReturn($order);

        $request = (new RequestFactory())->createRequest('PUT', '/api/v1/orders/42')
            ->withParsedBody(['status' => 'processing']);
        $response = $this->controller->update($request, (new ResponseFactory())->createResponse(), ['id' => '42']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('processing', $order->getStatus());
        self::assertNotNull($order->getPaidAt());
        self::assertContains('order.status_changed', $this->events);
        self::assertContains('order.paid', $this->events);
    }

    public function testUpdateRejectsProtectedAmountFields(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());

        $request = (new RequestFactory())->createRequest('PUT', '/api/v1/orders/42')
            ->withParsedBody(['status' => 'completed', 'total' => 1.0, 'items' => []]);
        $response = $this->controller->update($request, (new ResponseFactory())->createResponse(), ['id' => '42']);

        self::assertSame(422, $response->getStatusCode());
        $errors = json_decode((string) $response->getBody(), true)['errors'];
        self::assertArrayHasKey('total', $errors);
        self::assertArrayHasKey('items', $errors);
        self::assertSame([], $this->events);
        self::assertSame([], $this->savedOrders);
    }

    public function testUpdateRejectsInvalidStatus(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());

        $request = (new RequestFactory())->createRequest('PUT', '/api/v1/orders/42')
            ->withParsedBody(['status' => 'nope']);
        $response = $this->controller->update($request, (new ResponseFactory())->createResponse(), ['id' => '42']);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('status', json_decode((string) $response->getBody(), true)['errors']);
    }

    public function testUpdateWritesTrackingAndCustomerNote(): void
    {
        $order = $this->makeOrder();
        $added = [];
        $this->orderRepo->method('findById')->willReturn($order);
        $this->orderLogRepo->method('add')->willReturnCallback(
            function (
                int $orderId,
                string $event,
                ?string $note,
                string $type,
                ?int $userId,
                ?string $addedBy
            ) use (&$added): void {
                $added[] = [$event, $note, $type, $addedBy];
            }
        );

        $request = (new RequestFactory())->createRequest('PUT', '/api/v1/orders/42')
            ->withParsedBody([
                'carrier'         => 'dhl',
                'tracking_number' => 'JD014600003812345678',
                'customer_note'   => 'Leave at the door.',
                'note'            => 'Shipped via DHL Express.',
            ]);
        $response = $this->controller->update($request, (new ResponseFactory())->createResponse(), ['id' => '42']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('dhl', $order->getCarrier());
        self::assertSame('JD014600003812345678', $order->getTrackingNumber());
        self::assertSame('Leave at the door.', $order->getCustomerNote());
        self::assertSame(
            ['tracking_updated', 'Tracking updated — Carrier: dhl, Tracking #: JD014600003812345678', 'system', 'API'],
            $added[0]
        );
        self::assertSame(['admin_note', 'Shipped via DHL Express.', 'note', 'API'], $added[1]);
        self::assertContains('order.shipped', $this->events);
    }

    public function testUpdateReturns404IfOrderMissing(): void
    {
        $this->orderRepo->method('findById')->willReturn(null);

        $request = (new RequestFactory())->createRequest('PUT', '/api/v1/orders/404')
            ->withParsedBody(['status' => 'processing']);
        $response = $this->controller->update($request, (new ResponseFactory())->createResponse(), ['id' => '404']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testDestroyNoteDeletesNote(): void
    {
        $deleted = [];
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());
        $this->orderLogRepo->method('findByOrderId')->willReturn([
            $this->makeLog(1, 'status_changed', 'Status changed.', 'system'),
            $this->makeLog(2, 'admin_note', 'License key sent.', 'note'),
        ]);
        $this->orderLogRepo->method('delete')->willReturnCallback(
            function (int $id) use (&$deleted): void {
                $deleted[] = $id;
            }
        );

        $request  = (new RequestFactory())->createRequest('DELETE', '/api/v1/orders/42/notes/2');
        $response = $this->controller->destroyNote(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '42', 'noteId' => '2']
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([2], $deleted);
    }

    public function testDestroyNoteRejectsNonNoteLog(): void
    {
        $this->orderRepo->method('findById')->willReturn($this->makeOrder());
        $this->orderLogRepo->method('findByOrderId')->willReturn([
            $this->makeLog(1, 'status_changed', 'Status changed.', 'system'),
        ]);

        $request  = (new RequestFactory())->createRequest('DELETE', '/api/v1/orders/42/notes/1');
        $response = $this->controller->destroyNote(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => '42', 'noteId' => '1']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function makeLog(int $id, string $event, string $note, string $type): OrderLog
    {
        return OrderLog::fromRow([
            'id'         => $id,
            'order_id'   => 42,
            'event'      => $event,
            'note'       => $note,
            'created_at' => '2026-10-01 10:00:00',
            'type'       => $type,
            'added_by'   => 'API',
        ]);
    }

    private function makeOrder(): Order
    {
        $order = Order::fromRow([
            'id'             => 42,
            'order_number'   => 'VL-1001',
            'status'         => 'processing',
            'locale'         => 'en',
            'currency'       => 'EUR',
            'subtotal'       => '49.00',
            'tax'            => '0.00',
            'shipping'       => '0.00',
            'discount'       => '0.00',
            'total'          => '49.00',
            'billing'        => json_encode(['first_name' => 'Test', 'email' => 'Buyer@Example.com']),
            'paid_at'        => '2026-10-01 10:00:00',
            'created_at'     => '2026-10-01 09:59:00',
            'payment_method' => 'stripe',
        ]);

        $order->setItems([
            [
                'product_id'   => 7,
                'variation_id' => null,
                'name'         => 'LumiFind Pro',
                'sku'          => 'LUMI-PRO',
                'quantity'     => 1,
                'price'        => '49.00',
            ],
        ]);

        return $order;
    }
}
