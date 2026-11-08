<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Membership\SubscriptionService;
use ValeApp\Application\Shop\BankTransferDetailsService;
use ValeApp\Application\Shop\ChangeOrderStatusUseCase;
use ValeApp\Application\Shop\OrderShippingEstimator;
use ValeApp\Application\Shop\OrderStatusEffects;
use ValeApp\Application\Shop\UpdateOrderTrackingUseCase;
use ValeApp\Application\Shop\VariationPrice;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\OrderLog;
use ValeApp\Domain\Shop\OrderStatus;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Payment\CheckoutComGateway;
use ValeApp\Infrastructure\Payment\MollieGateway;
use ValeApp\Infrastructure\Payment\PayPalGateway;
use ValeApp\Infrastructure\Payment\StripeGateway;
use ValeApp\Infrastructure\Payment\TwoCheckoutGateway;
use ValeApp\Infrastructure\Pdf\PdfGeneratorInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Shop\ShippingCarrierCatalog;
use ValeApp\Infrastructure\Translation\Translator;

final class OrderController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly OrderRepositoryInterface $orders,
        private readonly SessionManager $session,
        private readonly ProductRepositoryInterface $products,
        private readonly CouponRepositoryInterface $coupons,
        private readonly OrderLogRepositoryInterface $orderLogs,
        private readonly DbalSettingsRepository $settings,
        private readonly CountryTaxRateRepositoryInterface $countryTaxRates,
        private readonly EventDispatcherInterface $events,
        private readonly OutboundWebhookService $webhookService,
        private readonly OrderStatusEffects $orderStatusEffects,
        private readonly ChangeOrderStatusUseCase $changeOrderStatus,
        private readonly UpdateOrderTrackingUseCase $updateOrderTracking,
        private readonly OrderShippingEstimator $orderEstimator,
        private readonly ShippingCarrierCatalog $carrierCatalog,
        private readonly ?MailService $mail = null,
        private readonly ?DownloadRepositoryInterface $downloadRepo = null,
        private readonly ?PdfGeneratorInterface $pdfGenerator = null,
        private readonly ?PayPalGateway $paypalGateway = null,
        private readonly ?StripeGateway $stripeGateway = null,
        private readonly ?MollieGateway $mollieGateway = null,
        private readonly ?TwoCheckoutGateway $twoCheckoutGateway = null,
        private readonly ?CheckoutComGateway $checkoutComGateway = null,
        private readonly ?SubscriptionService $subscriptionService = null,
        private readonly ?BankTransferDetailsService $bankTransferDetails = null,
        private readonly ?AdminSearchCache $searchCache = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $filters = [];
        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }
        if (!empty($params['from'])) {
            $filters['from'] = $params['from'];
        }
        if (!empty($params['to'])) {
            $filters['to'] = $params['to'];
        }
        if (!empty($params['coupon_code'])) {
            $filters['coupon_code'] = strtoupper(trim($params['coupon_code']));
        }
        if (!empty($params['product_id'])) {
            $filters['product_id'] = (int) $params['product_id'];
        }

        $baseCount  = $this->orders->count($filters);
        $clientSide = $baseCount <= client_side_search_fetch_limit() && empty($params['search']);

        if ($clientSide) {
            $pagination = new Pagination(1, 100000);
            $orders     = $this->orders->findAll($pagination, $filters);
            $total      = !empty($filters['search']) ? $this->orders->count($filters) : $baseCount;
        } else {
            if (!empty($params['search'])) {
                $filters['search'] = $params['search'];
            }
            $page       = max(1, (int) ($params['page'] ?? 1));
            $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 20)));
            $pagination = new Pagination($page, $perPage, $params['sort'] ?? null, $params['dir'] ?? 'desc');

            $orderKey = $this->orderSearchKeyParts($filters, $pagination);
            $cached   = $orderKey !== null
                ? $this->searchCache->getObjects('order_search', $orderKey, [Order::class])
                : null;
            $orders = $cached !== null ? $cached['items'] : null;

            if ($orders === null) {
                $total  = $this->orders->count($filters);
                $orders = $this->orders->findAll($pagination, $filters);
                if ($orderKey !== null) {
                    $this->searchCache->setObjects('order_search', $orderKey, $total, $orders, 120);
                }
            } else {
                $total = $cached['total'];
            }
        }

        $carry             = array_diff_key($params, array_flip(['page', 'sort', 'dir', 'search', 'per_page']));
        $filterProductName = null;
        if (!empty($filters['product_id'])) {
            $product = $this->products->findById($filters['product_id']);
            if ($product !== null) {
                $filterProductName = $product->getName();
            }
        }
        $baseUrl = admin_url('/orders') . ($carry ? '?' . http_build_query($carry) : '');

        $carryWithoutProduct = $carry;
        unset($carryWithoutProduct['product_id']);
        $removeProductUrl = admin_url('/orders') . ($carryWithoutProduct ? '?' . http_build_query($carryWithoutProduct) : '');

        $carryWithoutCoupon = $carry;
        unset($carryWithoutCoupon['coupon_code']);
        $removeCouponUrl = admin_url('/orders') . ($carryWithoutCoupon ? '?' . http_build_query($carryWithoutCoupon) : '');

        return $this->twig->render($response, 'admin/orders/index.twig', [
            'orders'             => $orders,
            'total'              => $total,
            'filters'            => $params,
            'client_side'        => $clientSide,
            'page'               => $clientSide ? 1 : $pagination->page,
            'per_page'           => $clientSide ? 20 : $pagination->perPage,
            'base_url'           => $baseUrl,
            'filterProductName'  => $filterProductName,
            'removeProductUrl'   => $removeProductUrl,
            'removeCouponUrl'    => $removeCouponUrl,
        ]);
    }

    public function reports(Request $request, Response $response): Response
    {
        $now  = new \DateTimeImmutable();
        $from = $request->getQueryParams()['from'] ?? $now->modify('-30 days')->format('Y-m-d');
        $to   = $request->getQueryParams()['to']   ?? $now->format('Y-m-d');

        $data = $this->orders->getReportData($from, $to);

        return $this->twig->render($response, 'admin/orders/reports.twig', [
            'from'   => $from,
            'to'     => $to,
            'data'   => $data,
        ]);
    }

    public function reportsPdf(Request $request, Response $response): Response
    {
        $now  = new \DateTimeImmutable();
        $from = $request->getQueryParams()['from'] ?? $now->modify('-30 days')->format('Y-m-d');
        $to   = $request->getQueryParams()['to']   ?? $now->format('Y-m-d');

        $data = $this->orders->getReportData($from, $to);

        $html = $this->twig->fetch('admin/orders/report-pdf.twig', [
            'from' => $from,
            'to'   => $to,
            'data' => $data,
            'shop' => $this->getShopData(),
        ]);

        if ($this->pdfGenerator === null || !$this->pdfGenerator->isAvailable()) {
            $this->session->set('flash_error', 'PDF generation is not available.');
            return $response->withHeader('Location', admin_url('/orders/reports?from=' . $from . '&to=' . $to))->withStatus(302);
        }

        $pdf = $this->pdfGenerator->generate($html);

        $this->session->set('flash_success', 'Report exported.');
        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="report-' . $from . '-to-' . $to . '.pdf"');
    }

    private function getShopData(): array
    {
        return [
            'company_name' => (string) ($this->settings->get('shop_company_name', '') ?? ''),
            'address_1'    => (string) ($this->settings->get('shop_address_1', '') ?? ''),
            'address_2'    => (string) ($this->settings->get('shop_address_2', '') ?? ''),
            'city'         => (string) ($this->settings->get('shop_city', '') ?? ''),
            'state'        => (string) ($this->settings->get('shop_state', '') ?? ''),
            'postcode'     => (string) ($this->settings->get('shop_postcode', '') ?? ''),
            'country'      => (string) ($this->settings->get('shop_country', '') ?? ''),
            'phone'        => (string) ($this->settings->get('shop_phone', '') ?? ''),
            'email'        => (string) ($this->settings->get('shop_email', '') ?? ''),
            'vat_number'   => (string) ($this->settings->get('shop_vat_number', '') ?? ''),
        ];
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $logs    = $this->orderLogs->findByOrderId($order->getId());
        $refData = require dirname(__DIR__, 3) . '/config/reference-data.php';

        $isBankTransfer = BankTransferDetailsService::isBankTransferMethod($order->getPaymentMethod());

        $invoiceVersions = $this->extractAllInvoiceVersions($logs);
        $versionMap      = [];
        foreach ($invoiceVersions as $version) {
            $versionMap[$version['log_id']] = $version;
        }

        return $this->twig->render($response, 'admin/orders/show.twig', [
            'order'               => $order,
            'logs'                => $logs,
            'refund_id'           => $this->extractRefundIdFromLogs($logs),
            'invoice_snapshot'    => $this->extractInvoiceSnapshotFromLogs($logs),
            'invoice_versions'    => $invoiceVersions,
            'invoice_version_map' => $versionMap,
            'status_labels'       => OrderStatus::STATUSES,
            'payment_methods'     => $this->availablePaymentMethods(),
            'country_codes'       => $refData['country_codes']     ?? [],
            'shipping_carriers'   => $this->carrierCatalog->all(),
            'payment_link'        => $this->session->flash('payment_link'),
            'downloads'           => $this->downloadRepo?->findGrantsByOrderId($order->getId()) ?? [],
            'is_bank_transfer'    => $isBankTransfer,
            'bank_details'        => $isBankTransfer && $this->bankTransferDetails !== null
                ? $this->bankTransferDetails->forOrder($order)
                : null,
        ]);
    }

    /** POST /admin/orders/{id}/billing */
    public function updateBilling(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $body    = (array) $request->getParsedBody();
        $billing = $order->getBilling();

        $updated = [
            'first_name' => trim((string) ($body['first_name'] ?? '')),
            'last_name'  => trim((string) ($body['last_name'] ?? '')),
            'email'      => strtolower(trim((string) ($body['email'] ?? ''))),
            'phone'      => trim((string) ($body['phone'] ?? '')),
            'address'    => trim((string) ($body['address'] ?? '')),
            'address_1'  => trim((string) ($body['address_1'] ?? '')),
            'address_2'  => trim((string) ($body['address_2'] ?? '')),
            'city'       => trim((string) ($body['city'] ?? '')),
            'state'      => trim((string) ($body['state'] ?? '')),
            'postcode'   => trim((string) ($body['postcode'] ?? '')),
            'country'    => strtoupper(trim((string) ($body['country'] ?? ''))),
        ];

        if ($updated['first_name'] === '' || $updated['email'] === '') {
            $this->session->flash('error', 'Billing first name and email are required.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        if (!filter_var($updated['email'], FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('error', 'Billing email format is invalid.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        // Preserve existing custom/checkout metadata inside billing payload.
        foreach ($billing as $key => $value) {
            if (!array_key_exists($key, $updated)) {
                $updated[$key] = $value;
            }
        }

        $order->setBilling($updated);
        $this->orders->save($order);

        $this->orderLogs->add(
            $order->getId(),
            'billing_updated',
            'Billing details updated by admin.',
            'system',
            (int) $this->session->get('user_id'),
            (string) ($this->session->get('user_name') ?? 'Admin'),
        );

        $this->session->flash('success', $this->translator->get('Billing details updated.'));
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    public function updateShipping(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $body    = (array) $request->getParsedBody();
        $current = $order->getShippingAddress();

        $updated = [
            'first_name' => trim((string) ($body['shipping_first_name'] ?? '')),
            'last_name'  => trim((string) ($body['shipping_last_name'] ?? '')),
            'company'    => trim((string) ($body['shipping_company'] ?? '')),
            'address_1'  => trim((string) ($body['shipping_address_1'] ?? '')),
            'address_2'  => trim((string) ($body['shipping_address_2'] ?? '')),
            'city'       => trim((string) ($body['shipping_city'] ?? '')),
            'state'      => trim((string) ($body['shipping_state'] ?? '')),
            'postcode'   => trim((string) ($body['shipping_postcode'] ?? '')),
            'country'    => strtoupper(trim((string) ($body['shipping_country'] ?? ''))),
            'phone'      => trim((string) ($body['shipping_phone'] ?? '')),
        ];

        if (!empty(array_filter($updated))) {
            $order->setShippingAddress($updated);
        } else {
            $order->setShippingAddress([]);
        }

        $this->orders->save($order);

        $this->orderLogs->add(
            $order->getId(),
            'shipping_updated',
            'Shipping details updated by admin.',
            'system',
            (int) $this->session->get('user_id'),
            (string) ($this->session->get('user_name') ?? 'Admin'),
        );

        $this->session->flash('success', $this->translator->get('Shipping details updated.'));
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $body   = (array) $request->getParsedBody();
        $status = (string) ($body['status'] ?? '');

        if (OrderStatus::isValid($status) && $status !== $order->getStatus()) {
            $this->changeOrderStatus->execute($order, $status);
            $this->searchCache?->bump('order_search');
            $this->session->flash('success', $this->translator->get('Order status updated.'));
        }

        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /** POST /admin/orders/{id}/payment-method */
    public function updatePaymentMethod(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $body    = (array) $request->getParsedBody();
        $method  = trim((string) ($body['payment_method'] ?? ''));
        $allowed = array_keys($this->availablePaymentMethods());
        $old     = (string) ($order->getPaymentMethod() ?? '');

        if (!in_array($method, $allowed, true)) {
            $this->session->flash('error', $this->translator->get('Invalid payment method.'));
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        if ($method !== $old) {
            $order->setPaymentMethod($method);
            $this->orders->save($order);

            $oldLabel = $old !== '' ? ucfirst(str_replace('_', ' ', $old)) : '—';
            $newLabel = ucfirst(str_replace('_', ' ', $method));
            $this->orderLogs->add(
                $order->getId(),
                'payment_method_changed',
                'Payment method changed from "' . $oldLabel . '" to "' . $newLabel . '".'
            );

            $this->session->flash('success', $this->translator->get('Payment method updated.'));
        }

        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /** POST /admin/orders/{id}/refund/approve */
    public function approveRefundRequest(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        if ($order->getStatus() !== 'refund_requested') {
            $this->session->flash('error', 'Order has no pending refund request.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $body            = (array) $request->getParsedBody();
        $adminNote       = trim((string) ($body['admin_note'] ?? ''));
        $refundAmountRaw = trim((string) ($body['refund_amount'] ?? ''));
        $refundAmount    = (float) str_replace(',', '.', $refundAmountRaw === '' ? '0' : $refundAmountRaw);

        if ($refundAmount <= 0.0) {
            $this->session->flash('error', 'Please enter a valid refund amount.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }
        if ($refundAmount > (float) $order->getTotal()) {
            $this->session->flash('error', 'Refund amount cannot exceed order total.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $newStatus     = 'cancelled';
        $paymentMethod = (string) ($order->getPaymentMethod() ?? '');
        if (in_array($paymentMethod, $this->registeredGatewayNames(), true)) {
            try {
                $refundResult = $this->performGatewayRefund($order, $refundAmount, $adminNote);
                $refundId     = trim((string) ($refundResult['refund_id'] ?? ''));
                $newStatus    = 'refunded';
                if ($refundId !== '') {
                    $this->orderLogs->add(
                        $order->getId(),
                        'payment_refunded',
                        ucfirst($paymentMethod) . ' refund completed. Amount: ' . number_format($refundAmount, 2, '.', '') . ' ' . $order->getCurrency() . '. Refund ID: ' . $refundId . '.',
                        'system',
                        (int) $this->session->get('user_id'),
                        (string) ($this->session->get('user_name') ?? 'Admin'),
                    );
                }
            } catch (\Throwable $e) {
                $this->session->flash('error', ucfirst($paymentMethod) . ' refund failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
                return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
            }
        }

        $wasPaid   = $order->getPaidAt() !== null;
        $oldStatus = $order->getStatus();

        $order->setStatus($newStatus);
        $this->orders->save($order);
        $this->searchCache?->bump('order_search');

        if ($wasPaid) {
            $this->events->doAction('order.reversed', $order);
        }

        $this->orderStatusEffects->syncDownloadRevocation($order, $newStatus);
        $this->orderStatusEffects->syncCouponUsage($order, $oldStatus, $newStatus);

        if ($order->getOrderType() === 'subscription' && $this->subscriptionService !== null) {
            $subscriptionId = $order->getSubscriptionId();
            if ($subscriptionId !== null && $subscriptionId !== '') {
                $gateway = $order->getPaymentMethod();
                try {
                    if ($gateway === 'stripe' && $this->stripeGateway !== null) {
                        $this->stripeGateway->cancelSubscription($subscriptionId);
                    } elseif ($gateway === 'paypal' && $this->paypalGateway !== null) {
                        $this->paypalGateway->cancelBillingAgreement($subscriptionId);
                    }
                } catch (\Throwable $e) {
                    error_log('Failed to cancel subscription at gateway: ' . $e->getMessage());
                }

                $this->subscriptionService->updateSubscriptionStatus($subscriptionId, 'cancelled');
                $this->subscriptionService->removePlanTagBySubscriptionId($subscriptionId);
            }
        }

        $note = 'Refund request approved by admin.';
        $note .= ' Amount: ' . number_format($refundAmount, 2, '.', '') . ' ' . $order->getCurrency() . '.';
        if ($adminNote !== '') {
            $note .= ' Note: ' . $adminNote;
        }
        $this->orderLogs->add($order->getId(), 'refund_request_approved', $note, 'system', (int) $this->session->get('user_id'), (string) ($this->session->get('user_name') ?? 'Admin'));

        $this->events?->doAction('order.status_changed', $order, $newStatus);
        $this->sendRefundReviewEmail($order, true, $adminNote);
        if ($newStatus === 'refunded') {
            $this->session->flash('success', 'Refund request approved and gateway refund completed.');
        } else {
            $this->session->flash('success', 'Refund request approved and order cancelled.');
        }

        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /** POST /admin/orders/{id}/refund/reject */
    public function rejectRefundRequest(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        if ($order->getStatus() !== 'refund_requested') {
            $this->session->flash('error', 'Order has no pending refund request.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $body   = (array) $request->getParsedBody();
        $reason = trim((string) ($body['reject_reason'] ?? ''));
        if ($reason === '') {
            $this->session->flash('error', 'Please provide a rejection reason.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $order->setStatus('refund_rejected');
        $this->orders->save($order);
        $this->searchCache?->bump('order_search');

        $this->orderLogs->add(
            $order->getId(),
            'refund_request_rejected',
            'Reason: ' . $reason,
            'system',
            (int) $this->session->get('user_id'),
            (string) ($this->session->get('user_name') ?? 'Admin'),
        );

        $this->events?->doAction('order.status_changed', $order, 'refund_rejected');
        $this->sendRefundReviewEmail($order, false, $reason);
        $this->session->flash('success', 'Refund request rejected and customer notified.');

        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    public function couponPreview(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $code     = strtoupper(trim($body['code'] ?? ''));
        $subtotal = max(0.0, (float) str_replace(',', '.', $body['subtotal'] ?? '0'));

        $payload = ['valid' => false, 'discount' => 0.0, 'free_shipping' => false];

        if ($code !== '') {
            $coupon = $this->coupons->findByCode($code);
            if ($coupon !== null && $coupon->isValid($subtotal)) {
                $payload['valid']         = true;
                $payload['discount']      = round($coupon->calculateDiscount($subtotal), 2);
                $payload['free_shipping'] = $coupon->hasFreeShipping();
            }
        }

        $response->getBody()->write(json_encode($payload));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /** @return array<string,string> payment method slug => label (offline + enabled gateways) */
    private function availablePaymentMethods(): array
    {
        $methods = [
            'cash'          => $this->translator->get('Cash'),
            'manual'        => $this->translator->get('Manual / Other'),
            // Must match the checkout-side slug ("banktransfer") so payment-method
            // checks treat admin-created and customer-created orders identically.
            'banktransfer' => $this->translator->get('Bank Transfer'),
        ];

        $refData  = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $gateways = $refData['signup_urls']['payment_gateways'] ?? [];
        foreach ($gateways as $slug => $gateway) {
            if ($this->settings->get($slug . '_enabled', '0') === '1') {
                $methods[$slug] = $gateway['name'] ?? ucfirst($slug);
            }
        }

        return $methods;
    }

    public function create(Request $request, Response $response): Response
    {
        $referenceData   = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $currencyCatalog = is_array($referenceData['currencies'] ?? null) ? $referenceData['currencies'] : [];
        $countryCodes    = is_array($referenceData['country_codes'] ?? null) ? $referenceData['country_codes'] : [];
        $defaultCurrency = $this->settings->get('currency', 'USD');

        $paymentMethods = $this->availablePaymentMethods();

        return $this->twig->render($response, 'admin/orders/create.twig', [
            'payment_methods'  => $paymentMethods,
            'statuses'         => OrderStatus::STATUSES,
            'currency_catalog' => $currencyCatalog,
            'country_codes'    => $countryCodes,
            'default_currency' => $defaultCurrency,
            'flash_error'      => $this->session->flash('flash_error'),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        // --- Billing ---
        $billing = [
            'first_name' => trim($body['first_name'] ?? ''),
            'last_name'  => trim($body['last_name']  ?? ''),
            'email'      => trim($body['email']      ?? ''),
            'phone'      => trim($body['phone']      ?? ''),
            'address_1'  => trim($body['address_1']  ?? ''),
            'address_2'  => trim($body['address_2']  ?? ''),
            'city'       => trim($body['city']       ?? ''),
            'state'      => trim($body['state']      ?? ''),
            'postcode'   => trim($body['postcode']   ?? ''),
            'country'    => trim($body['country']    ?? ''),
        ];

        if (empty($billing['first_name']) || empty($billing['email'])) {
            $this->session->flash('error', 'First name and email are required.');
            return $response->withHeader('Location', admin_url('/orders/create'))->withStatus(302);
        }

        // --- Line items ---
        $rawItems = $body['items'] ?? [];
        $items    = [];
        $subtotal = 0.0;

        foreach ((array) $rawItems as $row) {
            $qty   = max(1, (int) ($row['qty'] ?? 1));
            $price = (float) str_replace(',', '.', $row['price'] ?? '0');
            $name  = trim($row['name'] ?? '');
            $pid   = isset($row['product_id']) && (int) $row['product_id'] > 0
                ? (int) $row['product_id'] : null;
            $vid   = isset($row['variation_id']) && (int) $row['variation_id'] > 0
                ? (int) $row['variation_id'] : null;

            $sku           = null;
            $variationData = null;

            // If a product was selected, use its name/price unless overridden
            if ($pid !== null) {
                $product = $this->products->findById($pid);
                if ($product !== null) {
                    if (empty($name)) {
                        $name = $product->getName();
                    }

                    $variationRow = null;
                    if ($vid !== null) {
                        foreach ($this->products->findVariationsByProductId($pid) as $v) {
                            if ((int) $v['id'] === $vid) {
                                $variationRow = $v;
                                break;
                            }
                        }
                    }

                    if ($variationRow !== null) {
                        $attrs = is_string($variationRow['attributes'] ?? null)
                            ? json_decode((string) $variationRow['attributes'], true)
                            : ($variationRow['attributes'] ?? null);
                        if (is_array($attrs)) {
                            foreach ((array) ($row['variation_selected'] ?? []) as $tKey => $tVal) {
                                $tKey = trim((string) $tKey);
                                $tVal = trim((string) $tVal);
                                if ($tKey !== '' && $tVal !== '') {
                                    $attrs[$tKey] = $tVal;
                                }
                            }
                            if ($attrs !== []) {
                                $variationData = json_encode($attrs);
                            }
                        }
                        $variationSku = trim((string) ($variationRow['sku'] ?? ''));
                        $sku          = $variationSku !== '' ? $variationSku : ($product->getSku() ?? null);
                        if ($price <= 0.0) {
                            $price = VariationPrice::resolve($variationRow);
                        }
                    } else {
                        $vid = null;
                        if ($price <= 0.0) {
                            $price = $product->getPrice();
                        }
                    }
                } else {
                    $pid = null;
                    $vid = null;
                }
            }

            if (empty($name) || $price < 0) {
                continue;
            }

            $lineSubtotal = round($price * $qty, 2);
            $subtotal    += $lineSubtotal;
            $items[] = [
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

        if (empty($items)) {
            $this->session->flash('error', $this->translator->get('At least one line item is required.'));
            return $response->withHeader('Location', admin_url('/orders/create'))->withStatus(302);
        }

        // --- Shipping & discount ---
        $shipping       = max(0.0, (float) str_replace(',', '.', $body['shipping'] ?? '0'));
        $manualDiscount = max(0.0, (float) str_replace(',', '.', $body['discount'] ?? '0'));
        $couponCode     = strtoupper(trim($body['coupon_code'] ?? ''));
        $couponDiscount = 0.0;
        $resolvedCode   = null;

        if ($couponCode !== '') {
            $coupon = $this->coupons->findByCode($couponCode);
            if ($coupon !== null && $coupon->isValid($subtotal)) {
                $billingEmail = strtolower(trim($body['email'] ?? ''));
                $allowed      = $coupon->getAllowedEmails();
                $emailOk      = empty($allowed) || ($billingEmail !== '' && in_array($billingEmail, $allowed, true));
                if ($emailOk) {
                    $couponDiscount = $coupon->calculateDiscount($subtotal);
                    $resolvedCode   = $coupon->getCode();
                    $this->coupons->incrementUses($coupon->getId());
                }
            }
        }

        $totalDiscount = $manualDiscount + $couponDiscount;

        // --- Build order ---
        $status    = OrderStatus::isValid((string) ($body['status'] ?? '')) ? (string) $body['status'] : 'pending';
        $currency  = strtoupper(trim($body['currency'] ?? 'USD')) ?: 'USD';

        // --- Shipping address ---
        $shippingAddress = [
            'first_name' => trim($body['shipping_first_name'] ?? ''),
            'last_name'  => trim($body['shipping_last_name']  ?? ''),
            'company'    => trim($body['shipping_company']    ?? ''),
            'address_1'  => trim($body['shipping_address_1']  ?? ''),
            'address_2'  => trim($body['shipping_address_2']  ?? ''),
            'city'       => trim($body['shipping_city']       ?? ''),
            'state'      => trim($body['shipping_state']      ?? ''),
            'postcode'   => trim($body['shipping_postcode']   ?? ''),
            'country'    => trim($body['shipping_country']    ?? ''),
            'phone'      => trim($body['shipping_phone']      ?? ''),
        ];

        $order = new Order($this->orders->generateOrderNumber(), $billing);
        if (!empty(array_filter($shippingAddress))) {
            $order->setShippingAddress($shippingAddress);
        }
        $order->setItems($items);
        $order->setSubtotal(round($subtotal, 2));
        $order->setShipping(round($shipping, 2));
        $order->setDiscount(round($totalDiscount, 2));
        $order->setCouponCode($resolvedCode);
        $order->setStatus($status);
        $order->setCurrency($currency);
        $order->setPaymentMethod($body['payment_method'] ?? null);
        $order->setCustomerNote(trim($body['customer_note'] ?? '') ?: null);
        if (!empty($body['transaction_id'])) {
            $order->setTransactionId(trim($body['transaction_id']));
        }
        if ($status === 'completed') {
            $order->setPaidAt(new \DateTimeImmutable());
        }
        if (OrderStatus::isUnpaidTerminal($status) && $resolvedCode !== null) {
            // The coupon use was reserved at validation time but the order
            // is already terminal — mark it returned so a later delete never
            // gives the (unearned) use back.
            $order->setCouponReturned(true);
        }
        $order->calculateTotal();

        $this->orders->save($order);
        $this->searchCache?->bump('order_search');

        if (in_array($order->getStatus(), OrderStatus::PAID, true)) {
            if ($order->getPaidAt() === null) {
                $order->setPaidAt(new \DateTimeImmutable());
                $this->orders->save($order);
            }
            $this->events->doAction('order.paid', $order);
            $this->orderStatusEffects->tagSubscriberPaid($order);
        }

        $statusLabel = OrderStatus::label($status);
        $this->orderLogs->add(
            $order->getId(),
            'order_created',
            'Manual order created by admin. Initial status: "' . $statusLabel . '".'
        );
        $this->session->flash('success', $this->translator->get('Order #:number created.', [':number' => $order->getOrderNumber()]));

        return $response->withHeader('Location', admin_url('/orders/' . $order->getId()))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);

        // A paid/processed order carries side effects (decremented stock,
        // total_sold). Reverse them before the rows disappear. The reversal
        // listener's atomic claim prevents double-restoration.
        if ($order !== null) {
            $wasProcessed = $order->isInventoryProcessed() === true || $order->getPaidAt() !== null;

            // A paid/processed order carries side effects (decremented stock,
            // total_sold). Reverse them before the rows disappear. The reversal
            // listener's atomic claim prevents double-restoration.
            if ($wasProcessed) {
                $this->events->doAction('order.reversed', $order);
            }

            // Return the coupon use — the order is being removed entirely.
            // The atomic claim guarantees a single return even when the use
            // was already returned by a prior status transition or by a
            // gateway "payment failed" / checkout-cancel event.
            $couponCode = $order->getCouponCode();
            if ($couponCode !== null && $couponCode !== ''
                                     && $this->orders->claimCouponReturn((int) $order->getId())
            ) {
                $coupon = $this->coupons->findByCode((string) $couponCode);
                if ($coupon !== null) {
                    $this->coupons->decrementUses((int) $coupon->getId());
                }
            }
        }

        $this->orders->delete((int) $args['id']);
        $this->searchCache?->bump('order_search');
        $this->session->flash('success', $this->translator->get('Order deleted.'));
        return $response->withHeader('Location', admin_url('/orders'))->withStatus(302);
    }

    public function addNote(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $note = trim($body['note'] ?? '');

        if ($note !== '') {
            $userId   = (int) $this->session->get('user_id');
            $addedBy  = (string) ($this->session->get('user_name') ?? 'Admin');
            $this->orderLogs->add(
                $order->getId(),
                'admin_note',
                $note,
                'note',
                $userId ?: null,
                $addedBy,
            );
            $this->session->flash('success', 'Note added.');
        }

        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    public function sendCustomerEmail(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $body    = (array) $request->getParsedBody();
        $subject = trim($body['subject'] ?? '');
        $message = trim($body['message'] ?? '');

        if ($subject === '' || $message === '') {
            $this->session->flash('error', 'Subject and message are required.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $billing = $order->getBilling();
        $email   = $billing['email'] ?? null;

        if (empty($email)) {
            $this->session->flash('error', 'No customer email address on this order.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        if ($this->mail !== null) {
            try {
                $this->mail->sendTemplate(
                    $email,
                    $subject,
                    'mail/order-note',
                    ['order' => $order, 'message' => $message, 'subject' => $subject],
                    locale: $order->getLocale(),
                    recipientType: 'customer',
                );
            } catch (\Throwable $e) {
                error_log('[Order] Failed to send customer email for order #' . $order->getOrderNumber() . ': ' . $e->getMessage());
                $this->session->flash('error', 'Failed to send email: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
                return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
            }
        }

        $userId  = (int) $this->session->get('user_id');
        $addedBy = (string) ($this->session->get('user_name') ?? 'Admin');
        $this->orderLogs->add(
            $order->getId(),
            'email_sent',
            'Email sent to ' . $email . '. Subject: "' . $subject . '".',
            'email',
            $userId ?: null,
            $addedBy,
        );

        $this->session->flash('success', 'Email sent to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.');
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /** POST /admin/orders/{id}/send-email */
    public function sendEmail(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $body      = (array) $request->getParsedBody();
        $emailType = $body['email_type'] ?? 'custom';

        $email = $this->getCustomerEmail($order);
        if ($email === null) {
            $this->session->flash('error', 'No customer email address on this order.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        return match ($emailType) {
            'order_confirmation' => $this->resendOrderConfirmation($order, $email, $response, $args),
            'payment_received'   => $this->resendPaymentReceived($order, $email, $response, $args),
            'payment_link'       => $this->sendPaymentLinkEmail($order, $email, $response, $args),
            'invoice'            => $this->sendInvoiceToCustomer($request, $response, $args),
            default              => $this->sendCustomerEmail($request, $response, $args),
        };
    }

    private function getCustomerEmail(Order $order): ?string
    {
        $billing = $order->getBilling();
        $email   = $billing['email'] ?? null;
        return !empty($email) ? $email : null;
    }

    private function logEmailSent(Order $order, string $email, string $logDetail): void
    {
        $userId  = (int) $this->session->get('user_id');
        $addedBy = (string) ($this->session->get('user_name') ?? 'Admin');
        $this->orderLogs->add(
            $order->getId(),
            'email_sent',
            $logDetail,
            'email',
            $userId ?: null,
            $addedBy,
        );
    }

    /**
     * @return array{min: ?int, max: ?int}
     */
    private function orderShippingEstimate(Order $order): array
    {
        return $this->orderEstimator->estimate($order);
    }

    private function resendOrderConfirmation(Order $order, string $email, Response $response, array $args): Response
    {
        $templateData = [
            'order'             => $order,
            'policy_links'      => $this->policyLinks(),
            'downloads'         => $this->downloadRepo?->findGrantsByOrderId($order->getId()) ?? [],
            'refund_request'    => $this->buildRefundRequestPayload($order),
            'shipping_estimate' => $this->orderShippingEstimate($order),
            'order_status_url'  => absolute_url('/checkout/success', [], ['lang' => $order->getLocale(), 'order' => $order->getOrderNumber()]),
        ];

        try {
            $this->mail?->sendTemplate(
                $email,
                'Order Confirmation — #' . $order->getOrderNumber(),
                'mail/order-confirmation',
                $templateData,
                locale: $order->getLocale(),
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Failed to send order confirmation: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $this->logEmailSent($order, $email, 'Order confirmation resent to ' . $email . '.');
        $this->session->flash('success', 'Order confirmation sent to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.');
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    private function resendPaymentReceived(Order $order, string $email, Response $response, array $args): Response
    {
        $templateData = [
            'order'             => $order,
            'policy_links'      => $this->policyLinks(),
            'downloads'         => $this->downloadRepo?->findGrantsByOrderId($order->getId()) ?? [],
            'refund_request'    => $this->buildRefundRequestPayload($order),
            'shipping_estimate' => $this->orderShippingEstimate($order),
            'order_status_url'  => absolute_url('/checkout/success', [], ['lang' => $order->getLocale(), 'order' => $order->getOrderNumber()]),
        ];

        try {
            $this->mail?->sendTemplate(
                $email,
                'Payment Received — #' . $order->getOrderNumber(),
                'mail/payment-received',
                $templateData,
                locale: $order->getLocale(),
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Failed to send payment received email: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $this->logEmailSent($order, $email, 'Payment received email resent to ' . $email . '.');
        $this->session->flash('success', 'Payment received email sent to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.');
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    private function sendPaymentLinkEmail(Order $order, string $email, Response $response, array $args): Response
    {
        $token = bin2hex(random_bytes(32));
        $order->setPaymentToken($token);
        $this->orders->save($order);

        $link = __require_app_url() . '/pay/' . $token;

        $isBankTransfer = BankTransferDetailsService::isBankTransferMethod($order->getPaymentMethod());
        $this->orderLogs->add(
            $order->getId(),
            'payment_link_generated',
            $isBankTransfer
                ? 'Bank transfer instruction link generated and sent to customer: ' . $link
                : 'Payment link generated and sent to customer: ' . $link
        );

        $templateData   = [
            'order'            => $order,
            'payment_link'     => $link,
            'is_bank_transfer' => $isBankTransfer,
        ];
        if ($isBankTransfer && $this->bankTransferDetails !== null) {
            $templateData += $this->bankTransferDetails->forOrder($order);
        }

        try {
            $this->mail?->sendTemplate(
                $email,
                $isBankTransfer
                    ? 'Bank Transfer Instructions — Order #' . $order->getOrderNumber()
                    : 'Payment Link — Order #' . $order->getOrderNumber(),
                'mail/payment-link',
                $templateData,
                locale: $order->getLocale(),
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Failed to send payment link email: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $logDetail = $isBankTransfer
            ? 'Bank transfer instructions email sent to ' . $email . '.'
            : 'Payment link email sent to ' . $email . '.';
        $this->logEmailSent($order, $email, $logDetail);
        $this->session->flash(
            'success',
            $isBankTransfer
                ? 'Bank transfer instructions sent to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.'
                : 'Payment link generated and sent to ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '.'
        );
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /** POST /admin/orders/{id}/tracking */
    public function updateTracking(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $body     = (array) $request->getParsedBody();
        $carrier  = trim((string) ($body['carrier'] ?? ''));
        $trackNum = trim((string) ($body['tracking_number'] ?? $body['tracking_url'] ?? ''));

        // The "notify" button sends value "1" (Notify) or "0" (Save only).
        // We check if it's set and explicitly truthy.
        $notify = isset($body['notify']) && (string) $body['notify'] === '1';

        if ($trackNum === '') {
            $this->session->flash('error', 'Tracking number is required.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $this->updateOrderTracking->execute(
            $order,
            $carrier,
            $trackNum,
            $notify,
            (string) ($this->session->get('user_name') ?? 'Admin'),
            (int) $this->session->get('user_id'),
        );

        $this->session->flash('success', $notify ? 'Tracking saved and customer notified.' : 'Tracking saved.');

        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /**
     * Builds policy page URLs from settings (returns/privacy/terms).
     */
    private function policyLinks(): array
    {
        $base  = __require_app_url();
        $keys  = ['returns' => 'policy_page_returns', 'privacy' => 'policy_page_privacy', 'terms' => 'policy_page_terms'];
        $links = [];
        foreach ($keys as $label => $settingKey) {
            $slug = trim($this->settings->get($settingKey, '') ?? '');
            if ($slug !== '') {
                $links[$label] = $base . '/' . $slug;
            }
        }
        return $links;
    }

    /**
     * @return array{status:string,reason:?string,requested_at:?string,deadline:?string}
     */
    private function buildRefundRequestPayload(Order $order): array
    {
        $reason      = null;
        $requestedAt = null;

        $logs = $this->orderLogs->findByOrderId($order->getId());
        foreach ($logs as $log) {
            if ($log->getEvent() === 'refund_requested') {
                $requestedAt = $log->getCreatedAt()->format('Y-m-d H:i');
                $note        = (string) ($log->getNote() ?? '');
                if (str_starts_with($note, 'Customer reason: ')) {
                    $reason = trim(substr($note, strlen('Customer reason: ')));
                }
            }
        }

        $deadline = $order->getCreatedAt()?->modify('+14 days')->format('Y-m-d H:i');

        return [
            'status'       => $order->getStatus(),
            'reason'       => $reason,
            'requested_at' => $requestedAt,
            'deadline'     => $deadline,
        ];
    }

    public function deleteLog(Request $request, Response $response, array $args): Response
    {
        $orderId = (int) $args['id'];
        $logId   = (int) $args['logId'];

        // Fetch all logs for this order and verify the log belongs to it and is a note
        $logs = $this->orderLogs->findByOrderId($orderId);
        foreach ($logs as $log) {
            if ($log->getId() === $logId && $log->getType() === 'note') {
                $this->orderLogs->delete($logId);
                $this->session->flash('success', $this->translator->get('Note deleted.'));
                break;
            }
        }

        return $response->withHeader('Location', admin_url('/orders/' . $orderId))->withStatus(302);
    }

    /** @return list<string> slugs of gateways registered in the DI container */
    private function registeredGatewayNames(): array
    {
        $names = [];
        foreach (
            [$this->stripeGateway, $this->paypalGateway, $this->mollieGateway, $this->twoCheckoutGateway, $this->checkoutComGateway] as $gw
        ) {
            if ($gw !== null) {
                $names[] = $gw->getName();
            }
        }
        return $names;
    }

    public function generatePaymentLink(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $method         = trim((string) ($order->getPaymentMethod() ?? ''));
        $isBankTransfer = BankTransferDetailsService::isBankTransferMethod($method);
        $gatewayNames   = $this->registeredGatewayNames();
        if (!$isBankTransfer && ($method === '' || !in_array($method, $gatewayNames, true))) {
            $this->session->flash('error', $this->translator->get('Payment links require an online payment method. Change the payment method first.'));
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        // Always regenerate so the old link is invalidated
        $token = bin2hex(random_bytes(32));
        $order->setPaymentToken($token);
        $this->orders->save($order);

        $link      = __require_app_url() . '/pay/' . $token;
        $logDetail = $isBankTransfer
            ? 'Bank transfer instruction link generated by admin: ' . $link
            : 'Payment link generated by admin: ' . $link;
        $this->orderLogs->add(
            $order->getId(),
            'payment_link_generated',
            $logDetail
        );
        $this->session->flash('payment_link', $link);
        $this->session->flash(
            'success',
            $isBankTransfer
                ? 'Bank transfer instruction link generated.'
                : 'Payment link generated.'
        );

        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /** POST /admin/orders/{id}/invoice/issue */
    public function issueInvoice(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $logs                       = $this->orderLogs->findByOrderId($order->getId());
        $snapshot                   = $this->buildInvoiceSnapshot($order, $logs);
        $isFirst                    = $this->extractInvoiceSnapshotFromLogs($logs) === null;
        $revision                   = count($this->extractAllInvoiceVersions($logs)) + 1;
        $snapshot['revision']       = $revision;
        $snapshot['invoice_number'] = $this->versionedInvoiceNumber($snapshot['invoice_number'], $revision);
        $event                      = $isFirst ? 'invoice_issued' : 'invoice_reissued';
        $this->orderLogs->add(
            $order->getId(),
            $event,
            json_encode($snapshot, JSON_UNESCAPED_SLASHES),
            'system',
            (int) $this->session->get('user_id'),
            (string) ($this->session->get('user_name') ?? 'Admin'),
        );

        $this->session->flash('success', ($event === 'invoice_reissued' ? 'Invoice reissued with latest totals: ' : 'Invoice issued: ') . $snapshot['invoice_number']);
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /** GET /admin/orders/{id}/invoice */
    public function viewInvoice(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $logs     = $this->orderLogs->findByOrderId($order->getId());
        $versions = $this->extractAllInvoiceVersions($logs);
        if ($versions === []) {
            $this->session->flash('error', 'Issue invoice first.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $query      = $request->getQueryParams();
        $resolved   = $this->resolveInvoiceVersion($versions, (int) ($query['log'] ?? 0));
        $snapshot   = $resolved['snapshot'];
        $latest     = end($versions);
        $isLatest   = $resolved['log_id'] === $latest['log_id'];

        return $this->twig->render($response, 'admin/orders/invoice.twig', [
            'order'          => $order,
            'invoice'        => $snapshot,
            'pdf_available'  => $this->pdfGenerator?->isAvailable() ?? false,
            'is_latest'      => $isLatest,
            'latest_invoice' => $isLatest ? null : $latest['snapshot'],
            'prev_number'    => $this->previousInvoiceNumber($snapshot),
        ]);
    }

    /** GET /admin/orders/{id}/invoice/pdf */
    public function downloadPdf(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        $logs     = $this->orderLogs->findByOrderId($order->getId());
        $versions = $this->extractAllInvoiceVersions($logs);
        if ($versions === []) {
            $this->session->flash('error', 'Issue invoice first.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $query    = $request->getQueryParams();
        $resolved = $this->resolveInvoiceVersion($versions, (int) ($query['log'] ?? 0));
        $snapshot = $resolved['snapshot'];
        $latest   = end($versions);
        $isLatest = $resolved['log_id'] === $latest['log_id'];

        if ($this->pdfGenerator === null || !$this->pdfGenerator->isAvailable()) {
            $this->session->flash('error', 'PDF generation is not available — missing PHP extension (dom). Install php-xml package and restart your web server.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $html = $this->twig->getEnvironment()->render('pdf/invoice.twig', [
            'order'          => $order,
            'invoice'        => $snapshot,
            'is_latest'      => $isLatest,
            'latest_invoice' => $isLatest ? null : $latest['snapshot'],
            'prev_number'    => $this->previousInvoiceNumber($snapshot),
        ]);

        $pdfBinary = $this->pdfGenerator->generate($html);
        $filename  = preg_replace('/[^A-Za-z0-9._-]+/', '', (string) ($snapshot['invoice_number'] ?? 'invoice')) . '.pdf';

        $response->getBody()->write($pdfBinary);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($pdfBinary));
    }

    /** POST /admin/orders/{id}/invoice/send */
    public function sendInvoiceToCustomer(Request $request, Response $response, array $args): Response
    {
        $order = $this->orders->findById((int) $args['id']);
        if ($order === null) {
            return $response->withStatus(404);
        }

        if ($this->mail === null) {
            $this->session->flash('error', 'Mail service is not configured.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $logs     = $this->orderLogs->findByOrderId($order->getId());
        $versions = $this->extractAllInvoiceVersions($logs);
        if ($versions === []) {
            $this->session->flash('error', 'Issue invoice first.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $body     = (array) $request->getParsedBody();
        $query    = $request->getQueryParams();
        $snapshot = $this->resolveInvoiceVersion($versions, (int) ($body['log'] ?? $query['log'] ?? 0))['snapshot'];

        $email = (string) ($snapshot['billing']['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('error', 'Invoice cannot be sent: customer email is missing/invalid.');
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        try {
            $attachments = [];

            $mailDriver   = $this->settings->get('mail_driver', 'log');
            $pdfAvailable = $this->pdfGenerator !== null && $this->pdfGenerator->isAvailable();

            if ($mailDriver !== 'log' && $pdfAvailable) {
                $pdfHtml = $this->twig->getEnvironment()->render('pdf/invoice.twig', [
                    'order'          => $order,
                    'invoice'        => $snapshot,
                    'is_latest'      => true,
                    'latest_invoice' => null,
                    'prev_number'    => $this->previousInvoiceNumber($snapshot),
                ]);
                $pdfBinary     = $this->pdfGenerator->generate($pdfHtml);
                $attachments[] = [
                    'name'    => preg_replace('/[^A-Za-z0-9._-]+/', '', (string) ($snapshot['invoice_number'] ?? 'invoice')) . '.pdf',
                    'content' => $pdfBinary,
                    'mime'    => 'application/pdf',
                ];
            }

            if ($mailDriver !== 'log' && !$pdfAvailable) {
                $this->session->flash('warning', 'PDF attachment could not be generated — missing PHP extension (dom). Email sent without PDF.');
            }

            $this->mail->sendTemplate(
                $email,
                $this->translator->get('Invoice :number', [':number' => ($snapshot['invoice_number'] ?? ('INV-' . $order->getOrderNumber()))], $order->getLocale()),
                'mail/invoice',
                [
                    'order'          => $order,
                    'invoice'        => $snapshot,
                    'is_latest'      => true,
                    'latest_invoice' => null,
                    'prev_number'    => $this->previousInvoiceNumber($snapshot),
                ],
                null,
                null,
                $attachments,
                locale: $order->getLocale(),
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Invoice email failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
        }

        $this->orderLogs->add(
            $order->getId(),
            'invoice_sent',
            'Invoice ' . ($snapshot['invoice_number'] ?? '') . ' sent to ' . $email . '.',
            'email',
            (int) $this->session->get('user_id'),
            (string) ($this->session->get('user_name') ?? 'Admin'),
        );

        $this->session->flash('success', 'Invoice ' . ($snapshot['invoice_number'] ?? '') . ' sent to customer.');
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    private function sendRefundReviewEmail(Order $order, bool $approved, string $note): void
    {
        if ($this->mail === null) {
            return;
        }

        $billing = $order->getBilling();
        $email   = $billing['email'] ?? null;
        if (empty($email)) {
            return;
        }

        try {
            $this->mail->sendTemplate(
                $email,
                $this->translator->get($approved ? 'Refund Request Approved — #:number' : 'Refund Request Rejected — #:number', [':number' => $order->getOrderNumber()], $order->getLocale()),
                'mail/refund-request-reviewed',
                [
                    'order'            => $order,
                    'approved'         => $approved,
                    'note'             => $note,
                    'order_status_url' => absolute_url('/checkout/success', [], ['lang' => $order->getLocale(), 'order' => $order->getOrderNumber()]),
                ],
                locale: $order->getLocale(),
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            error_log('[Order] Failed to send refund review email for order #' . $order->getOrderNumber() . ': ' . $e->getMessage());
        }
    }

    /** @param array<int, OrderLog> $logs */
    private function extractRefundIdFromLogs(array $logs): ?string
    {
        for ($i = count($logs) - 1; $i >= 0; $i--) {
            $log = $logs[$i];
            if ($log->getEvent() !== 'payment_refunded') {
                continue;
            }

            $note = (string) ($log->getNote() ?? '');
            if ($note !== '' && preg_match('/Refund ID:\s*([A-Za-z0-9_\-]+)/', $note, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** @param array<int, OrderLog> $logs */
    private function extractInvoiceSnapshotFromLogs(array $logs): ?array
    {
        $versions = $this->extractAllInvoiceVersions($logs);
        if ($versions === []) {
            return null;
        }

        return end($versions)['snapshot'];
    }

    /**
     * All issued invoice versions, oldest first. Revision numbers are derived
     * from position (1-based) so pre-existing snapshots without a stored
     * revision resolve deterministically. System log rows are append-only,
     * therefore positions are stable (GoBD audit trail).
     *
     * @param array<int, OrderLog> $logs
     * @return list<array{log_id:int,revision:int,event:string,snapshot:array<string,mixed>,created_at:\DateTimeImmutable,added_by:?string}>
     */
    private function extractAllInvoiceVersions(array $logs): array
    {
        $versions = [];
        foreach ($logs as $log) {
            if (!in_array($log->getEvent(), ['invoice_issued', 'invoice_reissued'], true)) {
                continue;
            }
            $decoded = json_decode((string) ($log->getNote() ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $revision                  = count($versions) + 1;
            $decoded['revision']       = $revision;
            $decoded['invoice_number'] = $this->versionedInvoiceNumber(
                (string) ($decoded['invoice_number'] ?? 'INV-'),
                $revision
            );
            $versions[] = [
                'log_id'     => (int) $log->getId(),
                'revision'   => $revision,
                'event'      => $log->getEvent(),
                'snapshot'   => $decoded,
                'created_at' => $log->getCreatedAt(),
                'added_by'   => $log->getAddedBy(),
            ];
        }

        return $versions;
    }

    /**
     * @param list<array{log_id:int,revision:int,event:string,snapshot:array<string,mixed>,created_at:\DateTimeImmutable,added_by:?string}> $versions
     * @return array{log_id:int,revision:int,event:string,snapshot:array<string,mixed>,created_at:\DateTimeImmutable,added_by:?string}
     */
    private function resolveInvoiceVersion(array $versions, int $logId): array
    {
        foreach ($versions as $version) {
            if ($version['log_id'] === $logId) {
                return $version;
            }
        }

        return end($versions);
    }

    private function versionedInvoiceNumber(string $baseNumber, int $revision): string
    {
        $base = (string) preg_replace('/-R\d+$/', '', $baseNumber);
        if ($base === '') {
            $base = $baseNumber;
        }

        return $revision <= 1 ? $base : $base . '-R' . $revision;
    }

    /** @param array<string,mixed> $snapshot */
    private function previousInvoiceNumber(array $snapshot): ?string
    {
        $revision = (int) ($snapshot['revision'] ?? 1);
        if ($revision <= 1) {
            return null;
        }
        $base = (string) preg_replace('/-R\d+$/', '', (string) ($snapshot['invoice_number'] ?? ''));
        if ($base === '') {
            return null;
        }

        return $revision === 2 ? $base : $base . '-R' . ($revision - 1);
    }

    /** @param array<int, OrderLog> $logs */
    private function buildInvoiceSnapshot(Order $order, array $logs): array
    {
        $refunds       = $this->collectRefundLinesFromLogs($logs, $order->getCurrency());
        $refundedTotal = array_reduce($refunds, static fn (float $carry, array $line) => $carry + (float) ($line['amount'] ?? 0.0), 0.0);

        // Resolve tax policy based on order's billing country
        $billingCountry = strtoupper(trim((string) ($order->getBilling()['country'] ?? '')));
        $taxPolicy      = $this->resolveTaxPolicyForCountry($billingCountry);
        $taxRegime      = $taxPolicy['regime'];
        $taxRate        = $taxPolicy['rate'];
        $taxZeroReason  = $taxPolicy['zero_reason'];

        return [
            'invoice_number' => 'INV-' . $order->getOrderNumber(),
            'issued_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'currency'       => $order->getCurrency(),
            'shop'           => [
                'company_name' => (string) ($this->settings->get('shop_company_name', '') ?? ''),
                'address_1'    => (string) ($this->settings->get('shop_address_1', '') ?? ''),
                'address_2'    => (string) ($this->settings->get('shop_address_2', '') ?? ''),
                'city'         => (string) ($this->settings->get('shop_city', '') ?? ''),
                'state'        => (string) ($this->settings->get('shop_state', '') ?? ''),
                'postcode'     => (string) ($this->settings->get('shop_postcode', '') ?? ''),
                'country'      => (string) ($this->settings->get('shop_country', '') ?? ''),
                'phone'        => (string) ($this->settings->get('shop_phone', '') ?? ''),
                'email'        => (string) ($this->settings->get('shop_email', '') ?? ''),
                'vat_number'   => (string) ($this->settings->get('shop_vat_number', '') ?? ''),
            ],
            'billing'         => $order->getBilling(),
            'items'           => $order->getItems(),
            'subtotal'        => $order->getSubtotal(),
            'shipping'        => $order->getShipping(),
            'tax'             => $order->getTax(),
            'tax_regime'      => $taxRegime,
            'tax_rate'        => $taxRate,
            'tax_zero_reason' => $taxZeroReason,
            'tax_country'     => $billingCountry,
            'discount'        => $order->getDiscount(),
            'total'           => $order->getTotal(),
            'refunds'         => $refunds,
            'refunded_total'  => round($refundedTotal, 2),
            'net_paid'        => round(max(0.0, $order->getTotal() - $refundedTotal), 2),
        ];
    }

    /** @return array{regime:string,rate:float,zero_reason:string} */
    private function resolveTaxPolicyForCountry(string $countryCode): array
    {
        $displayMode   = ''; // not needed for invoice snapshot
        $legacyRate    = (string) ($this->settings->get('tax_rate', '19') ?? '19');
        $globalRateRaw = (string) ($this->settings->get('tax_default_rate', $legacyRate) ?? $legacyRate);
        $globalRate    = max(0.0, (float) str_replace(',', '.', $globalRateRaw));

        $companyCountry = strtoupper(trim((string) ($this->settings->get('shop_country', '') ?? '')));

        if ($countryCode !== '') {
            $countryTax = $this->countryTaxRates->findByCountry($countryCode);

            if ($countryTax !== null) {
                $regime = $countryTax->getRegime();
                if ($regime === 'normal_vat') {
                    return [
                        'regime'      => 'normal_vat',
                        'rate'        => $countryTax->getRate() ?? $globalRate,
                        'zero_reason' => '',
                    ];
                }
                $zeroReason = trim($countryTax->getZeroReason() ?? '');
                if ($zeroReason === '') {
                    $zeroReason = 'No tax applied (0%).';
                }
                return [
                    'regime'      => 'no_tax',
                    'rate'        => 0.0,
                    'zero_reason' => $zeroReason,
                ];
            }

            $fallback = $this->countryTaxRates->findFallback();
            if ($fallback !== null) {
                $fbRegime = $fallback->getRegime();
                if ($fbRegime === 'use_global') {
                    $countryRate = $this->countryTaxRates->findByCountry($companyCountry);
                    if ($countryRate !== null && $countryRate->getRegime() === 'normal_vat') {
                        return [
                            'regime'      => 'normal_vat',
                            'rate'        => $countryRate->getRate() ?? $globalRate,
                            'zero_reason' => '',
                        ];
                    }
                    return $this->buildOrderGlobalTaxPolicy($globalRate);
                }
                if ($fbRegime === 'normal_vat') {
                    return [
                        'regime'      => 'normal_vat',
                        'rate'        => $fallback->getRate() ?? $globalRate,
                        'zero_reason' => '',
                    ];
                }
                $zeroReason = trim($fallback->getZeroReason() ?? '');
                if ($zeroReason === '') {
                    $zeroReason = 'No tax applied (0%).';
                }
                return [
                    'regime'      => 'no_tax',
                    'rate'        => 0.0,
                    'zero_reason' => $zeroReason,
                ];
            }
        }

        return $this->buildOrderGlobalTaxPolicy($globalRate);
    }

    private function buildOrderGlobalTaxPolicy(float $globalRate): array
    {
        $regime = (string) ($this->settings->get('tax_regime', 'normal_vat') ?? 'normal_vat');
        if (in_array($regime, ['kleinunternehmer', 'vat_exempt'], true)) {
            $regime = 'no_tax';
        }
        if (!in_array($regime, ['normal_vat', 'no_tax'], true)) {
            $regime = 'normal_vat';
        }

        $rate = $regime === 'normal_vat' ? $globalRate : 0.0;

        $zeroReason = trim((string) ($this->settings->get('tax_zero_reason_default', '') ?? ''));
        if ($regime === 'no_tax' && $zeroReason === '') {
            $zeroReason = 'No tax applied (0%).';
        }

        return [
            'regime'      => $regime,
            'rate'        => $rate,
            'zero_reason' => $zeroReason,
        ];
    }

    /**
     * @param array<int, OrderLog> $logs
     * @return array<int,array{amount:float,currency:string,refund_id:?string,created_at:string,note:string}>
     */
    private function collectRefundLinesFromLogs(array $logs, string $currency): array
    {
        $lines = [];
        foreach ($logs as $log) {
            if ($log->getEvent() !== 'payment_refunded') {
                continue;
            }
            $note = (string) ($log->getNote() ?? '');
            if (preg_match('/Amount:\s*([0-9]+(?:\.[0-9]{1,2})?)\s+([A-Z]{3})/', $note, $amountMatch) !== 1) {
                continue;
            }

            $refundId = null;
            if (preg_match('/Refund ID:\s*([A-Za-z0-9_\-]+)/', $note, $refundIdMatch) === 1) {
                $refundId = $refundIdMatch[1];
            }

            $lineCurrency = strtoupper($amountMatch[2]);
            if ($lineCurrency !== strtoupper($currency)) {
                continue;
            }

            $lines[] = [
                'amount'     => (float) $amountMatch[1],
                'currency'   => $lineCurrency,
                'refund_id'  => $refundId,
                'created_at' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
                'note'       => $note,
            ];
        }

        return $lines;
    }

    /**
     * @return array{status:string,refund_id:string}
     */
    private function performGatewayRefund(Order $order, float $refundAmount, string $adminNote): array
    {
        $paymentMethod = (string) ($order->getPaymentMethod() ?? '');
        $txId          = trim((string) ($order->getTransactionId() ?? ''));
        if ($txId === '') {
            throw new \RuntimeException('Missing transaction ID on this order.');
        }

        $note     = $adminNote !== '' ? $adminNote : ('Refund approved for order #' . $order->getOrderNumber());
        $currency = (string) $order->getCurrency();

        return match ($paymentMethod) {
            'paypal' => $this->paypalGateway?->refundCapture($txId, $refundAmount, $currency, $note)
                ?? throw new \RuntimeException('PayPal gateway is not configured.'),
            'stripe' => str_starts_with($txId, 'sub_')
                ? ($this->stripeGateway?->refundBySubscription($txId, $refundAmount, $currency, $note)
                    ?? throw new \RuntimeException('Stripe gateway is not configured.'))
                : ($this->stripeGateway?->refundPaymentIntent($txId, $refundAmount, $currency, $note)
                    ?? throw new \RuntimeException('Stripe gateway is not configured.')),
            'mollie' => $this->mollieGateway?->refundPayment($txId, $refundAmount, $currency, $note)
                ?? throw new \RuntimeException('Mollie gateway is not configured.'),
            'twocheckout' => $this->twoCheckoutGateway?->refundOrder($txId, $refundAmount, $currency, $note)
                ?? throw new \RuntimeException('2Checkout gateway is not configured.'),
            'checkoutcom' => $this->checkoutComGateway?->refundPayment($txId, $refundAmount, $currency, $note)
                ?? throw new \RuntimeException('Checkout.com gateway is not configured.'),
            default => throw new \RuntimeException('Unsupported payment method for automatic refund.'),
        };
    }

    /** POST /admin/orders/{id}/downloads/{dlId}/revoke */
    public function revokeDownload(Request $request, Response $response, array $args): Response
    {
        if ($this->downloadRepo === null) {
            return $response->withStatus(503);
        }
        $body   = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? 'revoke');
        $this->downloadRepo->setRevoked((int) $args['dlId'], $action !== 'unrevoke');

        $this->session->flash('success', $action === 'unrevoke' ? 'Download link restored.' : 'Download link revoked.');
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /** POST /admin/orders/{id}/downloads/{dlId}/regenerate */
    public function regenerateDownloadToken(Request $request, Response $response, array $args): Response
    {
        if ($this->downloadRepo === null) {
            return $response->withStatus(503);
        }
        $this->downloadRepo->regenerateToken((int) $args['dlId']);

        $this->session->flash('success', 'Download token regenerated and count reset.');
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /** POST /admin/orders/{id}/downloads/{dlId}/update-meta */
    public function updateDownloadMeta(Request $request, Response $response, array $args): Response
    {
        if ($this->downloadRepo === null) {
            return $response->withStatus(503);
        }
        $body   = (array) $request->getParsedBody();
        $count  = max(0, (int) ($body['download_count'] ?? 0));
        $limit  = max(0, (int) ($body['download_limit'] ?? 0));
        $expiry = trim((string) ($body['expires_at'] ?? ''));
        $this->downloadRepo->updateDownloadMeta((int) $args['dlId'], $count, $limit, $expiry ?: null);

        $this->session->flash('success', $this->translator->get('Download meta updated.'));
        return $response->withHeader('Location', admin_url('/orders/' . $args['id']))->withStatus(302);
    }

    /**
     * Build cache key parts when a server-side search/filter is active.
     * Returns null for unfiltered views (handled client-side).
     *
     * @return array<string, mixed>|null
     */
    private function orderSearchKeyParts(array $filters, Pagination $pagination): ?array
    {
        $search = trim($filters['search'] ?? '');
        $status = $filters['status']      ?? '';
        $coupon = $filters['coupon_code'] ?? '';
        if ($search === '' && $status === '' && $coupon === '') {
            return null;
        }
        return [
            'search'   => $search,
            'status'   => $status,
            'coupon'   => $coupon,
            'sort'     => $pagination->sortBy  ?? '',
            'dir'      => $pagination->sortDir ?? 'desc',
            'page'     => $pagination->page,
            'perPage'  => $pagination->perPage,
        ];
    }
}
