<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Application\Shop\ChangeOrderStatusUseCase;
use ValeApp\Application\Shop\OrderStatusEffects;
use ValeApp\Application\Shop\UpdateOrderTrackingUseCase;
use ValeApp\Application\Shop\VariationPrice;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\OrderStatus;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class OrderApiController
{
    private const MAX_NOTE_LENGTH = 5000;

    private const API_ACTOR = 'API';

    /** Payment methods an API-created manual order may use (offline methods only). */
    private const OFFLINE_PAYMENT_METHODS = ['cash', 'manual', 'banktransfer'];

    /** Fields a PUT /orders/{id} payload may never touch. */
    private const PROTECTED_FIELDS = [
        'subtotal', 'shipping', 'discount', 'total', 'tax', 'items',
        'billing', 'shipping_address', 'currency', 'payment_method', 'user_id',
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderLogRepositoryInterface $orderLogs,
        private readonly ProductRepositoryInterface $products,
        private readonly CouponRepositoryInterface $coupons,
        private readonly EventDispatcherInterface $events,
        private readonly OrderStatusEffects $effects,
        private readonly ChangeOrderStatusUseCase $changeOrderStatus,
        private readonly UpdateOrderTrackingUseCase $updateOrderTracking,
        private readonly DbalSettingsRepository $settings,
        private readonly ?AdminSearchCache $searchCache = null,
    ) {
    }

    /** GET /api/v1/orders */
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 30)));

        $filters = [];
        foreach (['status', 'search', 'from', 'to', 'coupon_code', 'updated_since'] as $key) {
            if (!empty($params[$key])) {
                $filters[$key] = (string) $params[$key];
            }
        }
        if (!empty($params['product_id'])) {
            $filters['product_id'] = (int) $params['product_id'];
        }

        $items = $this->orders->findAll(new Pagination($page, $perPage), $filters);
        $total = $this->orders->count($filters);

        return $this->json($response, [
            'data' => array_map(fn (Order $o) => $this->serializeOrder($o), $items),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => max(1, (int) ceil($total / max(1, $perPage))),
            ],
        ]);
    }

    /** GET /api/v1/orders/{id} */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $order = $this->orders->findById((int) ($args['id'] ?? 0));
        if ($order === null) {
            return $this->error($response, 404, 'Order not found');
        }

        return $this->json($response, ['data' => $this->serializeOrder($order)]);
    }

    /** GET /api/v1/orders/{id}/notes */
    public function notes(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $order = $this->orders->findById((int) ($args['id'] ?? 0));
        if ($order === null) {
            return $this->error($response, 404, 'Order not found');
        }

        $notes = array_values(array_filter(
            $this->orderLogs->findByOrderId($order->getId()),
            static fn ($log) => $log->getType() === 'note'
        ));

        return $this->json($response, [
            'data' => array_map(static fn ($log) => [
                'id'         => $log->getId(),
                'note'       => $log->getNote(),
                'added_by'   => $log->getAddedBy(),
                'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $notes),
            'meta' => ['total' => count($notes)],
        ]);
    }

    /** POST /api/v1/orders/{id}/notes */
    public function storeNote(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $order = $this->orders->findById((int) ($args['id'] ?? 0));
        if ($order === null) {
            return $this->error($response, 404, 'Order not found');
        }

        $data = (array) $request->getParsedBody();
        $note = trim((string) ($data['note'] ?? ''));

        if ($note === '') {
            return $this->error($response, 422, 'A note is required.');
        }
        if (mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            return $this->error(
                $response,
                422,
                'Note exceeds the maximum length of ' . self::MAX_NOTE_LENGTH . ' characters.'
            );
        }

        $this->orderLogs->add(
            $order->getId(),
            'admin_note',
            $note,
            'note',
            null,
            self::API_ACTOR,
        );

        return $this->json($response->withStatus(201), [
            'data' => [
                'order_id' => $order->getId(),
                'note'     => $note,
                'added_by' => self::API_ACTOR,
            ],
        ]);
    }

    /** DELETE /api/v1/orders/{id}/notes/{noteId} */
    public function destroyNote(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $order = $this->orders->findById((int) ($args['id'] ?? 0));
        if ($order === null) {
            return $this->error($response, 404, 'Order not found');
        }

        $noteId = (int) ($args['noteId'] ?? 0);
        foreach ($this->orderLogs->findByOrderId($order->getId()) as $log) {
            if ($log->getId() === $noteId && $log->getType() === 'note') {
                $this->orderLogs->delete($noteId);
                return $response->withStatus(204);
            }
        }

        return $this->error($response, 404, 'Note not found');
    }

    /**
     * POST /api/v1/orders — manual/offline order creation (admin parity).
     *
     * Prices for linked products/variations are resolved server-side from the
     * current catalog; the total is always computed server-side
     * (subtotal + shipping − discount) and never trusted from the payload.
     */
    public function store(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data = (array) $request->getParsedBody();

        $errors = [];

        // ── Billing ──
        $billingInput = (array) ($data['billing'] ?? []);
        $billing      = [
            'first_name' => trim((string) ($billingInput['first_name'] ?? '')),
            'last_name'  => trim((string) ($billingInput['last_name'] ?? '')),
            'email'      => trim((string) ($billingInput['email'] ?? '')),
            'phone'      => trim((string) ($billingInput['phone'] ?? '')),
            'address_1'  => trim((string) ($billingInput['address_1'] ?? '')),
            'address_2'  => trim((string) ($billingInput['address_2'] ?? '')),
            'city'       => trim((string) ($billingInput['city'] ?? '')),
            'state'      => trim((string) ($billingInput['state'] ?? '')),
            'postcode'   => trim((string) ($billingInput['postcode'] ?? '')),
            'country'    => strtoupper(trim((string) ($billingInput['country'] ?? ''))),
        ];

        if ($billing['first_name'] === '') {
            $errors['billing.first_name'] = ['Billing first name is required.'];
        }
        if ($billing['email'] === '' || !filter_var($billing['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['billing.email'] = ['A valid billing email address is required.'];
        }

        // ── Line items ──
        $rawItems = $data['items'] ?? [];
        if (!is_array($rawItems) || $rawItems === []) {
            $errors['items'] = ['At least one line item is required.'];
        }

        $items    = [];
        $subtotal = 0.0;

        if (is_array($rawItems)) {
            foreach (array_values($rawItems) as $index => $row) {
                if (!is_array($row)) {
                    $errors["items.$index"] = ['Line item must be an object.'];
                    continue;
                }

                $qty           = max(1, (int) ($row['qty'] ?? 1));
                $priceRaw      = $row['price'] ?? null;
                $price         = ($priceRaw !== null && $priceRaw !== '') ? round((float) $priceRaw, 2) : 0.0;
                $name          = trim((string) ($row['name'] ?? ''));
                $pid           = null;
                $vid           = null;
                $sku           = trim((string) ($row['sku'] ?? '')) ?: null;
                $variationData = null;

                if (empty($row['product_id']) && !empty($row['sku'])) {
                    $bySku = $this->products->findBySku(trim((string) $row['sku']));
                    if ($bySku !== null) {
                        $pid = $bySku->getId();
                    }
                }
                if (!empty($row['product_id'])) {
                    $pid = (int) $row['product_id'];
                }

                if ($pid !== null) {
                    $product = $this->products->findById($pid);
                    if ($product === null) {
                        $errors["items.$index.product_id"] = ["Product #{$pid} not found."];
                        continue;
                    }
                    if ($name === '') {
                        $name = $product->getName();
                    }

                    if (!empty($row['variation_id'])) {
                        $vid          = (int) $row['variation_id'];
                        $variationRow = null;
                        foreach ($this->products->findVariationsByProductId($pid) as $candidate) {
                            if ((int) $candidate['id'] === $vid) {
                                $variationRow = $candidate;
                                break;
                            }
                        }
                        if ($variationRow === null) {
                            $errors["items.$index.variation_id"] = ["Variation #{$vid} not found for product #{$pid}."];
                            continue;
                        }

                        $sku = trim((string) ($variationRow['sku'] ?? '')) !== ''
                            ? trim((string) $variationRow['sku'])
                            : $product->getSku();
                        if ($price <= 0.0) {
                            $price = VariationPrice::resolve($variationRow);
                        }
                        $attrs = is_string($variationRow['attributes'] ?? null)
                            ? json_decode((string) $variationRow['attributes'], true)
                            : ($variationRow['attributes'] ?? null);
                        if (is_array($row['variation'] ?? null) && is_array($attrs)) {
                            foreach ($row['variation'] as $tKey => $tVal) {
                                $tKey          = trim((string) $tKey);
                                $tVal          = trim((string) $tVal);
                                if ($tKey !== '' && $tVal !== '') {
                                    $attrs[$tKey] = $tVal;
                                }
                            }
                        }
                        if (is_array($attrs) && $attrs !== []) {
                            $variationData = json_encode($attrs);
                        }
                    } else {
                        if ($price <= 0.0) {
                            $price = $product->getPrice();
                        }
                        $sku = $product->getSku();
                    }
                } else {
                    if ($name === '') {
                        $errors["items.$index.name"] = ['A name is required for items without a product reference.'];
                        continue;
                    }
                    if ($price <= 0.0) {
                        $errors["items.$index.price"] = ['A price is required for items without a product reference.'];
                        continue;
                    }
                }

                if ($price < 0) {
                    $errors["items.$index.price"] = ['Line item price cannot be negative.'];
                    continue;
                }

                $lineSubtotal = round($price * $qty, 2);
                $subtotal    += $lineSubtotal;
                $items[]      = [
                    'product_id'     => $pid,
                    'variation_id'   => $vid,
                    'name'           => $name,
                    'sku'            => $sku,
                    'qty'            => $qty,
                    'price'          => $price,
                    'subtotal'       => $lineSubtotal,
                    'variation_data' => $variationData,
                ];
            }
        }

        if ($items === []) {
            $errors['items'] = $errors['items'] ?? ['At least one valid line item is required.'];
        }

        // ── Payment method (offline methods only) ──
        $paymentMethod = trim((string) ($data['payment_method'] ?? ''));
        if ($paymentMethod !== '' && !in_array($paymentMethod, self::OFFLINE_PAYMENT_METHODS, true)) {
            $errors['payment_method'] = [
                'Payment method must be one of: ' . implode(', ', self::OFFLINE_PAYMENT_METHODS) . '.',
            ];
        }

        // ── Status ──
        $status = (string) ($data['status'] ?? 'pending');
        if (!OrderStatus::isValid($status)) {
            $errors['status'] = [
                'Invalid order status. Valid statuses: ' . implode(', ', OrderStatus::all()) . '.',
            ];
        }

        // ── Optional note ──
        $note = trim((string) ($data['note'] ?? ''));
        if ($note !== '' && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            $errors['note'] = ['Note exceeds the maximum length of ' . self::MAX_NOTE_LENGTH . ' characters.'];
        }

        if ($errors !== []) {
            return $this->validationError($response, $errors);
        }

        // ── Shipping, discount, coupon ──
        $shipping       = max(0.0, round((float) ($data['shipping'] ?? 0), 2));
        $manualDiscount = max(0.0, round((float) ($data['discount'] ?? 0), 2));
        $couponCode     = strtoupper(trim((string) ($data['coupon_code'] ?? '')));
        $couponDiscount = 0.0;
        $resolvedCode   = null;

        if ($couponCode !== '') {
            $coupon = $this->coupons->findByCode($couponCode);
            if ($coupon !== null && $coupon->isValid($subtotal)) {
                $billingEmail = strtolower(trim($billing['email']));
                $allowed      = $coupon->getAllowedEmails();
                $emailOk      = $allowed === [] || ($billingEmail !== '' && in_array($billingEmail, $allowed, true));
                if ($emailOk) {
                    $couponDiscount = $coupon->calculateDiscount($subtotal);
                    $resolvedCode   = $coupon->getCode();
                    $this->coupons->incrementUses($coupon->getId());
                }
            }
        }

        $totalDiscount = $manualDiscount + $couponDiscount;

        // ── Shipping address ──
        $shippingInput      = (array) ($data['shipping_address'] ?? []);
        $shippingAddress    = [
            'first_name' => trim((string) ($shippingInput['first_name'] ?? '')),
            'last_name'  => trim((string) ($shippingInput['last_name'] ?? '')),
            'company'    => trim((string) ($shippingInput['company'] ?? '')),
            'address_1'  => trim((string) ($shippingInput['address_1'] ?? '')),
            'address_2'  => trim((string) ($shippingInput['address_2'] ?? '')),
            'city'       => trim((string) ($shippingInput['city'] ?? '')),
            'state'      => trim((string) ($shippingInput['state'] ?? '')),
            'postcode'   => trim((string) ($shippingInput['postcode'] ?? '')),
            'country'    => strtoupper(trim((string) ($shippingInput['country'] ?? ''))),
            'phone'      => trim((string) ($shippingInput['phone'] ?? '')),
        ];

        $order = new Order($this->orders->generateOrderNumber(), $billing);
        if (!empty(array_filter($shippingAddress))) {
            $order->setShippingAddress($shippingAddress);
        }
        $order->setItems($items);
        $order->setSubtotal(round($subtotal, 2));
        $order->setShipping($shipping);
        $order->setDiscount(round($totalDiscount, 2));
        $order->setCouponCode($resolvedCode);
        $order->setStatus($status);
        $currency     = strtoupper(trim((string) ($data['currency'] ?? $this->settings->get('currency', 'USD'))));
        $order->setCurrency($currency !== '' ? $currency : 'USD');
        $order->setPaymentMethod($paymentMethod !== '' ? $paymentMethod : null);
        $customerNote = trim((string) ($data['customer_note'] ?? ''));
        $order->setCustomerNote($customerNote !== '' ? $customerNote : null);
        if (!empty($data['transaction_id'])) {
            $order->setTransactionId(trim((string) $data['transaction_id']));
        }
        if ($status === OrderStatus::COMPLETED) {
            $order->setPaidAt(new \DateTimeImmutable());
        }
        $order->calculateTotal();
        $this->orders->save($order);
        $this->searchCache?->bump('order_search');

        if (OrderStatus::isPaid($status)) {
            if ($order->getPaidAt() === null) {
                $order->setPaidAt(new \DateTimeImmutable());
                $this->orders->save($order);
            }
            $this->events->doAction('order.paid', $order);
            $this->effects->tagSubscriberPaid($order);
        }

        $this->orderLogs->add(
            $order->getId(),
            'order_created',
            'Manual order created via REST API. Initial status: "' . OrderStatus::label($status) . '".',
            'system',
            null,
            self::API_ACTOR,
        );

        if ($note !== '') {
            $this->orderLogs->add($order->getId(), 'admin_note', $note, 'note', null, self::API_ACTOR);
        }

        // Re-hydrate so the response matches GET /orders/{id} exactly
        // (created_at, DB-normalized item rows including quantity).
        $saved = $this->orders->findById($order->getId()) ?? $order;

        $payload = $this->serializeOrder($saved);
        if ($note !== '') {
            $payload['note'] = $note;
        }

        return $this->json($response->withStatus(201), [
            'message' => 'Order created',
            'data'    => $payload,
        ]);
    }

    /** PUT /api/v1/orders/{id} — status, carrier/tracking, customer note, inline note. */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $order = $this->orders->findById((int) ($args['id'] ?? 0));
        if ($order === null) {
            return $this->error($response, 404, 'Order not found');
        }

        $data = (array) $request->getParsedBody();

        $errors = [];
        foreach (self::PROTECTED_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $errors[$field] = ["The '{$field}' field cannot be modified via the API."];
            }
        }

        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];
            if (!OrderStatus::isValid($status)) {
                $errors['status'] = [
                    'Invalid order status. Valid statuses: ' . implode(', ', OrderStatus::all()) . '.',
                ];
            }
        }

        $hasTrackingInput = isset($data['tracking_number']) || isset($data['carrier']);
        if ($hasTrackingInput && isset($data['tracking_number']) && trim((string) $data['tracking_number']) === '') {
            $errors['tracking_number'] = ['A tracking number is required.'];
        }

        $note = isset($data['note']) && $data['note'] !== null ? trim((string) $data['note']) : '';
        if ($note !== '' && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            $errors['note'] = ['Note exceeds the maximum length of ' . self::MAX_NOTE_LENGTH . ' characters.'];
        }

        if ($errors !== []) {
            return $this->validationError($response, $errors);
        }

        try {
            $changed = false;

            if (array_key_exists('status', $data)) {
                $changed = $this->changeOrderStatus->execute($order, (string) $data['status']) || $changed;
            }

            if ($hasTrackingInput) {
                $carrier         = trim((string) ($data['carrier'] ?? $order->getCarrier() ?? ''));
                $trackingNumber  = trim((string) ($data['tracking_number'] ?? $order->getTrackingNumber() ?? ''));
                $this->updateOrderTracking->execute(
                    $order,
                    $carrier,
                    $trackingNumber,
                    !empty($data['notify_customer']),
                    self::API_ACTOR,
                );
                $changed = true;
            }

            if (array_key_exists('customer_note', $data)) {
                $customerNote = $data['customer_note'] !== null ? trim((string) $data['customer_note']) : '';
                $order->setCustomerNote($customerNote !== '' ? $customerNote : null);
                $this->orders->save($order);
                $changed = true;
            }

            if ($note !== '') {
                $this->orderLogs->add($order->getId(), 'admin_note', $note, 'note', null, self::API_ACTOR);
                $changed = true;
            }
        } catch (ValidationException $e) {
            return $this->json($response->withStatus(422), [
                'error'  => 'Validation failed',
                'errors' => $e->getErrors(),
            ]);
        }

        if ($changed) {
            $this->searchCache?->bump('order_search');
        }

        return $this->json($response, [
            'message' => 'Order updated',
            'data'    => $this->serializeOrder($order),
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeOrder(Order $order): array
    {
        $billing = $order->getBilling();
        $email   = isset($billing['email']) && is_string($billing['email'])
            ? strtolower(trim($billing['email']))
            : null;

        return [
            'id'             => $order->getId(),
            'order_number'   => $order->getOrderNumber(),
            'status'         => $order->getStatus(),
            'currency'       => $order->getCurrency(),
            'subtotal'       => $order->getSubtotal(),
            'total'          => $order->getTotal(),
            'payment_method' => $order->getPaymentMethod(),
            'paid_at'        => $order->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'created_at'     => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'customer_email' => $email !== '' ? $email : null,
            'items'          => array_map(
                static fn (array $item): array => [
                    'product_id'   => isset($item['product_id']) ? (int) $item['product_id'] : null,
                    'variation_id' => isset($item['variation_id']) ? (int) $item['variation_id'] : null,
                    'name'         => (string) ($item['name'] ?? ''),
                    'sku'          => $item['sku'] ?? null,
                    'quantity'     => (int) ($item['quantity'] ?? $item['qty'] ?? 1),
                ],
                $order->getItems()
            ),
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $data */
    private function error(
        ResponseInterface $response,
        int $status,
        string $message
    ): ResponseInterface {
        return $this->json($response->withStatus($status), ['error' => $message]);
    }

    /**
     * @param array<string, string[]> $errors
     */
    private function validationError(ResponseInterface $response, array $errors): ResponseInterface
    {
        return $this->json($response->withStatus(422), [
            'error'  => 'Validation failed',
            'errors' => $errors,
        ]);
    }
}
