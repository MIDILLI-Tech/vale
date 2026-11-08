<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Shop\AbandonedCartService;
use ValeApp\Application\Shop\BankTransferDetailsService;
use ValeApp\Application\Shop\CouponValidator;
use ValeApp\Application\Shop\DeliveryEstimateService;
use ValeApp\Application\Shop\OrderStatusEffects;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Entity\FormSubmission;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\OrderStatus;
use ValeApp\Domain\Shop\Port\PaymentGatewayInterface;
use ValeApp\Domain\Shop\Repository\CountryTaxRateRepositoryInterface;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Payment\Money;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;
use ValeApp\Infrastructure\Security\IpAnonymizer;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Checkout + payment flow controller.
 *
 * GET  /checkout          — show checkout form (cart must not be empty)
 * POST /checkout          — validate billing + create order + redirect to payment
 * GET  /checkout/payment  — Stripe JS embedded payment page
 * GET  /checkout/success  — post-payment success page
 * GET  /checkout/cancel   — user cancelled (PayPal flow)
 * POST /checkout/confirm  — AJAX: confirm Stripe payment intent succeeded
 */
final class CheckoutController
{
    use CaptchaTrait;

    public function __construct(
        private readonly Twig $twig,
        private readonly ProductRepositoryInterface $products,
        private readonly OrderRepositoryInterface $orders,
        private readonly SessionManager $session,
        private readonly DbalSettingsRepository $settings,
        private readonly FormRepositoryInterface $forms,
        private readonly CouponRepositoryInterface $coupons,
        private readonly CountryTaxRateRepositoryInterface $countryTaxRates,
        private readonly EventDispatcherInterface $events,
        private readonly OutboundWebhookService $webhookService,
        /** @var PaymentGatewayInterface[] */
        private readonly array $gateways = [],
        private readonly ?MailService $mail = null,
        private readonly ?OrderLogRepositoryInterface $orderLogs = null,
        private readonly ?ExchangeRateService $exchangeRates = null,
        private readonly ?SubscriberRepositoryInterface $subscriberRepo = null,
        private readonly ?AutomationService $automationService = null,
        private readonly ?DownloadRepositoryInterface $downloadRepo = null,
        private readonly ?Translator $translator = null,
        private readonly ?Connection $db = null,
        private readonly ?AbandonedCartService $abandonedCart = null,
        private readonly ?BankTransferDetailsService $bankTransferDetails = null,
        private readonly ?CouponValidator $couponValidator = null,
        private readonly ?DeliveryEstimateService $deliveryEstimate = null,
        private readonly ?Encrypter $encrypter = null,
    ) {
    }

    private ?float $cachedFxRate          = null;
    private ?string $activeBillingCountry = null;

    private function currentFxRate(): float
    {
        if ($this->cachedFxRate !== null) {
            return $this->cachedFxRate;
        }
        if ($this->exchangeRates === null) {
            return $this->cachedFxRate = 1.0;
        }
        $base               = strtoupper($this->settings->get('currency', 'USD') ?? 'USD');
        $target             = strtoupper($this->activeOrderCurrency());
        $this->cachedFxRate = $this->exchangeRates->getRate($base, $target);
        return $this->cachedFxRate;
    }

    /** GET /checkout */
    public function index(Request $request, Response $response): Response
    {
        $cartItems  = $this->session->get('cart', []);
        $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
        if (empty($cartItems)) {
            return $response->withHeader('Location', site_url($shopPrefix))->withStatus(302);
        }

        // Guest checkout gate
        $guestCheckout = $this->settings->get('guest_checkout_enabled', '1');
        if ($guestCheckout === '0' && $this->session->get('user_id') === null) {
            $checkoutPrefix = (string) ($this->settings->get('routing_checkout_prefix', '/checkout')
                ?? '/checkout');
            $this->session->set('redirect_after_login', site_url($checkoutPrefix));
            $message = $this->translator !== null
                ? $this->translator->get('Please log in to checkout.')
                : 'Please log in to checkout.';
            $this->session->flash('info', $message);
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        [$items, $subtotal]         = $this->resolveCartItems($cartItems);

        // Membership access control — a restricted product may have entered the
        // cart before the tag was assigned (or the subscription lapsed).
        // Purge it here so GET /checkout never renders a locked item.
        $restricted = $this->findRestrictedCartItems($items);
        if ($restricted !== []) {
            $this->removeCartItems(array_keys($restricted));
            $message = $this->translator !== null
                ? $this->translator->get('Some items in your cart require an active membership and were removed.')
                : 'Some items in your cart require an active membership and were removed.';
            $this->session->flash('error', $message);
            return $response->withHeader('Location', $this->cartUrl())->withStatus(302);
        }

        $hasPhysicalItems = false;
        foreach ($items as $item) {
            if (!($item['product']->isDigital())) {
                $hasPhysicalItems = true;
                break;
            }
        }

        $defaultCountry             = $this->detectCountryFromRequest($request);
        $this->activeBillingCountry = $defaultCountry;
        $shipping                   = $this->calculateShipping($items, $subtotal, $defaultCountry);
        $total                      = $subtotal + $shipping;
        $taxPolicy                  = $this->getTaxPolicy($defaultCountry);
        $taxAmount                  = $this->calculateIncludedTaxFromItems($items, $shipping, 0.0, $taxPolicy);
        $itemTaxAmount              = $this->calculateIncludedTaxFromItems($items, 0.0, 0.0, $taxPolicy);
        $subtotalExclTax            = max(0.0, $subtotal - $itemTaxAmount);
        $taxMixed                   = $this->hasMixedItemVatRates($items, $taxPolicy, $shipping);
        $taxDisplayRate             = $this->resolveCheckoutDisplayVatRate($items, $taxPolicy, $shipping);
        $checkoutForm               = $this->getSelectedCheckoutForm();
        $checkoutFields             = $this->filterCheckoutExtraFields($this->normalizeCheckoutFormFields($checkoutForm));
        $checkoutRequired           = $this->checkoutRequiredMap();
        $checkoutDisplay            = $this->checkoutDisplayMap();

        $hasDoNotShipItems = $defaultCountry && $this->hasDoNotShipItems($items, $defaultCountry);
        foreach ($items as &$item) {
            [$shClass, $shCost] = $defaultCountry
                ? $this->resolveEffectiveShipping($item, $defaultCountry)
                : ['', 0.0];
            $item['shipping_class'] = $shClass;
            $item['shipping_cost']  = $shCost;
            $item['can_ship']       = $shClass !== 'do_not_ship';
        }
        unset($item);

        $userAddress         = [];
        $userShippingAddress = [];
        if ($this->session->get('user_id') && $this->db !== null) {
            $row = $this->db->fetchAssociative(
                'SELECT email, billing_address, shipping_address FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
                [$this->session->get('user_id')]
            );
            if ($row) {
                $billing     = $this->decryptUserAddressFields(json_decode($row['billing_address'] ?? '', true) ?: []);
                $userAddress = [
                    'first_name' => $billing['first_name'] ?? $this->session->get('user_name', ''),
                    'last_name'  => $billing['last_name']  ?? '',
                    'email'      => $row['email']          ?? $this->session->get('user_email', ''),
                    'phone'      => $billing['phone']      ?? '',
                    'country'    => $billing['country']    ?? '',
                    'address'    => $billing['address_1']  ?? '',
                    'city'       => $billing['city']       ?? '',
                    'state'      => $billing['state']      ?? '',
                    'postcode'   => $billing['postcode']   ?? '',
                ];

                $shippingAddressData     = $this->decryptUserAddressFields(json_decode($row['shipping_address'] ?? '', true) ?: []);
                $userShippingAddress     = [
                    'first_name' => $shippingAddressData['first_name'] ?? '',
                    'last_name'  => $shippingAddressData['last_name']  ?? '',
                    'company'    => $shippingAddressData['company']    ?? '',
                    'address'    => $shippingAddressData['address_1']  ?? '',
                    'address_2'  => $shippingAddressData['address_2']  ?? '',
                    'city'       => $shippingAddressData['city']       ?? '',
                    'state'      => $shippingAddressData['state']      ?? '',
                    'postcode'   => $shippingAddressData['postcode']   ?? '',
                    'country'    => $shippingAddressData['country']    ?? '',
                    'phone'      => $shippingAddressData['phone']      ?? '',
                ];
            }
        }

        // Track abandoned cart
        $userId = $this->session->get('user_id');
        $email  = (string) ($this->session->get('user_email') ?? '');
        if ($email !== '' && $this->abandonedCart !== null) {
            $currency = $this->activeOrderCurrency();
            $locale   = $this->translator?->getLocale() ?? 'en';
            $this->abandonedCart->touchCart($userId, $email, json_encode($cartItems), $subtotal, $currency, $locale);
        }

        // Warn if saved shipping country is not shippable.
        // When shipping is disabled and the cart has physical items, shipping
        // rules don't apply at all — the "Shipping is currently unavailable"
        // message is the only relevant one. Checking the saved country against
        // an empty shipping zone would show a misleading error.
        $savedAddressError            = '';
        $shippingDisabledWithPhysical = $hasPhysicalItems && $this->settings->get('shipping_enabled', '0') !== '1';
        if ($userId !== null && !$shippingDisabledWithPhysical) {
            $savedCountry = '';
            if (!empty($userShippingAddress['country'])) {
                $savedCountry = strtoupper(trim($userShippingAddress['country']));
            } elseif (!empty($userAddress['country'])) {
                $savedCountry = strtoupper(trim($userAddress['country']));
            }
            if ($savedCountry !== '') {
                $allowedCountries = $this->resolveCheckoutCountries($items, true);
                if (!array_key_exists($savedCountry, $allowedCountries)) {
                    if ($hasPhysicalItems) {
                        $savedAddressError = $this->translator->get('Your saved address country is not in our shipping zone. Please update your address or choose a different country below.');
                    } else {
                        $savedAddressError = $this->translator->get('We do not sell to your country. Please update your address or choose a different country below.');
                    }
                } elseif ($this->settings->get('shipping_enabled', '0') === '1' && $hasPhysicalItems && $this->hasDoNotShipItems($items, $savedCountry)) {
                    $blockedNames = [];
                    foreach ($items as $item) {
                        if (!($item['product']->isDigital())) {
                            [$shClass] = $this->resolveEffectiveShipping($item, $savedCountry);
                            if ($shClass === 'do_not_ship') {
                                $blockedNames[] = $item['product']->getName();
                            }
                        }
                    }
                    if ($blockedNames !== []) {
                        $savedAddressError = $this->translator->get('The following item(s) cannot be shipped to your saved address country (:items).', [
                            ':items' => implode(', ', $blockedNames),
                        ]);
                    } else {
                        $savedAddressError = $this->translator->get('Some items in your cart cannot be shipped to your saved address country.');
                    }
                }
            }
        }

        $shippingEnabled     = $this->settings->get('shipping_enabled', '0') === '1';
        $shippingError       = '';
        if ($hasPhysicalItems && !$shippingEnabled) {
            $shippingError = $this->translator ? $this->translator->get('Shipping is currently unavailable.') : 'Shipping is currently unavailable.';
        } elseif ($hasDoNotShipItems) {
            $shippingError = $this->translator ? $this->translator->get('Some items cannot be shipped to selected country.') : 'Some items cannot be shipped to selected country.';
        }
        if ($savedAddressError !== '' && $hasPhysicalItems) {
            $shippingError .= ($shippingError !== '' ? ' ' : '') . $savedAddressError;
        }

        $stripeEnabled       = (bool) $this->settings->get('stripe_enabled', '0');
        $paypalEnabled       = (bool) $this->settings->get('paypal_enabled', '0');
        $mollieEnabled       = (bool) $this->settings->get('mollie_enabled', '0');
        $twocheckoutEnabled  = (bool) $this->settings->get('twocheckout_enabled', '0');
        $checkoutcomEnabled  = (bool) $this->settings->get('checkoutcom_enabled', '0');
        $banktransferEnabled = (bool) $this->settings->get('banktransfer_enabled', '0');
        $stripeKey           = $this->settings->get('stripe_publishable_key', '');

        $stripeLabel      = $this->settings->get('stripe_label', 'Credit / Debit Card');
        $stripeVia        = 'Stripe';
        $paypalLabel      = $this->settings->get('paypal_label', 'PayPal');
        $paypalVia        = 'PayPal';
        $mollieLabel      = $this->settings->get('mollie_label', 'Credit / Debit Card');
        $mollieVia        = 'Mollie';
        $twocheckoutLabel = $this->settings->get('twocheckout_label', 'Credit / Debit Card');
        $twocheckoutVia   = '2Checkout';
        $checkoutcomLabel = $this->settings->get('checkoutcom_label', 'Credit / Debit Card');
        $checkoutcomVia   = 'Checkout.com';

        $banktransferLabel   = $this->settings->get('banktransfer_label', 'Bank Transfer / EFT');
        $banktransferBank    = $this->settings->get('banktransfer_bank_name', '');
        $banktransferIban    = $this->settings->get('banktransfer_iban', '');
        $banktransferHolder  = $this->settings->get('banktransfer_holder_name', '');
        $banktransferSwift   = $this->settings->get('banktransfer_swift', '');
        $banktransferRouting = $this->settings->get('banktransfer_routing', '');
        $banktransferBranch  = $this->settings->get('banktransfer_branch', '');
        $banktransferDesc    = $this->settings->get('banktransfer_description', '');
        $banktransferMaxDays = (int) ($this->settings->get('banktransfer_max_days', '14') ?? '14');

        // Pick default selection: if only one gateway is active, select it
        $activeCount = (int) $stripeEnabled + (int) $paypalEnabled + (int) $mollieEnabled + (int) $twocheckoutEnabled + (int) $checkoutcomEnabled + (int) $banktransferEnabled;
        if ($activeCount === 1) {
            $activeGateway = $stripeEnabled ? 'stripe' : ($paypalEnabled ? 'paypal' : ($mollieEnabled ? 'mollie' : ($twocheckoutEnabled ? 'twocheckout' : ($checkoutcomEnabled ? 'checkoutcom' : 'banktransfer'))));
        } else {
            $activeGateway = $this->settings->get('payment_active_gateway', 'stripe');
        }

        $shippingEstimate = $this->deliveryEstimate?->estimateForOrder($items, $defaultCountry) ?? ['min' => null, 'max' => null];

        // Country dropdowns: show the allowed set PLUS the user's saved country so a
        // previously-used country (now disallowed) remains visible/selectable. The
        // shippable set (shipping_countries_list) is what Place Order is validated against.
        $allCountries          = $this->getCountries();
        $userBillingCountry    = $userAddress['country']         ?? '';
        $userShippingCountry   = $userShippingAddress['country'] ?? '';
        $billingCountries      = $this->mergeUserCountry($allCountries, $this->resolveCheckoutCountries($items, false), $userBillingCountry);
        $shippingOptions       = $this->mergeUserCountry($allCountries, $this->resolveCheckoutCountries($items, true), $userShippingCountry !== '' ? $userShippingCountry : $userBillingCountry);

        return $this->twig->render($response, 'shop/checkout.twig', [
            'items'                    => $items,
            'checkout_base_url'        => $this->checkoutUrl(''),
            'subtotal'                 => $subtotal,
            'shipping'                 => $shipping,
            'total'                    => $total,
            'shipping_estimate'        => $shippingEstimate,
            'active_gateway'           => $activeGateway,
            'stripe_enabled'           => $stripeEnabled,
            'paypal_enabled'           => $paypalEnabled,
            'mollie_enabled'           => $mollieEnabled,
            'twocheckout_enabled'      => $twocheckoutEnabled,
            'checkoutcom_enabled'      => $checkoutcomEnabled,
            'banktransfer_enabled'     => $banktransferEnabled,
            'stripe_key'               => $stripeKey,
            'stripe_label'             => $stripeLabel,
            'stripe_via'               => $stripeVia,
            'paypal_label'             => $paypalLabel,
            'paypal_via'               => $paypalVia,
            'mollie_label'             => $mollieLabel,
            'mollie_via'               => $mollieVia,
            'twocheckout_label'        => $twocheckoutLabel,
            'twocheckout_via'          => $twocheckoutVia,
            'checkoutcom_label'        => $checkoutcomLabel,
            'checkoutcom_via'          => $checkoutcomVia,
            'banktransfer_label'       => $banktransferLabel,
            'banktransfer_bank'        => $banktransferBank,
            'banktransfer_iban'        => $banktransferIban,
            'banktransfer_holder'      => $banktransferHolder,
            'banktransfer_swift'       => $banktransferSwift,
            'banktransfer_routing'     => $banktransferRouting,
            'banktransfer_branch'      => $banktransferBranch,
            'banktransfer_desc'        => $banktransferDesc,
            'banktransfer_max_days'    => $banktransferMaxDays,
            'countries'                => $billingCountries,
            'shipping_country_options' => $shippingOptions,
            'shipping_countries_list'  => $this->resolveCheckoutCountries($items, true),
            'default_country'          => $defaultCountry,
            'policy_links'             => $this->policyLinks(),
            'checkout_display'         => $checkoutDisplay,
            'checkout_required'        => $checkoutRequired,
            'checkout_form'            => $checkoutForm,
            'checkout_fields'          => $checkoutFields,
            'checkout_form_missing'    => $checkoutForm === null,
            'tax_policy'               => $taxPolicy,
            'tax_amount'               => $taxAmount,
            'subtotal_excl_tax'        => $subtotalExclTax,
            'tax_mixed'                => $taxMixed,
            'tax_display_rate'         => $taxDisplayRate,
            'shipping_enabled'         => $shippingEnabled,
            'shipping_error'           => $shippingError,
            'saved_address_error'      => $savedAddressError,
            'user_address'             => $userAddress,
            'user_shipping_address'    => $userShippingAddress,
            'has_physical_items'       => $hasPhysicalItems,
        ]);
    }

    /** POST /checkout — create the order, redirect to payment */
    public function process(Request $request, Response $response): Response
    {
        $cartItems  = $this->session->get('cart', []);
        $shopPrefix = $this->settings->get('routing_shop_prefix', '/shop');
        if (empty($cartItems)) {
            return $response->withHeader('Location', site_url($shopPrefix))->withStatus(302);
        }

        [$items, $subtotal] = $this->resolveCartItems($cartItems);

        // Membership access control — same guard as GET /checkout, so a direct
        // POST can never create an order for a restricted product.
        $restricted = $this->findRestrictedCartItems($items);
        if ($restricted !== []) {
            $this->removeCartItems(array_keys($restricted));
            $message = $this->translator !== null
                ? $this->translator->get('Some items in your cart require an active membership and were removed.')
                : 'Some items in your cart require an active membership and were removed.';
            $this->session->flash('error', $message);
            return $response->withHeader('Location', $this->cartUrl())->withStatus(302);
        }

        $hasPhysicalItems = false;
        foreach ($items as $item) {
            if (!($item['product']->isDigital())) {
                $hasPhysicalItems = true;
                break;
            }
        }

        $body             = (array) $request->getParsedBody();
        $checkoutForm     = $this->getSelectedCheckoutForm();
        $checkoutFields   = $this->filterCheckoutExtraFields($this->normalizeCheckoutFormFields($checkoutForm));
        $checkoutRequired = $this->checkoutRequiredMap();
        $checkoutDisplay  = $this->checkoutDisplayMap();
        $body             = $this->normalizeCheckoutSubmission($body, $checkoutFields);
        $errors           = $this->validateBilling($body, $checkoutFields);

        // Validate shipping availability & country restriction
        $shippingEnabled = $this->settings->get('shipping_enabled', '0') === '1';
        $sameAsBilling   = !empty($body['same_as_billing']);
        $billingCountry  = strtoupper(trim((string) ($body['country'] ?? '')));
        $shippingCountry = $sameAsBilling
            ? $billingCountry
            : strtoupper(trim((string) ($body['shipping_country'] ?? $billingCountry)));

        if ($hasPhysicalItems && !$shippingEnabled) {
            $errors['country'] = $this->translator ? $this->translator->get('Shipping is currently unavailable.') : 'Shipping is currently unavailable.';
        } elseif ($shippingEnabled) {
            $allowedCountries = $this->resolveCheckoutCountries($items, true);
            $errKey           = $sameAsBilling ? 'country' : 'shipping_country';
            $shippingAllowed  = $shippingCountry === ''
                || array_key_exists($shippingCountry, $allowedCountries);
            $shippable        = $shippingCountry === ''
                || !$this->hasDoNotShipItems($items, $shippingCountry);
            if (!$shippingAllowed) {
                $errors[$errKey] = $this->translator->get(
                    'We do not sell to your country you selected.'
                );
            } elseif (!$shippable) {
                $errors[$errKey] = $this->translator->get(
                    'Some items cannot be shipped to selected country.'
                );
            }
        }

        if (!empty($errors)) {
            $userAddress         = [];
            $userShippingAddress = [];
            if ($this->session->get('user_id') && $this->db !== null) {
                $row = $this->db->fetchAssociative(
                    'SELECT email, billing_address, shipping_address FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
                    [$this->session->get('user_id')]
                );
                if ($row) {
                    $billing     = $this->decryptUserAddressFields(json_decode($row['billing_address'] ?? '', true) ?: []);
                    $userAddress = [
                        'first_name' => $billing['first_name'] ?? $this->session->get('user_name', ''),
                        'last_name'  => $billing['last_name']  ?? '',
                        'email'      => $row['email']          ?? $this->session->get('user_email', ''),
                        'phone'      => $billing['phone']      ?? '',
                        'country'    => $billing['country']    ?? '',
                        'address'    => $billing['address_1']  ?? '',
                        'city'       => $billing['city']       ?? '',
                        'state'      => $billing['state']      ?? '',
                        'postcode'   => $billing['postcode']   ?? '',
                    ];
                    $shippingAddressData     = $this->decryptUserAddressFields(json_decode($row['shipping_address'] ?? '', true) ?: []);
                    $userShippingAddress     = [
                        'first_name' => $shippingAddressData['first_name'] ?? '',
                        'last_name'  => $shippingAddressData['last_name']  ?? '',
                        'company'    => $shippingAddressData['company']    ?? '',
                        'address'    => $shippingAddressData['address_1']  ?? '',
                        'address_2'  => $shippingAddressData['address_2']  ?? '',
                        'city'       => $shippingAddressData['city']       ?? '',
                        'state'      => $shippingAddressData['state']      ?? '',
                        'postcode'   => $shippingAddressData['postcode']   ?? '',
                        'country'    => $shippingAddressData['country']    ?? '',
                        'phone'      => $shippingAddressData['phone']      ?? '',
                    ];
                }
            }

            $billingCountry  = strtoupper(trim((string) ($body['country'] ?? '')));
            $shippingCountry = $billingCountry;
            $sameAsBilling   = !empty($body['same_as_billing']);
            if (!$sameAsBilling) {
                $shippingCountry = strtoupper(trim((string) ($body['shipping_country'] ?? $shippingCountry)));
            }
            $this->activeBillingCountry = $billingCountry !== '' ? $billingCountry : null;

            // hasDoNotShipItems() already returns false for an empty country.
            $hasDNS        = $this->hasDoNotShipItems($items, $shippingCountry);
            $shippingError = '';
            if ($hasPhysicalItems && !$shippingEnabled) {
                $shippingError = $this->translator ? $this->translator->get('Shipping is currently unavailable.') : 'Shipping is currently unavailable.';
            } elseif ($hasDNS) {
                $shippingError = $this->translator ? $this->translator->get('Some items cannot be shipped to selected country.') : 'Some items cannot be shipped to selected country.';
            }
            foreach ($items as &$item) {
                [$shClass, $shCost] = $shippingCountry !== ''
                    ? $this->resolveEffectiveShipping($item, $shippingCountry)
                    : ['', 0.0];
                $item['shipping_class'] = $shClass;
                $item['shipping_cost']  = $shCost;
            }
            unset($item);

            $shipping        = $this->calculateShipping($items, $subtotal, $shippingCountry !== '' ? $shippingCountry : null);
            $taxPolicy       = $this->getTaxPolicy($this->activeBillingCountry);
            $taxAmount       = $this->calculateIncludedTaxFromItems($items, $shipping, 0.0, $taxPolicy);
            $itemTaxAmount   = $this->calculateIncludedTaxFromItems($items, 0.0, 0.0, $taxPolicy);
            $subtotalExclTax = max(0.0, $subtotal - $itemTaxAmount);
            $taxMixed        = $this->hasMixedItemVatRates($items, $taxPolicy, $shipping);
            $taxDisplayRate  = $this->resolveCheckoutDisplayVatRate($items, $taxPolicy, $shipping);
            $gateways        = [
                'stripe'       => (bool) $this->settings->get('stripe_enabled', '0'),
                'paypal'       => (bool) $this->settings->get('paypal_enabled', '0'),
                'mollie'       => (bool) $this->settings->get('mollie_enabled', '0'),
                'twocheckout'  => (bool) $this->settings->get('twocheckout_enabled', '0'),
                'checkoutcom'  => (bool) $this->settings->get('checkoutcom_enabled', '0'),
                'banktransfer' => (bool) $this->settings->get('banktransfer_enabled', '0'),
            ];
            $activeC = count(array_filter($gateways));

            // Preserve the gateway the user actually selected when the form
            // is re-rendered after a validation error.
            $submitted = (string) ($body['payment_method'] ?? '');
            if ($submitted !== '' && ($gateways[$submitted] ?? false)) {
                $ag = $submitted;
            } elseif ($activeC === 1) {
                $ag = array_keys(array_filter($gateways))[0];
            } else {
                $ag = $this->settings->get('payment_active_gateway', 'stripe');
            }
            // Normalize checkbox semantics for re-render: an unchecked box is
            // absent from POST, a fresh visit has no old data at all.
            $body['same_as_billing'] = !empty($body['same_as_billing']) ? 1 : 0;

            $billingCountry   = strtoupper(trim($body['country'] ?? ''));
            $shippingEstimate = $this->deliveryEstimate?->estimateForOrder($items, $billingCountry !== '' ? $billingCountry : null) ?? ['min' => null, 'max' => null];

            return $this->twig->render($response->withStatus(422), 'shop/checkout.twig', [
                'items'                 => $items,
                'checkout_base_url'     => $this->checkoutUrl(''),
                'subtotal'              => $subtotal,
                'shipping'              => $shipping,
                'total'                 => $subtotal + $shipping,
                'shipping_estimate'     => $shippingEstimate,
                'errors'                => $errors,
                'old'                   => $body,
                'active_gateway'        => $ag,
                'stripe_enabled'        => $gateways['stripe'],
                'paypal_enabled'        => $gateways['paypal'],
                'mollie_enabled'        => $gateways['mollie'],
                'twocheckout_enabled'   => $gateways['twocheckout'],
                'checkoutcom_enabled'   => $gateways['checkoutcom'],
                'banktransfer_enabled'  => $gateways['banktransfer'],
                'stripe_key'            => $this->settings->get('stripe_publishable_key', ''),
                'stripe_label'          => $this->settings->get('stripe_label', 'Credit / Debit Card'),
                'stripe_via'            => 'Stripe',
                'paypal_label'          => $this->settings->get('paypal_label', 'PayPal'),
                'paypal_via'            => 'PayPal',
                'mollie_label'          => $this->settings->get('mollie_label', 'Credit / Debit Card'),
                'mollie_via'            => 'Mollie',
                'twocheckout_label'     => $this->settings->get('twocheckout_label', 'Credit / Debit Card'),
                'twocheckout_via'       => '2Checkout',
                'checkoutcom_label'     => $this->settings->get('checkoutcom_label', 'Credit / Debit Card'),
                'checkoutcom_via'       => 'Checkout.com',
                'banktransfer_label'    => $this->settings->get('banktransfer_label', 'Bank Transfer / EFT'),
                'banktransfer_bank'     => $this->settings->get('banktransfer_bank_name', ''),
                'banktransfer_iban'     => $this->settings->get('banktransfer_iban', ''),
                'banktransfer_holder'   => $this->settings->get('banktransfer_holder_name', ''),
                'banktransfer_swift'    => $this->settings->get('banktransfer_swift', ''),
                'banktransfer_routing'  => $this->settings->get('banktransfer_routing', ''),
                'banktransfer_branch'   => $this->settings->get('banktransfer_branch', ''),
                'banktransfer_desc'     => $this->settings->get('banktransfer_description', ''),
                'banktransfer_max_days' => (int) ($this->settings->get('banktransfer_max_days', '14') ?? '14'),
                'user_address'          => $userAddress,
            'countries'                 => $this->mergeUserCountry($this->getCountries(), $this->resolveCheckoutCountries($items, false), $billingCountry),
            'shipping_country_options'  => $this->mergeUserCountry($this->getCountries(), $this->resolveCheckoutCountries($items, true), $shippingCountry),
            'shipping_countries_list'   => $this->resolveCheckoutCountries($items, true),
                'policy_links'          => $this->policyLinks(),
                'checkout_display'      => $checkoutDisplay,
                'checkout_required'     => $checkoutRequired,
                'checkout_form'         => $checkoutForm,
                'checkout_fields'       => $checkoutFields,
                'checkout_form_missing' => $checkoutForm === null,
                'tax_policy'            => $taxPolicy,
                'tax_amount'            => $taxAmount,
                'subtotal_excl_tax'     => $subtotalExclTax,
                'tax_mixed'             => $taxMixed,
                'tax_display_rate'      => $taxDisplayRate,
                'shipping_enabled'      => $shippingEnabled,
                'shipping_error'        => $shippingError,
                'saved_address_error'   => '',
                'user_shipping_address' => $userShippingAddress,
                'has_physical_items'    => $hasPhysicalItems,
            ]);
        }

        // Build order

        // Re-validate stock at order time (race condition protection)
        foreach ($items as $item) {
            /** @var Product $prod */
            $prod        = $item['product'];
            $qty         = (int) $item['qty'];
            $variationId = !empty($item['variation_id']) ? (int) $item['variation_id'] : 0;
            if (!$prod->isInStock()) {
                $this->session->set('flash_error', $this->translator->get('":name" is out of stock. Please remove it from your cart before proceeding.', [':name' => htmlspecialchars($prod->getName(), ENT_QUOTES, 'UTF-8')]));
                return $response->withHeader('Location', $this->cartUrl())->withStatus(302);
            }

            if ($prod->isExpired()) {
                $this->session->set('flash_error', $this->translator->get('":name" is no longer available. Please remove it from your cart before proceeding.', [':name' => htmlspecialchars($prod->getName(), ENT_QUOTES, 'UTF-8')]));
                return $response->withHeader('Location', $this->cartUrl())->withStatus(302);
            }

            $variationRow = null;
            if ($variationId > 0) {
                $variationRow = $this->findVariationRow($prod->getId(), $variationId);
                if ($variationRow === null || !$this->isVariationPurchasable($variationRow)) {
                    $this->session->set('flash_error', $this->translator->get('A selected variation for ":name" is out of stock. Please update your cart.', [':name' => htmlspecialchars($prod->getName(), ENT_QUOTES, 'UTF-8')]));
                    return $response->withHeader('Location', $this->cartUrl())->withStatus(302);
                }
            }

            $available = $this->resolveEffectiveMaxQty($prod, $variationRow);
            if ($available !== null && $qty > $available) {
                $this->session->set('flash_error', $this->translator->get('":name" only has :count unit(s) available. Please update your cart.', [':name' => htmlspecialchars($prod->getName(), ENT_QUOTES, 'UTF-8'), ':count' => (string) $available]));
                return $response->withHeader('Location', $this->cartUrl())->withStatus(302);
            }
        }

        $billingCountryInput = strtoupper(trim((string) ($body['country'] ?? '')));

        $hasPhysicalItems = false;
        foreach ($items as $item) {
            if (!($item['product']->isDigital())) {
                $hasPhysicalItems = true;
                break;
            }
        }
        $sameAsBilling = !empty($body['same_as_billing']);

        $shippingCountry = $billingCountryInput;
        if (!$sameAsBilling) {
            $shippingCountry = strtoupper(trim((string) ($body['shipping_country'] ?? $shippingCountry)));
        }
        if ($hasPhysicalItems && !$shippingEnabled) {
            $this->session->set('flash_error', $this->translator ? $this->translator->get('Shipping is currently unavailable.') : 'Shipping is currently unavailable.');
            return $response->withHeader('Location', $this->checkoutUrl())->withStatus(302);
        }

        if ($shippingCountry !== '' && $this->hasDoNotShipItems($items, $shippingCountry)) {
            $blockedNames = [];
            foreach ($items as $item) {
                if (!($item['product']->isDigital())) {
                    [$shClass] = $this->resolveEffectiveShipping($item, $shippingCountry);
                    if ($shClass === 'do_not_ship') {
                        $blockedNames[] = $item['product']->getName();
                    }
                }
            }
            if ($blockedNames !== []) {
                $this->session->set('flash_error', $this->translator->get('The following item(s) cannot be shipped to selected country (:items).', [
                    ':items' => implode(', ', $blockedNames),
                ]));
            } else {
                $this->session->set('flash_error', $this->translator->get('Some items cannot be shipped to selected country.'));
            }
            return $response->withHeader('Location', $this->checkoutUrl())->withStatus(302);
        }
        $shipping = $this->calculateShipping($items, $subtotal, $shippingCountry !== '' ? $shippingCountry : null);
        $total    = $subtotal + $shipping;
        $billing  = [
            'first_name'   => trim((string) ($body['first_name'] ?? '')),
            'last_name'    => trim((string) ($body['last_name'] ?? '')),
            'email'        => trim((string) ($body['email'] ?? '')),
            'phone'        => trim((string) ($body['phone'] ?? '')),
            'address'      => trim((string) ($body['address'] ?? '')),
            'city'         => trim((string) ($body['city'] ?? '')),
            'state'        => trim((string) ($body['state'] ?? '')),
            'postcode'     => trim((string) ($body['postcode'] ?? '')),
            'country'      => trim((string) ($body['country'] ?? 'US')),
            'extra_fields' => $this->extractCheckoutExtraFields($body, $checkoutFields),
        ];

        $order = new Order($this->orders->generateOrderNumber(), $billing, $this->session->get('user_id'));
        $order->setLocale($this->translator?->getLocale() ?? 'en');
        $this->session->set('last_billing_country', strtoupper(trim((string) ($billing['country'] ?? ''))));

        // Shipping address
        if (!$sameAsBilling) {
            $order->setShippingAddress([
                'first_name' => trim((string) ($body['shipping_first_name'] ?? $billing['first_name'])),
                'last_name'  => trim((string) ($body['shipping_last_name'] ?? $billing['last_name'])),
                'company'    => trim((string) ($body['shipping_company'] ?? '')),
                'address'    => trim((string) ($body['shipping_address'] ?? $billing['address'])),
                'address_2'  => trim((string) ($body['shipping_address_2'] ?? '')),
                'city'       => trim((string) ($body['shipping_city'] ?? $billing['city'])),
                'state'      => trim((string) ($body['shipping_state'] ?? $billing['state'])),
                'postcode'   => trim((string) ($body['shipping_postcode'] ?? $billing['postcode'])),
                'country'    => strtoupper(trim((string) ($body['shipping_country'] ?? $billing['country']))),
                'phone'      => trim((string) ($body['shipping_phone'] ?? $billing['phone'])),
            ]);
        } elseif ($hasPhysicalItems) {
            $order->setShippingAddress($billing);
        }
        $order->setSubtotal($subtotal);
        $order->setShipping($shipping);
        $order->setCurrency($this->activeOrderCurrency());
        $order->setCustomerNote(!empty($body['note']) ? trim($body['note']) : null);

        // Coupon
        $couponCode    = strtoupper(trim($body['coupon_code'] ?? ''));
        $billingEmail  = strtolower(trim($body['email'] ?? ''));
        $discount      = 0.0;
        $discountLines = null;
        if ($couponCode !== '') {
            $cartProductIds  = array_map(static fn ($i) => (int) $i['product']->getId(), $items);
            $cartCategoryIds = [];
            foreach ($items as $item) {
                foreach ($item['product']->getCategoryIds() as $cid) {
                    $cartCategoryIds[] = (int) $cid;
                }
            }
            $cartCategoryIds  = array_values(array_unique($cartCategoryIds));
            $cartVariationIds = array_values(array_filter(array_map(static fn ($i) => (int) ($i['variation_id'] ?? 0), $items)));

            $result = $this->validateCoupon($couponCode, $subtotal, $billingEmail, $cartProductIds, $cartCategoryIds, $cartVariationIds);
            if (!$result['valid']) {
                $this->session->set('flash_error', $this->translator->get($result['error']));
                return $response->withHeader('Location', $this->checkoutUrl())->withStatus(302);
            }

            $coupon = $result['coupon'];
            if (!$this->coupons->incrementUsesIfAvailable((int) $coupon->getId())) {
                $this->session->set(
                    'flash_error',
                    $this->translator->get('You have already reached the maximum usage limit for this coupon.')
                );
                return $response->withHeader('Location', $this->checkoutUrl())->withStatus(302);
            }

            $scoped        = $this->couponDiscountForItems($coupon, $items);
            $discount      = $scoped['discount'];
            $discountLines = $scoped['lines'];
            $order->setCouponCode($coupon->getCode());
            if ($coupon->hasFreeShipping()) {
                $shipping = 0.0;
            }
        }
        $order->setShipping($shipping);
        $order->setDiscount(round($discount, 2));
        $this->activeBillingCountry = $billingCountryInput !== '' ? $billingCountryInput : null;
        $taxPolicy                  = $this->getTaxPolicy($this->activeBillingCountry);
        $taxBreakdown               = $this->calculateIncludedTaxBreakdown($items, $shipping, $discount, $taxPolicy, $discountLines);
        $order->setTax($taxBreakdown['total']);
        $total = max(0.0, $subtotal + $shipping - $discount);
        $order->setTotal($total);

        $orderItems = [];
        foreach ($items as $index => $item) {
            $variationSku = trim((string) ($item['variation_row']['sku'] ?? ''));
            $orderItem    = [
                'product_id' => $item['product']->getId(),
                'name'       => $item['product']->getName(),
                'sku'        => $variationSku !== '' ? $variationSku : ($item['product']->getSku() ?? ''),
                'price'      => $item['price'],
                'qty'        => $item['qty'],
                'subtotal'   => $item['price'] * $item['qty'],
            ];
            $lineTax = $taxBreakdown['lines'][$index] ?? null;
            if ($lineTax !== null) {
                $orderItem['tax_rate']   = $lineTax['rate'];
                $orderItem['tax_amount'] = $lineTax['amount'];
            }
            if (!empty($item['variation_id'])) {
                $orderItem['variation_id'] = $item['variation_id'];
            }
            // Store merged variation + customization data so the order record is self-contained
            $mergedAttrs = array_merge($item['variation'] ?? [], $item['custom_attrs'] ?? []);
            if (!empty($mergedAttrs)) {
                $orderItem['variation_data'] = json_encode($mergedAttrs);
            }
            $orderItems[] = $orderItem;
        }
        $order->setItems($orderItems);
        $this->orders->save($order);

        // Mark abandoned cart as recovered — members by user id, guests by
        // billing email (guest carts have no user_id).
        if ($this->abandonedCart !== null) {
            $userId = $this->session->get('user_id');
            if ($userId !== null) {
                $this->abandonedCart->markRecovered($userId);
            }

            $billingEmail = trim((string) ($billing['email'] ?? ''));
            if ($billingEmail !== '') {
                $this->abandonedCart->markRecoveredByEmail($billingEmail);
            }
        }

        // Save addresses to user profile
        $saveAddresses = !empty($body['save_addresses']) && $this->session->get('user_id') && $this->db !== null;
        if ($saveAddresses) {
            $profileBilling = [
                'first_name' => $billing['first_name'] ?? '',
                'last_name'  => $billing['last_name']  ?? '',
                'company'    => '',
                'phone'      => $billing['phone']   ?? '',
                'address_1'  => $billing['address'] ?? '',
                'address_2'  => '',
                'city'       => $billing['city']     ?? '',
                'state'      => $billing['state']    ?? '',
                'postcode'   => $billing['postcode'] ?? '',
                'country'    => $billing['country']  ?? '',
            ];
            $profileShipping = [];
            if (!$sameAsBilling) {
                $profileShipping = [
                    'first_name' => trim((string) ($body['shipping_first_name'] ?? '')),
                    'last_name'  => trim((string) ($body['shipping_last_name'] ?? '')),
                    'company'    => trim((string) ($body['shipping_company'] ?? '')),
                    'phone'      => trim((string) ($body['shipping_phone'] ?? '')),
                    'address_1'  => trim((string) ($body['shipping_address'] ?? '')),
                    'address_2'  => trim((string) ($body['shipping_address_2'] ?? '')),
                    'city'       => trim((string) ($body['shipping_city'] ?? '')),
                    'state'      => trim((string) ($body['shipping_state'] ?? '')),
                    'postcode'   => trim((string) ($body['shipping_postcode'] ?? '')),
                    'country'    => strtoupper(trim((string) ($body['shipping_country'] ?? ''))),
                ];
            }
            $this->db->update(VL_DB_PREFIX . 'users', [
                'billing_address'  => json_encode($this->encryptUserAddressFields($profileBilling)),
                'shipping_address' => json_encode($this->encryptUserAddressFields($profileShipping)),
            ], ['id' => $this->session->get('user_id')]);
        }

        // Persist selected checkout form data as a real form submission for admin reporting.
        $this->persistCheckoutFormSubmission($request, $order, $checkoutForm, $body, $checkoutFields);

        // Only allow gateways that are currently enabled — a stale form or a
        // hand-crafted POST must not resurrect a disabled provider.
        $enabledGateways = [
            'stripe'       => $this->settings->bool('stripe_enabled', false),
            'paypal'       => $this->settings->bool('paypal_enabled', false),
            'mollie'       => $this->settings->bool('mollie_enabled', false),
            'twocheckout'  => $this->settings->bool('twocheckout_enabled', false),
            'checkoutcom'  => $this->settings->bool('checkoutcom_enabled', false),
            'banktransfer' => $this->settings->bool('banktransfer_enabled', false),
        ];
        $requestedGateway = (string) ($body['payment_method'] ?? '');
        if ($requestedGateway !== '' && ($enabledGateways[$requestedGateway] ?? false)) {
            $gateway = $requestedGateway;
        } else {
            $defaultGateway = (string) $this->settings->get('payment_active_gateway', 'stripe');
            $gateway        = ($enabledGateways[$defaultGateway] ?? false) ? $defaultGateway : $requestedGateway;
        }
        $order->setPaymentMethod($gateway);
        $this->orders->save($order);
        $this->orderLogs?->add(
            $order->getId(),
            'order_created',
            'Order placed via checkout. Awaiting payment via ' . $gateway . '.'
        );

        $this->session->set('pending_order_id', $order->getId());
        $this->session->set('pending_order_number', $order->getOrderNumber());

        // Free order ($0) — skip payment, complete immediately
        if ($total <= 0) {
            $status = $order->isAllItemsDigital($this->products) ? 'completed' : 'processing';
            $order->setStatus($status);
            $order->setPaidAt(new \DateTimeImmutable());
            $order->setPaymentMethod('free');
            $this->orders->save($order);
            $this->orderLogs?->add(
                $order->getId(),
                'payment_received',
                $this->translator->get('No payment required — order total was zero.')
            );
            $this->events->doAction('order.paid', $order);
            $this->sendOrderConfirmationEmail($order);
            $this->session->set('cart', []);
            return $response->withHeader('Location', $this->checkoutUrl('/success', ['order' => $order->getOrderNumber()]))->withStatus(302);
        }

        // Bank Transfer — manual payment, no gateway
        if ($gateway === 'banktransfer') {
            // Generate the payment token now so the success page can show the
            // "I've Sent the Transfer" button and the /pay/{token} link works
            // for the customer without admin intervention.
            $order->setPaymentToken(bin2hex(random_bytes(32)));
            $this->orders->save($order);
            $maxDays = (int) ($this->settings->get('banktransfer_max_days', '14') ?? '14');
            $this->session->set('banktransfer_max_days', $maxDays);
            $this->sendOrderConfirmationEmail($order);
            $this->session->set('cart', []);
            return $response->withHeader('Location', $this->checkoutUrl('/success', ['order' => $order->getOrderNumber()]))->withStatus(302);
        }

        // Delegate to payment gateway
        $gw = $this->getGateway($gateway);
        if ($gw === null) {
            $this->session->set('flash_error', $this->translator->get('Payment method not configured.'));
            return $response->withHeader('Location', $this->checkoutUrl())->withStatus(302);
        }

        try {
            $paymentData = $gw->createPayment($order);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[Checkout] Payment init failed (gateway=%s, order=%s): %s',
                $gateway,
                $order->getOrderNumber(),
                $e->getMessage()
            ));
            $this->session->set('flash_error', 'Payment initialization failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            return $response->withHeader('Location', $this->checkoutUrl())->withStatus(302);
        }

        if (!empty($paymentData['redirect_url'])) {
            // External redirect gateway (PayPal, Mollie, Checkout.com, etc.)
            $this->session->set('pending_payment_id', $paymentData['payment_id'] ?? '');
            if ($gateway === 'checkoutcom') {
                $this->session->set('checkoutcom_link_id', $paymentData['payment_id'] ?? '');
                $this->session->set('checkoutcom_link_order', $order->getOrderNumber());
            }
            return $response->withHeader('Location', $paymentData['redirect_url'])->withStatus(302);
        }

        if (empty($paymentData['client_secret'])) {
            $this->session->set('flash_error', 'Payment initialization failed: no redirect URL and no client secret returned from gateway.');
            return $response->withHeader('Location', $this->checkoutUrl())->withStatus(302);
        }

        // Stripe: go to embedded payment page
        $this->session->set('stripe_client_secret', $paymentData['client_secret'] ?? '');
        $this->session->set('stripe_payment_id', $paymentData['payment_id'] ?? '');

        return $response->withHeader('Location', $this->checkoutUrl('/payment'))->withStatus(302);
    }

    /** POST /checkout/apply-coupon — AJAX coupon validation */
    public function applyCoupon(Request $request, Response $response): Response
    {
        $body                               = (array) json_decode((string) $request->getBody(), true);
        $code                               = strtoupper(trim($body['code'] ?? ''));
        $subtotal                           = (float) ($body['subtotal'] ?? 0);
        $email                              = strtolower(trim($body['email'] ?? ''));
        $country                            = strtoupper(trim((string) ($body['country'] ?? '')));
        $shippingCountry                    = strtoupper(trim((string) ($body['shipping_country'] ?? $country)));
        $cartItems                          = $this->session->get('cart', []);
        [$resolvedItems, $resolvedSubtotal] = !empty($cartItems) ? $this->resolveCartItems($cartItems) : [[], 0.0];
        if (!empty($resolvedItems)) {
            $subtotal = $resolvedSubtotal;
        }

        if ($code === '') {
            $payload = ['valid' => false, 'message' => $this->translator->get('Please enter a coupon code.')];
        } else {
            $cartProductIds  = array_map(static fn ($i) => (int) $i['product']->getId(), $resolvedItems);
            $cartCategoryIds = [];
            foreach ($resolvedItems as $item) {
                foreach ($item['product']->getCategoryIds() as $cid) {
                    $cartCategoryIds[] = (int) $cid;
                }
            }
            $cartCategoryIds  = array_values(array_unique($cartCategoryIds));
            $cartVariationIds = array_values(array_filter(array_map(static fn ($i) => (int) ($i['variation_id'] ?? 0), $resolvedItems)));

            $result = $this->validateCoupon($code, $subtotal, $email, $cartProductIds, $cartCategoryIds, $cartVariationIds);
            if (!$result['valid']) {
                $payload = ['valid' => false, 'message' => $this->translator->get($result['error'])];
            } else {
                $coupon                      = $result['coupon'];
                $scoped                      = $this->couponDiscountForItems($coupon, $resolvedItems);
                $discount                    = $scoped['discount'];
                $this->activeBillingCountry  = $country !== '' ? $country : null;
                $shipping                    = $this->calculateShipping($resolvedItems, $subtotal, $shippingCountry !== '' ? $shippingCountry : null);
                $freeShipping                = $coupon->hasFreeShipping();
                if ($freeShipping) {
                    $shipping = 0.0;
                }
                $taxPolicy                  = $this->getTaxPolicy($this->activeBillingCountry);
                $taxAmount                  = $this->calculateIncludedTaxFromItems($resolvedItems, $shipping, $discount, $taxPolicy, $scoped['lines']);
                $payload                    = [
                    'valid'         => true,
                    'code'          => $coupon->getCode(),
                    'discount'      => $discount,
                    'shipping'      => $shipping,
                    'tax'           => $taxAmount,
                    'total'         => max(0.0, $subtotal + $shipping - $discount),
                    'message'       => $this->translator->get('Coupon applied — :amount off!', [':amount' => $this->formatCouponSaving($coupon, $discount)]),
                    'free_shipping' => $freeShipping,
                ];
            }
        }

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    /** POST /checkout/quote — AJAX summary recalculation for country/coupon updates */
    public function quote(Request $request, Response $response): Response
    {
        $body            = (array) json_decode((string) $request->getBody(), true);
        $country         = strtoupper(trim((string) ($body['country'] ?? '')));
        $shippingCountry = strtoupper(trim((string) ($body['shipping_country'] ?? $country)));
        $couponCode      = strtoupper(trim((string) ($body['coupon_code'] ?? '')));
        $email           = strtolower(trim((string) ($body['email'] ?? '')));

        $cartItems                  = $this->session->get('cart', []);
        [$items, $subtotal]         = !empty($cartItems) ? $this->resolveCartItems($cartItems) : [[], 0.0];
        $this->activeBillingCountry = $country !== '' ? $country : null;
        $shipping                   = $this->calculateShipping($items, $subtotal, $shippingCountry !== '' ? $shippingCountry : null);

        $discount      = 0.0;
        $discountLines = null;
        $appliedCode   = '';
        $freeShipping  = false;
        if ($couponCode !== '') {
            $cartProductIds  = array_map(static fn ($i) => (int) $i['product']->getId(), $items);
            $cartCategoryIds = [];
            foreach ($items as $item) {
                foreach ($item['product']->getCategoryIds() as $cid) {
                    $cartCategoryIds[] = (int) $cid;
                }
            }
            $cartCategoryIds  = array_values(array_unique($cartCategoryIds));
            $cartVariationIds = array_values(array_filter(array_map(static fn ($i) => (int) ($i['variation_id'] ?? 0), $items)));

            $result = $this->validateCoupon($couponCode, $subtotal, $email, $cartProductIds, $cartCategoryIds, $cartVariationIds);
            if ($result['valid']) {
                $scoped        = $this->couponDiscountForItems($result['coupon'], $items);
                $discount      = $scoped['discount'];
                $discountLines = $scoped['lines'];
                $appliedCode   = $result['coupon']->getCode();
                $freeShipping  = $result['coupon']->hasFreeShipping();
            }
        }

        if ($freeShipping) {
            $shipping = 0.0;
        }

        $taxPolicy     = $this->getTaxPolicy($country !== '' ? $country : null);
        $taxAmount     = $this->calculateIncludedTaxFromItems($items, $shipping, $discount, $taxPolicy, $discountLines);
        $itemTaxAmount = $this->calculateIncludedTaxFromItems($items, 0.0, 0.0, $taxPolicy);
        // Track abandoned cart for guests who entered email
        if ($email !== '' && $this->abandonedCart !== null) {
            $this->abandonedCart->touchCart(
                $this->session->get('user_id'),
                $email,
                json_encode($cartItems),
                $subtotal,
                $this->activeOrderCurrency(),
                $this->translator?->getLocale() ?? 'en'
            );
        }

        $shippingEnabled  = $this->settings->get('shipping_enabled', '0') === '1';
        $hasPhysicalItems = false;
        foreach ($items as $item) {
            if (!($item['product']->isDigital())) {
                $hasPhysicalItems = true;
                break;
            }
        }

        // hasDoNotShipItems() already returns false for an empty country.
        $hasDoNotShip  = $this->hasDoNotShipItems($items, $shippingCountry);
        $shippingError = '';
        if ($hasPhysicalItems && !$shippingEnabled) {
            $shippingError = $this->translator ? $this->translator->get('Shipping is currently unavailable.') : 'Shipping is currently unavailable.';
        } elseif ($hasDoNotShip) {
            $shippingError = $this->translator ? $this->translator->get('Some items cannot be shipped to selected country.') : 'Some items cannot be shipped to selected country.';
        }

        $estimateCountry     = $shippingCountry !== '' ? $shippingCountry : ($country !== '' ? $country : null);
        $shippingEstimateRow = $this->deliveryEstimate?->estimateForOrder($items, $estimateCountry) ?? ['min' => null, 'max' => null];

        $payload       = [
            'shipping'              => round($shipping, 2),
            'subtotal'              => round($subtotal, 2),
            'subtotal_excl_tax'     => round(max(0.0, $subtotal - $itemTaxAmount), 2),
            'discount'              => round($discount, 2),
            'tax'                   => round($taxAmount, 2),
            'total'                 => round(max(0.0, $subtotal + $shipping - $discount), 2),
            'applied_code'          => $appliedCode,
            'free_shipping'         => $freeShipping,
            'shipping_error'        => $shippingError,
            'has_do_not_ship_items' => $hasDoNotShip || ($hasPhysicalItems && !$shippingEnabled),
            'tax_display_rate'      => $this->resolveCheckoutDisplayVatRate($items, $taxPolicy, $shipping),
            'tax_mixed'             => $this->hasMixedItemVatRates($items, $taxPolicy, $shipping),
            'regime'                => $taxPolicy['regime'] ?? 'normal_vat',
            'shipping_estimate'     => $shippingEstimateRow,
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    /** GET /checkout/payment — Stripe.js embedded payment page */
    public function payment(Request $request, Response $response): Response
    {
        $orderId      = $this->session->get('pending_order_id');
        $clientSecret = $this->session->get('stripe_client_secret', '');

        if (!$clientSecret || !$orderId) {
            return $response->withHeader('Location', $this->checkoutUrl())->withStatus(302);
        }

        $order     = $this->orders->findById((int) $orderId);
        $stripeKey = $this->settings->get('stripe_publishable_key', '');

        return $this->twig->render($response, 'shop/checkout-payment.twig', [
            'order'         => $order,
            'client_secret' => $clientSecret,
            'stripe_key'    => $stripeKey,
            'success_url'   => $this->checkoutUrl('/success'),
        ]);
    }

    /** GET /checkout/success — shown after payment completes */
    public function success(Request $request, Response $response): Response
    {
        $params      = $request->getQueryParams();
        $orderNumber = (string) ($params['order'] ?? $this->session->get('pending_order_number', ''));
        $order       = $orderNumber ? $this->orders->findByOrderNumber($orderNumber) : null;
        $justPaid    = false;
        $shopPrefix  = $this->settings->get('routing_shop_prefix', '/shop');

        if (!$order) {
            return $response->withHeader('Location', site_url($shopPrefix))->withStatus(302);
        }

        // --- Authorization & verification ---
        $userId       = $this->session->get('user_id');
        $orderUserId  = $order->getUserId();
        $isOwner      = $userId !== null && $orderUserId !== null && (int) $orderUserId === (int) $userId;
        $isSuperAdmin = $this->session->get('user_role')                                === 'super_admin';
        // The payment provider returns to this URL in the same session that
        // created the order. This is the immediate checkout result, not a
        // reusable order-status link sent by email. Applies to every gateway
        // (Stripe/PayPal/Mollie/2Checkout/Checkout.com) as well as free and
        // bank-transfer orders — none of them append payment_intent.
        $isPaymentReturn = empty($params['order'])
            && $orderNumber !== ''
            && (string) $this->session->get('pending_order_number', '') === $orderNumber;

        // Logged in but not the owner -> deny (super admins may inspect any
        // order). Exception: a guest order placed with THIS account's email
        // before logging in / registering belongs to them.
        if ($userId !== null && !$isOwner && !$isSuperAdmin) {
            $sessionEmail         = strtolower(trim((string) ($this->session->get('user_email', '') ?? '')));
            $orderEmail           = strtolower(trim((string) ($order->getBilling()['email'] ?? '')));
            $matchesOwnGuestOrder = $orderUserId === null
                && $sessionEmail !== ''
                && $sessionEmail === $orderEmail;

            if (!$matchesOwnGuestOrder) {
                $this->session->flash('error', 'You do not have permission to view this order.');
                return $response->withHeader('Location', site_url($shopPrefix))->withStatus(302);
            }
        }

        // Reusable guest order links require email + captcha verification.
        // The immediate payment return is already tied to the checkout session.
        if (!$isOwner && !$isSuperAdmin && !$isPaymentReturn) {
            $verifiedKey = 'guest_order_verified_' . $orderNumber;
            $isVerified  = (bool) ($this->session->get($verifiedKey, false) ?? false);

            if ($request->getMethod() === 'POST') {
                $this->session->set('pending_order_number', null);

                $body         = $request->getParsedBody();
                $email        = trim((string) ($body['email'] ?? ''));
                $billingEmail = trim((string) ($order->getBilling()['email'] ?? ''));

                if (strcasecmp($email, $billingEmail) !== 0) {
                    return $this->twig->render($response->withStatus(422), 'shop/order-success-verify.twig', [
                        'order_number' => $orderNumber,
                        'error'        => 'The email address does not match this order.',
                        'captcha'      => $this->buildCaptchaData('login_captcha_type'),
                        'old_email'    => $email,
                    ]);
                }

                $captchaError = $this->validateCaptcha($body, 'login_captcha_type');
                if ($captchaError !== null) {
                    return $this->twig->render($response->withStatus(422), 'shop/order-success-verify.twig', [
                        'order_number' => $orderNumber,
                        'error'        => $captchaError,
                        'captcha'      => $this->buildCaptchaData('login_captcha_type'),
                        'old_email'    => $email,
                    ]);
                }

                $this->session->set($verifiedKey, true);
                return $response->withHeader('Location', $this->checkoutUrl('/success', ['order' => $orderNumber]))->withStatus(302);
            }

            if (!$isVerified) {
                $this->session->set('pending_order_number', null);
                return $this->twig->render($response, 'shop/order-success-verify.twig', [
                    'order_number' => $orderNumber,
                    'captcha'      => $this->buildCaptchaData('login_captcha_type'),
                ]);
            }
        }

        $explicitOrder = (string) ($params['order'] ?? '');
        if ($explicitOrder === '') {
            $shownOrderNumber = (string) ($this->session->get('checkout_success_order_number', '') ?? '');
            if ($shownOrderNumber !== '' && $shownOrderNumber === $orderNumber) {
                return $response->withHeader('Location', site_url($shopPrefix))->withStatus(302);
            }
        }

        // For Stripe: confirm the payment intent if passed
        if (!empty($params['payment_intent'])) {
            $gw = $this->getGateway('stripe');
            if ($gw !== null && $order !== null && !$justPaid) {
                try {
                    $result = $gw->verifyPayment($params['payment_intent']);
                    if ($result['status'] === 'paid' && $order->getStatus() !== 'processing') {
                        $status = $order->isAllItemsDigital($this->products) ? 'completed' : 'processing';
                        $order->setStatus($status);
                        $order->setTransactionId($result['transaction_id']);
                        $order->setPaidAt(new \DateTimeImmutable());
                        $this->orders->save($order);
                        $this->orderLogs?->add(
                            $order->getId(),
                            'payment_received',
                            'Stripe payment confirmed. Transaction: ' . $result['transaction_id']
                        );
                        $this->events->doAction('order.paid', $order);
                        $justPaid = true;
                    }
                } catch (\Throwable $e) {
                    $this->session->set('flash_error', 'Stripe verification failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
                }
            }
        }

        // For PayPal: token + PayerID in query string
        if (!empty($params['token']) && !empty($params['PayerID'])) {
            $gw = $this->getGateway('paypal');
            if ($gw !== null && $order !== null && !$justPaid) {
                try {
                    $result = $gw->verifyPayment($params['token']);
                    if ($result['status'] === 'paid') {
                        $status = $order->isAllItemsDigital($this->products) ? 'completed' : 'processing';
                        $order->setStatus($status);
                        $order->setTransactionId($result['transaction_id']);
                        $order->setPaidAt(new \DateTimeImmutable());
                        $this->orders->save($order);
                        $this->orderLogs?->add(
                            $order->getId(),
                            'payment_received',
                            'PayPal payment confirmed. Transaction: ' . $result['transaction_id']
                        );
                        $this->events->doAction('order.paid', $order);
                        $justPaid = true;
                    }
                } catch (\Throwable) {
                }
            }
        }

        // For Checkout.com: link ID stored in session during place(), bound to
        // the order it was created for — a stale key from a previous order in
        // the same session must never mark a different order as paid.
        $ckoLinkId    = (string) ($this->session->get('checkoutcom_link_id', '') ?? '');
        $ckoLinkOrder = (string) ($this->session->get('checkoutcom_link_order', '') ?? '');
        if (
            $ckoLinkId !== '' && $order !== null && !$justPaid
                              && $order->getPaymentMethod() === 'checkoutcom'
                              && $ckoLinkOrder !== ''
                              && $ckoLinkOrder === $orderNumber
        ) {
            $gw = $this->getGateway('checkoutcom');
            if ($gw !== null) {
                try {
                    $result = $gw->verifyPayment($ckoLinkId);
                    if ($result['status'] === 'paid' && $order->getStatus() !== 'processing') {
                        $status = $order->isAllItemsDigital($this->products) ? 'completed' : 'processing';
                        $order->setStatus($status);
                        $order->setTransactionId($result['transaction_id']);
                        $order->setPaidAt(new \DateTimeImmutable());
                        $this->orders->save($order);
                        $this->orderLogs?->add(
                            $order->getId(),
                            'payment_received',
                            'Checkout.com payment confirmed. Transaction: ' . $result['transaction_id']
                        );
                        $this->events->doAction('order.paid', $order);
                        $justPaid = true;
                    }
                } catch (\Throwable) {
                }
            }
        }

        // For Mollie / 2Checkout: verify on return using pending_payment_id stored in session
        $pendingPaymentId = (string) ($this->session->get('pending_payment_id', '') ?? '');
        $paymentMethod    = $order?->getPaymentMethod() ?? '';
        if ($pendingPaymentId !== '' && $order !== null && !$justPaid && in_array($paymentMethod, ['mollie', 'twocheckout'], true)) {
            $gw = $this->getGateway($paymentMethod);
            if ($gw !== null) {
                try {
                    $result = $gw->verifyPayment($pendingPaymentId);
                    if ($result['status'] === 'paid' && $order->getStatus() !== 'processing') {
                        $status = $order->isAllItemsDigital($this->products) ? 'completed' : 'processing';
                        $order->setStatus($status);
                        $order->setTransactionId($result['transaction_id']);
                        $order->setPaidAt(new \DateTimeImmutable());
                        $this->orders->save($order);
                        $this->orderLogs?->add(
                            $order->getId(),
                            'payment_received',
                            ucfirst($paymentMethod) . ' payment confirmed. Transaction: ' . $result['transaction_id']
                        );
                        $this->events->doAction('order.paid', $order);
                        $justPaid = true;
                    }
                } catch (\Throwable) {
                }
            }
        }

        // Clear the shopper's cart only when this view belongs to the order
        // they just placed — revisiting an older success link from email must
        // not silently wipe an active cart.
        $isFreshOrderView = $justPaid
            || (string) $this->session->get('pending_order_number', '') === $orderNumber;
        if ($isFreshOrderView) {
            $this->session->set('cart', []);
            setcookie('vl_cart', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }

        $this->session->set('pending_order_id', null);
        $this->session->set('pending_order_number', null);
        $this->session->set('stripe_client_secret', null);
        $this->session->set('pending_payment_id', null);
        $this->session->set('checkoutcom_link_id', null);
        $this->session->set('checkoutcom_link_order', null);
        $this->twig->getEnvironment()->addGlobal('cart_count', 0);

        // Re-fetch order from DB to ensure we have the latest persisted status
        if ($order !== null) {
            $dbOrder = $this->orders->findByOrderNumber($order->getOrderNumber());
            if ($dbOrder !== null) {
                $order = $dbOrder;
            }
        }

        // Send email only when payment is just confirmed (avoid re-sending on refresh)
        // For digital-only orders, send email even if status is completed
        if ($order !== null && $justPaid) {
            if (
                $order->getStatus() === 'processing' ||
                ($order->getStatus() === 'completed' && $order->isAllItemsDigital($this->products))
            ) {
                $this->sendPaymentReceivedEmail($order);
            }
        }

        $this->session->set('checkout_success_order_number', $orderNumber);

        // Bank transfer claim state — lets the success page show the
        // single-click "I've Sent the Transfer" button or an already-notified note.
        $alreadyClaimed = false;
        if (
            $order !== null && $this->orderLogs !== null
                            && BankTransferDetailsService::isBankTransferMethod($order->getPaymentMethod() ?? '')
        ) {
            foreach ($this->orderLogs->findByOrderId($order->getId()) as $log) {
                if ($log->getEvent() === 'customer_claimed_payment') {
                    $alreadyClaimed = true;
                    break;
                }
            }
        }

        return $this->twig->render($response, 'shop/order-success.twig', [
            'order'           => $order,
            'already_claimed' => $alreadyClaimed,
            'downloads'       => $order !== null && $this->downloadRepo !== null
                ? $this->downloadRepo->findGrantsByOrderId($order->getId())
                : [],
            'refund_request'          => $order !== null ? $this->buildRefundRequestPayload($order) : null,
            'refund_request_eligible' => $order !== null ? $this->isRefundRequestEligible($order) : false,
            'tax_policy'              => $this->getTaxPolicy($order !== null ? strtoupper(trim((string) ($order->getBilling()['country'] ?? ''))) : null),
            'tracking_url'            => $this->buildTrackingUrl($order),
            ...($order !== null ? $this->bankTransferContext($order) : []),
        ]);
    }

    private function buildTrackingUrl(?Order $order): string
    {
        if ($order === null) {
            return '';
        }
        $carrier  = strtolower(trim((string) $order->getCarrier()));
        $trackNum = trim((string) $order->getTrackingNumber());
        if ($carrier === '' || $trackNum === '') {
            return '';
        }
        if ($carrier === 'other') {
            return $trackNum;
        }

        $refData  = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $builtIn  = is_array($refData['shipping_carriers'] ?? null) ? $refData['shipping_carriers'] : [];
        $custom   = json_decode((string) $this->settings->get('custom_shipping_carriers', '[]'), true) ?: [];

        $carriers = array_merge($builtIn, $custom);
        foreach ($carriers as $c) {
            $val = strtolower(trim((string) ($c['value'] ?? '')));
            if ($val === $carrier && !empty($c['url_template'])) {
                return str_replace('{tracking_number}', urlencode($trackNum), (string) $c['url_template']);
            }
        }

        return '';
    }

    /** @return array{regime:string,rate:float,zero_reason:string,display_mode:string} */
    private function getTaxPolicy(?string $countryCode = null): array
    {
        $displayMode = (string) ($this->settings->get('tax_display_mode', 'incl_and_breakdown') ?? 'incl_and_breakdown');
        if (!in_array($displayMode, ['incl_only', 'incl_and_breakdown'], true)) {
            $displayMode = 'incl_and_breakdown';
        }

        $legacyRate    = (string) ($this->settings->get('tax_rate', '19') ?? '19');
        $globalRateRaw = (string) ($this->settings->get('tax_default_rate', $legacyRate) ?? $legacyRate);
        $globalRate    = max(0.0, (float) str_replace(',', '.', $globalRateRaw));

        $companyCountry = strtoupper(trim((string) ($this->settings->get('shop_country', '') ?? '')));

        // Resolve per-country tax config
        if ($countryCode !== null) {
            $countryCode = strtoupper($countryCode);
            $countryTax  = $this->countryTaxRates->findByCountry($countryCode);

            if ($countryTax !== null) {
                $regime = $countryTax->getRegime();
                if ($regime === 'normal_vat') {
                    $rate       = $countryTax->getRate() ?? $globalRate;
                    $zeroReason = '';
                } else {
                    $zeroReason = trim($countryTax->getZeroReason() ?? '');
                    if ($zeroReason === '') {
                        $zeroReason = 'No tax applied (0%).';
                    }
                    $rate = 0.0;
                }
                return [
                    'regime'       => $regime,
                    'rate'         => $rate,
                    'zero_reason'  => $zeroReason,
                    'display_mode' => $displayMode,
                ];
            }

            // No specific config for this country — check fallback
            $fallback = $this->countryTaxRates->findFallback();
            if ($fallback !== null) {
                $fbRegime = $fallback->getRegime();
                if ($fbRegime === 'use_global') {
                    // Inherit company country's default
                    $countryRate = $this->countryTaxRates->findByCountry($companyCountry);
                    if ($countryRate !== null && $countryRate->getRegime() === 'normal_vat') {
                        $rate = $countryRate->getRate() ?? $globalRate;
                        return [
                            'regime'       => 'normal_vat',
                            'rate'         => $rate,
                            'zero_reason'  => '',
                            'display_mode' => $displayMode,
                        ];
                    }
                    // Fall back to global settings
                    return $this->buildGlobalTaxPolicy($globalRate, $displayMode);
                }
                if ($fbRegime === 'normal_vat') {
                    $rate = $fallback->getRate() ?? $globalRate;
                    return [
                        'regime'       => 'normal_vat',
                        'rate'         => $rate,
                        'zero_reason'  => '',
                        'display_mode' => $displayMode,
                    ];
                }
                // no_tax
                $zeroReason = trim($fallback->getZeroReason() ?? '');
                if ($zeroReason === '') {
                    $zeroReason = 'No tax applied (0%).';
                }
                return [
                    'regime'       => 'no_tax',
                    'rate'         => 0.0,
                    'zero_reason'  => $zeroReason,
                    'display_mode' => $displayMode,
                ];
            }
        }

        return $this->buildGlobalTaxPolicy($globalRate, $displayMode);
    }

    private function buildGlobalTaxPolicy(float $globalRate, string $displayMode): array
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
            'regime'       => $regime,
            'rate'         => $rate,
            'zero_reason'  => $zeroReason,
            'display_mode' => $displayMode,
        ];
    }

    /** @param array{regime:string,rate:float,zero_reason:string,display_mode:string} $taxPolicy */
    private function calculateIncludedTaxFromItems(array $items, float $shipping, float $discount, array $taxPolicy, ?array $discountLines = null): float
    {
        return $this->calculateIncludedTaxBreakdown($items, $shipping, $discount, $taxPolicy, $discountLines)['total'];
    }

    /**
     * Extracts included VAT from gross prices.
     *
     * @param array{regime:string,rate:float,zero_reason:string,display_mode:string} $taxPolicy
     * @param array<int,float>|null $discountLines Per-line discount money (scope-aware allocation). Null = proportional split.
     * @return array{total: float, shipping: float, lines: array<int, array{rate: float, amount: float}>}
     */
    private function calculateIncludedTaxBreakdown(array $items, float $shipping, float $discount, array $taxPolicy, ?array $discountLines = null): array
    {
        if (($taxPolicy['regime'] ?? 'normal_vat') !== 'normal_vat') {
            return ['total' => 0.0, 'shipping' => 0.0, 'lines' => []];
        }

        $globalRate = $this->normalizeVatRate($taxPolicy['rate'] ?? 0.0);
        if ($globalRate <= 0.0 && empty($items)) {
            return ['total' => 0.0, 'shipping' => 0.0, 'lines' => []];
        }

        $lineGrossRows = [];
        $subtotalGross = '0';
        foreach ($items as $index => $item) {
            $lineGross = max(0.0, ((float) ($item['price'] ?? 0.0)) * (int) ($item['qty'] ?? 0));
            if ($lineGross <= 0.0) {
                continue;
            }
            $lineGrossRows[] = [
                'index' => $index,
                'gross' => Money::toDecimalString($lineGross),
                'rate'  => $this->resolveItemVatRate($item, $taxPolicy),
            ];
            $subtotalGross = Money::add($subtotalGross, $lineGross);
        }

        $discountApplied = min(max(0.0, $discount), Money::toFloat($subtotalGross));
        $tax             = '0';
        $shippingTax     = '0';
        $lines           = [];

        foreach ($lineGrossRows as $row) {
            $gross = (string) $row['gross'];
            $rate  = $this->normalizeVatRate($row['rate'] ?? 0.0);
            if ($rate <= 0.0) {
                continue;
            }
            $share = 0.0;
            if ($discountLines !== null) {
                $share = min(max(0.0, (float) ($discountLines[$row['index']] ?? 0.0)), Money::toFloat($gross));
            } elseif (Money::toFloat($subtotalGross) > 0.0) {
                $share = $discountApplied * Money::toFloat(Money::div($gross, $subtotalGross));
            }
            $taxableGross         = max(0.0, Money::toFloat(Money::sub($gross, $share)));
            $factor               = Money::add('1', Money::div((string) $rate, '100'));
            $amount               = Money::sub(
                (string) $taxableGross,
                Money::div((string) $taxableGross, $factor)
            );
            $tax                  = Money::add($tax, $amount);
            $lines[$row['index']] = ['rate' => $rate, 'amount' => Money::toFloat(Money::round2($amount))];
        }

        if ($globalRate > 0.0 && $shipping > 0.0) {
            $shippingGross  = max(0.0, $shipping);
            $shippingFactor = Money::add('1', Money::div((string) $globalRate, '100'));
            $shippingTax    = Money::sub((string) $shippingGross, Money::div((string) $shippingGross, $shippingFactor));
            $tax            = Money::add($tax, $shippingTax);
        }

        return ['total' => Money::toFloat(Money::round2($tax)), 'shipping' => Money::toFloat(Money::round2($shippingTax)), 'lines' => $lines];
    }

    /** @param array{regime:string,rate:float,zero_reason:string,display_mode:string} $taxPolicy */
    private function resolveItemVatRate(array $item, array $taxPolicy): float
    {
        $globalRate = $this->normalizeVatRate($taxPolicy['rate'] ?? 0.0);
        if (($taxPolicy['regime'] ?? 'normal_vat') !== 'normal_vat') {
            return 0.0;
        }

        $product   = $item['product'] ?? null;
        $variation = is_array($item['variation_row'] ?? null) ? $item['variation_row'] : null;

        // Check product-level VAT country override first
        if ($product instanceof Product && $this->activeBillingCountry !== null) {
            $vatOverrides = $product->getVatCountryOverrides();
            $countryCode  = strtoupper($this->activeBillingCountry);
            if (isset($vatOverrides[$countryCode])) {
                $override     = $vatOverrides[$countryCode];
                $overrideMode = (string) ($override['mode'] ?? 'use_global');
                if ($overrideMode === 'none') {
                    return 0.0;
                }
                if ($overrideMode === 'custom') {
                    return $this->normalizeVatRate($override['rate'] ?? 0.0);
                }
            }
        }

        if ($variation !== null) {
            $variationMode = (string) ($variation['vat_mode'] ?? 'use_parent');
            if ($variationMode === 'custom') {
                return $this->normalizeVatRate($variation['vat_rate'] ?? 0.0);
            }
            if ($variationMode === 'use_global') {
                return $globalRate;
            }
        }

        if ($product instanceof Product) {
            if ($product->getVatMode() === 'custom') {
                return $this->normalizeVatRate($product->getVatRate() ?? 0.0);
            }
        }

        return $globalRate;
    }

    private function normalizeVatRate(mixed $value): float
    {
        $normalized = is_string($value) ? str_replace(',', '.', $value) : $value;
        return max(0.0, (float) $normalized);
    }

    /** @param array{regime:string,rate:float,zero_reason:string,display_mode:string} $taxPolicy */
    private function hasMixedItemVatRates(array $items, array $taxPolicy, float $shipping = 0.0): bool
    {
        if (($taxPolicy['regime'] ?? 'normal_vat') !== 'normal_vat') {
            return false;
        }

        $rates = [];
        foreach ($items as $item) {
            $rates[] = number_format($this->resolveItemVatRate($item, $taxPolicy), 2, '.', '');
        }

        $globalRate = $this->normalizeVatRate($taxPolicy['rate'] ?? 0.0);
        if ($shipping > 0.0 && $globalRate > 0.0) {
            $rates[] = number_format($globalRate, 2, '.', '');
        }

        return count(array_unique($rates)) > 1;
    }

    /** @param array{regime:string,rate:float,zero_reason:string,display_mode:string} $taxPolicy */
    private function resolveCheckoutDisplayVatRate(array $items, array $taxPolicy, float $shipping = 0.0): float
    {
        if (($taxPolicy['regime'] ?? 'normal_vat') !== 'normal_vat') {
            return 0.0;
        }

        if ($this->hasMixedItemVatRates($items, $taxPolicy, $shipping)) {
            // Return first item's resolved rate instead of country base rate,
            // so VAT overrides are reflected in the display
            foreach ($items as $item) {
                return $this->resolveItemVatRate($item, $taxPolicy);
            }
        }

        foreach ($items as $item) {
            return $this->resolveItemVatRate($item, $taxPolicy);
        }

        return $this->normalizeVatRate($taxPolicy['rate'] ?? 0.0);
    }

    /** POST /checkout/request-refund — customer requests manual cancellation/refund review */
    public function requestRefund(Request $request, Response $response): Response
    {
        $body        = (array) $request->getParsedBody();
        $orderNumber = trim((string) ($body['order_number'] ?? ''));
        $reason      = trim((string) ($body['reason'] ?? ''));

        if ($orderNumber === '') {
            $this->session->set('flash_error', $this->translator->get('Order not found for refund request.'));
            return $response->withHeader('Location', $this->checkoutUrl('/success'))->withStatus(302);
        }

        $order = $this->orders->findByOrderNumber($orderNumber);
        if ($order === null) {
            $this->session->set('flash_error', $this->translator->get('Order not found for refund request.'));
            return $response->withHeader('Location', $this->checkoutUrl('/success', ['order' => $orderNumber]))->withStatus(302);
        }

        $billing      = $order->getBilling();
        $billingEmail = strtolower(trim((string) ($billing['email'] ?? '')));
        $requestEmail = strtolower(trim((string) ($body['email'] ?? '')));
        if ($billingEmail === '' || $requestEmail === '' || $billingEmail !== $requestEmail) {
            $this->session->set('flash_error', $this->translator->get('Refund request could not be verified for this order.'));
            return $response->withHeader('Location', $this->checkoutUrl('/success', ['order' => $orderNumber]))->withStatus(302);
        }

        if (!$this->isRefundRequestEligible($order)) {
            $this->session->set('flash_error', $this->translator->get('Refund request window is closed or this order cannot be requested right now.'));
            return $response->withHeader('Location', $this->checkoutUrl('/success', ['order' => $orderNumber]))->withStatus(302);
        }

        $summary                   = $this->analyzeOrderRefundability($order);
        $autoAcceptDigitalRefunds  = $this->settings->get('auto_accept_digital_refunds', '0') === '1';
        $isDigitalOnlyUndownloaded = $order->isAllItemsDigital($this->products) && !$summary['has_non_refundable'];

        if ($autoAcceptDigitalRefunds && $isDigitalOnlyUndownloaded) {
            // Auto-accept digital refund — run the same side-effect chain as
            // an admin-approved refund (stock restore, coupon return,
            // download revoke, subscriber tag, outbound webhooks) so the
            // self-service path does not drift from the admin path. The
            // auto-approve emails below are kept; no generic status email is
            // sent to avoid a duplicate.
            $oldStatus = $order->getStatus();
            $order->setStatus('refunded');
            $this->orders->save($order);

            $effects = new OrderStatusEffects(
                $this->orders,
                $this->coupons,
                $this->subscriberRepo,
                $this->downloadRepo,
                $this->automationService,
            );
            $effects->syncDownloadRevocation($order, 'refunded');
            $effects->syncCouponUsage($order, $oldStatus, 'refunded');

            $note = 'Auto-accepted digital refund request (no files downloaded).' . ($reason !== '' ? ' Customer reason: ' . $reason : '');
            $this->orderLogs?->add($order->getId(), 'refund_request_approved', $note, 'system', null, 'System');

            if (in_array($oldStatus, OrderStatus::PAID, true)) {
                $this->events->doAction('order.reversed', $order);
            }
            $this->events->doAction('order.status_changed', $order, 'refunded');
            if (OrderStatus::isReversal('refunded')) {
                $effects->tagSubscriberReversed($order);
            }

            $this->sendRefundAutoApprovedEmails($order, $reason);

            $this->session->set('flash_success', $this->translator->get('Your refund request has been automatically approved.'));
            return $response->withHeader('Location', $this->checkoutUrl('/success', ['order' => $orderNumber]))->withStatus(302);
        }

        $order->setStatus('refund_requested');
        $this->orders->save($order);

        $note = $reason !== '' ? ('Customer reason: ' . $reason) : 'Customer requested cancellation/refund review.';
        if ($summary['has_non_refundable']) {
            $note .= ' Note: Order contains non-refundable items: ' . implode('; ', $summary['reasons']);
        }

        $this->orderLogs?->add($order->getId(), 'refund_requested', $note, 'system', null, 'Customer');

        $this->events->doAction('order.status_changed', $order, 'refund_requested');

        $this->sendRefundRequestedEmails($order, $reason);

        $this->session->set('flash_success', $this->translator->get('Your refund request has been sent to our team.'));
        return $response->withHeader('Location', $this->checkoutUrl('/success', ['order' => $orderNumber]))->withStatus(302);
    }

    /** GET /pay/{token} — resume payment from an admin-generated payment link */
    public function resumePayment(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $order = $this->orders->findByPaymentToken($token);

        if ($order === null) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig', []);
        }

        // Already paid — show success page
        if (in_array($order->getStatus(), ['processing', 'completed', 'shipped'], true)) {
            return $response->withHeader(
                'Location',
                $this->checkoutUrl('/success', ['order' => $order->getOrderNumber()])
            )->withStatus(302);
        }

        // Cancelled orders cannot be resumed
        if ($order->getStatus() === 'cancelled') {
            return $this->twig->render($response->withStatus(410), 'errors/404.twig', []);
        }

        // Bank transfer orders have no online gateway — show payment instructions instead
        $gateway = $order->getPaymentMethod() ?? $this->settings->get('payment_active_gateway', 'stripe');
        if (BankTransferDetailsService::isBankTransferMethod($gateway)) {
            return $this->renderBankTransferInstructions($request, $response, $order);
        }

        $gw = $this->getGateway($gateway);

        if ($gw === null) {
            error_log('[ResumePayment] No payment gateway for order #' . $order->getOrderNumber()
                . ' (method: ' . ($order->getPaymentMethod() ?? 'null') . ')');
            return $this->twig->render($response->withStatus(503), 'errors/generic.twig', [
                'error_code' => 503,
                'title'      => $this->translator->get('Online payment unavailable'),
                'message'    => $this->translator->get('This order cannot be paid online. Please contact us to complete your purchase.'),
            ]);
        }

        try {
            $paymentData = $gw->createPayment($order);
        } catch (\Throwable $e) {
            error_log('[ResumePayment] Failed for order #' . $order->getOrderNumber() . ': ' . $e->getMessage());
            return $this->twig->render($response->withStatus(500), 'errors/500.twig', []);
        }

        $this->session->set('pending_order_id', $order->getId());
        $this->session->set('pending_order_number', $order->getOrderNumber());

        if (!empty($paymentData['redirect_url'])) {
            $this->session->set('pending_payment_id', $paymentData['payment_id'] ?? '');
            return $response->withHeader('Location', $paymentData['redirect_url'])->withStatus(302);
        }

        if (empty($paymentData['client_secret'])) {
            error_log('[ResumePayment] No client secret for order #' . $order->getOrderNumber());
            return $this->twig->render($response->withStatus(500), 'errors/500.twig', []);
        }

        $this->session->set('stripe_client_secret', $paymentData['client_secret']);
        $this->session->set('stripe_payment_id', $paymentData['payment_id'] ?? '');

        return $response->withHeader('Location', $this->checkoutUrl('/payment'))->withStatus(302);
    }

    /**
     * Template variables for bank transfer details (success page, emails).
     *
     * @return array<string, mixed>
     */
    private function bankTransferContext(Order $order): array
    {
        return $this->bankTransferDetails?->forOrder($order) ?? [];
    }

    /** GET /pay/{token} for bank transfer orders — instructions page. */
    private function renderBankTransferInstructions(Request $request, Response $response, Order $order): Response
    {
        $details = $this->bankTransferDetails?->forOrder($order) ?? [];
        $claimed = false;
        if ($this->orderLogs !== null) {
            foreach ($this->orderLogs->findByOrderId($order->getId()) as $log) {
                if ($log->getEvent() === 'customer_claimed_payment') {
                    $claimed = true;
                    break;
                }
            }
        }

        return $this->twig->render($response, 'shop/bank-transfer-instructions.twig', [
            'order'           => $order,
            'just_claimed'    => ($request->getQueryParams()['claimed'] ?? '') === '1',
            'already_claimed' => $claimed,
        ] + $details);
    }

    /** POST /pay/{token}/claim — customer says they sent the transfer. */
    public function claimBankTransfer(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        $order = $this->orders->findByPaymentToken($token);

        if (
            $order === null
            || !BankTransferDetailsService::isBankTransferMethod($order->getPaymentMethod())
        ) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig', []);
        }

        // Paid/cancelled orders have nothing to claim — back to the instructions page,
        // which redirects to success or shows as closed on its own.
        if (!in_array($order->getStatus(), ['pending', 'on-hold'], true)) {
            return $response->withHeader('Location', site_url('/pay/' . $token))->withStatus(302);
        }

        $recentlyClaimed = false;
        if ($this->orderLogs !== null) {
            foreach ($this->orderLogs->findByOrderId($order->getId()) as $log) {
                if (
                    $log->getEvent() === 'customer_claimed_payment'
                    && $log->getCreatedAt() > new \DateTimeImmutable('-24 hours')
                ) {
                    $recentlyClaimed = true;
                    break;
                }
            }
        }

        if (!$recentlyClaimed) {
            $this->orderLogs?->add(
                $order->getId(),
                'customer_claimed_payment',
                'Customer marked the bank transfer as sent.',
                'customer',
                null,
                'Customer'
            );
            $this->notifyAdminOfPaymentClaim($order);
        }

        return $response->withHeader('Location', site_url('/pay/' . $token, [], ['claimed' => '1']))->withStatus(302);
    }

    private function notifyAdminOfPaymentClaim(Order $order): void
    {
        if ($this->mail === null) {
            return;
        }

        $adminEmail = $this->adminNotificationEmail(
            (string) ($order->getBilling()['email'] ?? '')
        );
        if ($adminEmail === null) {
            return;
        }

        try {
            $adminLocale = $this->settings->get('locale', 'en');
            $this->mail->sendTemplate(
                $adminEmail,
                $this->mailSubject('Payment claim — Order #:number', [':number' => $order->getOrderNumber()], $adminLocale),
                'mail/payment-claim-admin',
                ['order' => $order],
                locale: $adminLocale,
                recipientType: 'admin',
            );
        } catch (\Throwable) {
            // Non-fatal — the order log entry already records the claim.
        }
    }

    /** GET /checkout/cancel — user cancelled on the payment provider's page */
    public function cancel(Request $request, Response $response): Response
    {
        $orderId = $this->session->get('pending_order_id');
        if ($orderId) {
            $order = $this->orders->findById((int) $orderId);
            if ($order && $order->getStatus() === 'pending') {
                // Mark as failed (not cancelled) so the customer can retry payment
                // via the admin-generated payment link or by placing a new order.
                $order->setStatus('failed');
                $this->orders->save($order);
                $this->orderLogs?->add(
                    $order->getId(),
                    'status_changed',
                    'Payment not completed - customer left the payment page.'
                );

                // Return the coupon use — nothing was purchased. The atomic
                // claim makes this idempotent against a gateway "payment
                // failed" event or an admin-side status change.
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
        }

        $this->session->set('flash_error', $this->translator->get('Payment was not completed. You can try again or place a new order.'));
        return $response->withHeader('Location', $this->checkoutUrl())->withStatus(302);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Calculate shipping cost for a set of resolved cart items. */
    /** Detect the visitor's country from request headers (no external service). */
    private function detectCountryFromRequest(Request $request): ?string
    {
        $remoteAddr     = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $trustedProxies = env('TRUSTED_PROXIES', '');

        // 1. Cloudflare provides CF-IPCountry header (trust only when proxy is trusted or TRUSTED_PROXIES not restricted)
        $cfCountry = trim($request->getHeaderLine('CF-IPCountry'));
        if ($cfCountry === '') {
            $cfCountry = trim((string) ($request->getServerParams()['HTTP_CF_IPCOUNTRY'] ?? ''));
        }
        if ($cfCountry !== '') {
            if ($trustedProxies === '' || vl_is_trusted_proxy($remoteAddr, $trustedProxies)) {
                $code = strtoupper($cfCountry);
                if ($code !== 'XX' && preg_match('/^[A-Z]{2}$/', $code)) {
                    return $code;
                }
            }
        }

        // 2. Get real IP using trusted proxy resolver
        $ip = vl_client_ip($request);

        // If IP is private/loopback we can't geolocate — fall through
        if (
            $ip && !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )
        ) {
            $ip = null;
        }

        // 3. Accept-Language fallback: "de-DE,de;q=0.9,en;q=0.8" -> "DE"
        $acceptLang = (string) ($headers['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($acceptLang) {
            if (preg_match('/[a-z]{2}-([A-Z]{2})/i', $acceptLang, $m)) {
                return strtoupper($m[1]);
            }
        }

        // 4. Fall back to the company's country setting
        $companyCountry = strtoupper(trim((string) $this->settings->get('shop_country', '') ?? ''));
        if ($companyCountry !== '') {
            return $companyCountry;
        }

        return null;
    }

    private function resolveEffectiveShipping(array $item, ?string $billingCountry): array
    {
        $product = $item['product'];
        $varRow  = $item['variation_row'] ?? null;

        $shClass = null;
        $shCost  = null;

        $effectiveCountry = !empty($billingCountry) ? strtoupper(trim($billingCountry)) : '';

        // 1. Variation-level country override
        if ($varRow !== null && $effectiveCountry !== '') {
            $varOverrides = isset($varRow['shipping_country_overrides']) && is_string($varRow['shipping_country_overrides'])
                ? (json_decode($varRow['shipping_country_overrides'], true) ?: [])
                : ($varRow['shipping_country_overrides'] ?? []);

            if (is_array($varOverrides) && isset($varOverrides[$effectiveCountry])) {
                $override = $varOverrides[$effectiveCountry];
                $shClass  = $override['class'] ?? null;
                if ($shClass === 'custom') {
                    $shCost = isset($override['cost']) ? (float) $override['cost'] : 0.0;
                } else {
                    $shCost = 0.0;
                }
            }
        }

        // 2. Variation shipping_class
        if ($shClass === null && $varRow !== null) {
            $varShClass = $varRow['shipping_class'] ?? 'use_parent';
            if ($varShClass !== 'use_parent') {
                $shClass = $varShClass;
                $shCost  = ($varShClass === 'custom') ? (float) ($varRow['shipping_cost'] ?? 0.0) : 0.0;
            }
        }

        // 3. Product-level country override
        if ($shClass === null && $effectiveCountry !== '') {
            $prodOverrides = $product->getShippingCountryOverrides();
            if (isset($prodOverrides[$effectiveCountry])) {
                $override = $prodOverrides[$effectiveCountry];
                $shClass  = $override['class'] ?? null;
                if ($shClass === 'custom') {
                    $shCost = isset($override['cost']) ? (float) $override['cost'] : 0.0;
                } else {
                    $shCost = 0.0;
                }
            }
        }

        // 4. Product shipping_class
        if ($shClass === null) {
            $shClass = $product->getShippingClass();
            $shCost  = (float) ($product->getShippingCost() ?? 0.0);
            // Backward compat: non_digital variation on a digital parent -> treat as physical
            if ($varRow !== null && ($varRow['download_mode'] ?? '') === 'non_digital' && $shClass === 'digital') {
                $shClass = 'use_global';
            }
        }

        return [$shClass, $shCost];
    }

    private function hasDoNotShipItems(array $items, ?string $billingCountry): bool
    {
        if (empty($billingCountry)) {
            return false;
        }

        foreach ($items as $item) {
            [$shClass] = $this->resolveEffectiveShipping($item, $billingCountry);
            if ($shClass === 'do_not_ship') {
                return true;
            }
        }

        return false;
    }

    /**
     * Shipping rates/thresholds are stored in the store's BASE currency.
     * This wrapper converts the final result into the active display/charge
     * currency (and evaluates the free-shipping threshold consistently).
     */
    private function calculateShipping(array $items, float $subtotal, ?string $billingCountry = null): float
    {
        $fx = $this->currentFxRate();
        if ($fx <= 0.0) {
            $fx = 1.0;
        }

        // Convert the incoming (already converted) subtotal back to base
        // currency so threshold comparisons use stored values.
        $subtotalBase = $subtotal / $fx;

        return round($this->calculateShippingBase($items, $subtotalBase, $billingCountry) * $fx, 2);
    }

    private function calculateShippingBase(array $items, float $subtotal, ?string $billingCountry = null): float
    {
        if ($this->settings->get('shipping_enabled', '0') !== '1') {
            return 0.0;
        }

        $mode          = $this->settings->get('shipping_mode', 'flat_rate') ?? 'flat_rate';
        $flatRate      = (float) str_replace(',', '.', $this->settings->get('shipping_flat_rate', '0') ?? '0');
        $freeThreshold = (float) str_replace(',', '.', $this->settings->get('shipping_free_threshold', '50') ?? '50');

        $customShipping = 0.0;
        $hasGlobalItems = false;
        $globalWeight   = 0.0;

        foreach ($items as $item) {
            $product = $item['product'];
            $qty     = (int) $item['qty'];

            [$shClass, $shCost] = $this->resolveEffectiveShipping($item, $billingCountry);

            if ($shClass === 'free' || $shClass === 'digital' || $shClass === 'do_not_ship') {
                // No shipping cost for this item
            } elseif ($shClass === 'custom') {
                $customShipping += $shCost;
            } else { // use_global
                $hasGlobalItems = true;
                $varRow         = $item['variation_row'] ?? null;
                $varWeight      = ($varRow !== null && isset($varRow['weight']) && $varRow['weight'] !== null && $varRow['weight'] !== '')
                    ? (float) $varRow['weight']
                    : null;
                $effectiveWeight = $varWeight ?? ($product->getWeight() ?? 0.0);
                $globalWeight  += $effectiveWeight * $qty;
            }
        }

        if (!$hasGlobalItems) {
            return round($customShipping, 2);
        }

        switch ($mode) {
            case 'free':
                $globalRate = 0.0;
                break;
            case 'free_above_threshold':
                $globalRate = $subtotal >= $freeThreshold ? 0.0 : $flatRate;
                break;
            case 'weight_based':
                $globalRate = $this->getWeightBasedRate($globalWeight);
                break;
            case 'highest_item_rate':
                $globalRate = $this->getHighestItemRate($items);
                break;
            case 'flat_rate':
            default:
                $globalRate = $flatRate;
                break;
        }

        $total = round($customShipping + $globalRate, 2);

        // Country-specific rate override
        $countryRatesJson = $this->settings->get('shipping_country_rates', '{}') ?? '{}';
        $countryRates     = json_decode((string) $countryRatesJson, true);
        if (is_array($countryRates) && !empty($countryRates)) {
            // Country comes from session (set during checkout form) or skip if not known yet
            $effectiveCountry = strtoupper(trim((string) ($billingCountry ?? '')));
            if ($effectiveCountry === '') {
                $effectiveCountry = strtoupper((string) ($this->session->get('last_billing_country') ?? ''));
            }
            if ($effectiveCountry && isset($countryRates[$effectiveCountry])) {
                $countryRate = $countryRates[$effectiveCountry];
                $override    = is_array($countryRate)
                    ? (float) str_replace(',', '.', (string) ($countryRate['rate'] ?? 0))
                    : (float) str_replace(',', '.', (string) $countryRate);
                return round($customShipping + $override, 2);
            }
        }

        return $total;
    }

    private function getWeightBasedRate(float $weight): float
    {
        $ratesJson = $this->settings->get('shipping_weight_rates', '[]') ?? '[]';
        $rates     = json_decode($ratesJson, true);
        if (!is_array($rates)) {
            $rates = [];
        }

        usort($rates, static fn ($a, $b) => ($a['max_weight'] ?? 0) <=> ($b['max_weight'] ?? 0));

        foreach ($rates as $tier) {
            if ($weight <= (float) ($tier['max_weight'] ?? 0)) {
                return (float) str_replace(',', '.', (string) ($tier['rate'] ?? 0));
            }
        }

        return (float) str_replace(',', '.', $this->settings->get('shipping_default_rate', '0') ?? '0');
    }

    /** Returns the highest single-item shipping rate among use_global items. */
    /** @return array<string,string> ISO-3166-1 alpha-2 => country name */
    private function getCountries(): array
    {
        return [
            'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra',
            'AO' => 'Angola', 'AG' => 'Antigua and Barbuda', 'AR' => 'Argentina', 'AM' => 'Armenia',
            'AU' => 'Australia', 'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BS' => 'Bahamas',
            'BH' => 'Bahrain', 'BD' => 'Bangladesh', 'BB' => 'Barbados', 'BY' => 'Belarus',
            'BE' => 'Belgium', 'BZ' => 'Belize', 'BJ' => 'Benin', 'BT' => 'Bhutan',
            'BO' => 'Bolivia', 'BA' => 'Bosnia and Herzegovina', 'BW' => 'Botswana', 'BR' => 'Brazil',
            'BN' => 'Brunei', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
            'CV' => 'Cabo Verde', 'KH' => 'Cambodia', 'CM' => 'Cameroon', 'CA' => 'Canada',
            'CF' => 'Central African Republic', 'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China',
            'CO' => 'Colombia', 'KM' => 'Comoros', 'CD' => 'Congo (DRC)', 'CG' => 'Congo (Republic)',
            'CR' => 'Costa Rica', 'HR' => 'Croatia', 'CU' => 'Cuba', 'CY' => 'Cyprus',
            'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'DJ' => 'Djibouti', 'DM' => 'Dominica',
            'DO' => 'Dominican Republic', 'EC' => 'Ecuador', 'EG' => 'Egypt', 'SV' => 'El Salvador',
            'GQ' => 'Equatorial Guinea', 'ER' => 'Eritrea', 'EE' => 'Estonia', 'SZ' => 'Eswatini',
            'ET' => 'Ethiopia', 'FJ' => 'Fiji', 'FI' => 'Finland', 'FR' => 'France',
            'GA' => 'Gabon', 'GM' => 'Gambia', 'GE' => 'Georgia', 'DE' => 'Germany',
            'GH' => 'Ghana', 'GR' => 'Greece', 'GD' => 'Grenada', 'GT' => 'Guatemala',
            'GN' => 'Guinea', 'GW' => 'Guinea-Bissau', 'GY' => 'Guyana', 'HT' => 'Haiti',
            'HN' => 'Honduras', 'HU' => 'Hungary', 'IS' => 'Iceland', 'IN' => 'India',
            'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq', 'IE' => 'Ireland',
            'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica', 'JP' => 'Japan',
            'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya', 'KI' => 'Kiribati',
            'KW' => 'Kuwait', 'KG' => 'Kyrgyzstan', 'LA' => 'Laos', 'LV' => 'Latvia',
            'LB' => 'Lebanon', 'LS' => 'Lesotho', 'LR' => 'Liberia', 'LY' => 'Libya',
            'LI' => 'Liechtenstein', 'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MG' => 'Madagascar',
            'MW' => 'Malawi', 'MY' => 'Malaysia', 'MV' => 'Maldives', 'ML' => 'Mali',
            'MT' => 'Malta', 'MH' => 'Marshall Islands', 'MR' => 'Mauritania', 'MU' => 'Mauritius',
            'MX' => 'Mexico', 'FM' => 'Micronesia', 'MD' => 'Moldova', 'MC' => 'Monaco',
            'MN' => 'Mongolia', 'ME' => 'Montenegro', 'MA' => 'Morocco', 'MZ' => 'Mozambique',
            'MM' => 'Myanmar', 'NA' => 'Namibia', 'NR' => 'Nauru', 'NP' => 'Nepal',
            'NL' => 'Netherlands', 'NZ' => 'New Zealand', 'NI' => 'Nicaragua', 'NE' => 'Niger',
            'NG' => 'Nigeria', 'NO' => 'Norway', 'OM' => 'Oman', 'PK' => 'Pakistan',
            'PW' => 'Palau', 'PA' => 'Panama', 'PG' => 'Papua New Guinea', 'PY' => 'Paraguay',
            'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal',
            'QA' => 'Qatar', 'RO' => 'Romania', 'RU' => 'Russia', 'RW' => 'Rwanda',
            'KN' => 'Saint Kitts and Nevis', 'LC' => 'Saint Lucia', 'VC' => 'Saint Vincent and the Grenadines',
            'WS' => 'Samoa', 'SM' => 'San Marino', 'ST' => 'Sao Tome and Principe', 'SA' => 'Saudi Arabia',
            'SN' => 'Senegal', 'RS' => 'Serbia', 'SC' => 'Seychelles', 'SL' => 'Sierra Leone',
            'SG' => 'Singapore', 'SK' => 'Slovakia', 'SI' => 'Slovenia', 'SB' => 'Solomon Islands',
            'SO' => 'Somalia', 'ZA' => 'South Africa', 'SS' => 'South Sudan', 'ES' => 'Spain',
            'LK' => 'Sri Lanka', 'SD' => 'Sudan', 'SR' => 'Suriname', 'SE' => 'Sweden',
            'CH' => 'Switzerland', 'SY' => 'Syria', 'TW' => 'Taiwan', 'TJ' => 'Tajikistan',
            'TZ' => 'Tanzania', 'TH' => 'Thailand', 'TL' => 'Timor-Leste', 'TG' => 'Togo',
            'TO' => 'Tonga', 'TT' => 'Trinidad and Tobago', 'TN' => 'Tunisia', 'TR' => 'Türkiye',
            'TM' => 'Turkmenistan', 'TV' => 'Tuvalu', 'UG' => 'Uganda', 'UA' => 'Ukraine',
            'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom', 'US' => 'United States',
            'UY' => 'Uruguay', 'UZ' => 'Uzbekistan', 'VU' => 'Vanuatu', 'VE' => 'Venezuela',
            'VN' => 'Vietnam', 'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
        ];
    }

    /** Resolve the checkout/shipping country list based on cart contents. */
    /** Adds the given country code to the map (if it exists in $allCountries) keeping original order but guaranteeing it appears last. */
    private function mergeUserCountry(array $allCountries, array $countries, ?string $countryCode): array
    {
        if ($countryCode === null || $countryCode === '') {
            return $countries;
        }
        $code = strtoupper(trim($countryCode));
        if ($code === '' || !isset($allCountries[$code])) {
            return $countries;
        }
        if (isset($countries[$code])) {
            return $countries;
        }
        $countries[$code] = $allCountries[$code];

        return $countries;
    }

    private function resolveCheckoutCountries(array $items, bool $forShipping = false): array
    {
        $allCountries = $this->getCountries();
        $hasPhysical  = false;
        $hasDigital   = false;
        foreach ($items as $item) {
            if ($item['product']->isDigital()) {
                $hasDigital = true;
            } else {
                $hasPhysical = true;
            }
        }

        // Digital-only cart: checkout rules apply, shipping rules are ignored.
        if ($hasDigital && !$hasPhysical) {
            return $this->filterCountriesByCheckoutRules($allCountries);
        }

        $shippingEnabled = $this->settings->get('shipping_enabled', '0') === '1';
        if ($forShipping && $hasPhysical && !$shippingEnabled) {
            return [];
        }

        // Mixed cart: billing uses checkout rules, shipping uses intersection.
        if ($hasDigital && $hasPhysical) {
            if ($forShipping) {
                $shippingFiltered = $this->filterCountriesByShippingRules($allCountries);
                $checkoutFiltered = $this->filterCountriesByCheckoutRules($allCountries);
                if (empty($checkoutFiltered)) {
                    return $shippingFiltered;
                }
                $intersection = array_intersect_key($shippingFiltered, $checkoutFiltered);
                if ($intersection === [] && !empty($checkoutFiltered)) {
                    return $checkoutFiltered;
                }
                return $intersection;
            }
            return $this->filterCountriesByCheckoutRules($allCountries);
        }

        // Physical-only cart: intersection of shipping and checkout rules.
        $shippingFiltered = $this->filterCountriesByShippingRules($allCountries);
        $checkoutFiltered = $this->filterCountriesByCheckoutRules($allCountries);
        if (empty($checkoutFiltered)) {
            return $shippingFiltered;
        }
        $intersection = array_intersect_key($shippingFiltered, $checkoutFiltered);
        if ($forShipping && $intersection === [] && !empty($checkoutFiltered)) {
            return $checkoutFiltered;
        }
        return $intersection;
    }

    /** Returns countries filtered by shipping_countries (allowed) and shipping_blocked_countries (blocked) settings. */
    private function filterCountriesByShippingRules(array $countries): array
    {
        $allowedRaw = $this->settings->get('shipping_countries', '')         ?? '';
        $blockedRaw = $this->settings->get('shipping_blocked_countries', '') ?? '';

        $allowed = array_filter(array_map('strtoupper', array_map('trim', explode(',', $allowedRaw))));
        $blocked = array_filter(array_map('strtoupper', array_map('trim', explode(',', $blockedRaw))));

        // Always include the company's own country.
        $companyCountryCode = $this->resolveCompanyCountryCode($countries);
        if ($companyCountryCode !== null) {
            $allowed[] = $companyCountryCode;
        }

        // Empty additional countries + no company country -> allow none.
        if (!empty($allowed)) {
            $countries = array_filter($countries, fn ($code) => in_array($code, array_values($allowed), true), ARRAY_FILTER_USE_KEY);
        } else {
            $countries = [];
        }
        if (!empty($blocked)) {
            $countries = array_filter($countries, fn ($code) => !in_array($code, array_values($blocked), true), ARRAY_FILTER_USE_KEY);
        }

        return $countries;
    }

    /** Returns countries filtered by checkout_allowed_countries and checkout_blocked_countries settings. */
    private function filterCountriesByCheckoutRules(array $countries): array
    {
        $allowedRaw = $this->settings->get('checkout_allowed_countries', '') ?? '';
        $blockedRaw = $this->settings->get('checkout_blocked_countries', '') ?? '';

        $allowed = array_filter(array_map('strtoupper', array_map('trim', explode(',', $allowedRaw))));
        $blocked = array_filter(array_map('strtoupper', array_map('trim', explode(',', $blockedRaw))));

        // Always include the company's own country.
        $companyCountryCode = $this->resolveCompanyCountryCode($countries);
        if ($companyCountryCode !== null) {
            $allowed[] = $companyCountryCode;
        }

        // Empty allowed list means all countries are allowed (no restriction).
        if (!empty($allowed)) {
            $countries = array_filter($countries, fn ($code) => in_array($code, array_values($allowed), true), ARRAY_FILTER_USE_KEY);
        }
        if (!empty($blocked)) {
            $countries = array_filter($countries, fn ($code) => !in_array($code, array_values($blocked), true), ARRAY_FILTER_USE_KEY);
        }

        return $countries;
    }

    private function resolveCompanyCountryCode(array $countries): ?string
    {
        $raw = trim((string) ($this->settings->get('shop_country', '') ?? ''));
        if ($raw === '') {
            return null;
        }

        $normalized = strtoupper($raw);
        if (strlen($normalized) === 2 && isset($countries[$normalized])) {
            return $normalized;
        }

        foreach ($countries as $code => $name) {
            if (strcasecmp($name, $raw) === 0) {
                return $code;
            }
        }

        return null;
    }

    private function getHighestItemRate(array $items): float
    {
        $ratesJson = $this->settings->get('shipping_weight_rates', '[]') ?? '[]';
        $rates     = json_decode($ratesJson, true);
        if (!is_array($rates)) {
            $rates = [];
        }

        usort($rates, static fn ($a, $b) => ($a['max_weight'] ?? 0) <=> ($b['max_weight'] ?? 0));

        $defaultRate = (float) str_replace(',', '.', $this->settings->get('shipping_default_rate', '0') ?? '0');
        $maxRate     = 0.0;

        foreach ($items as $item) {
            $product = $item['product'];
            if ($product->getShippingClass() !== 'use_global') {
                continue;
            }

            $varRow    = $item['variation_row'] ?? null;
            $varWeight = ($varRow !== null && isset($varRow['weight']) && $varRow['weight'] !== null && $varRow['weight'] !== '')
                ? (float) $varRow['weight']
                : null;
            $weight = $varWeight ?? (float) ($product->getWeight() ?? 0);
            $rate   = $defaultRate;

            foreach ($rates as $tier) {
                if ($weight <= (float) ($tier['max_weight'] ?? 0)) {
                    $rate = (float) str_replace(',', '.', (string) ($tier['rate'] ?? 0));
                    break;
                }
            }

            $maxRate = max($maxRate, $rate);
        }

        return $maxRate;
    }

    private function resolveCartItems(array $cart): array
    {
        $items    = [];
        $subtotal = 0.0;
        foreach ($cart as $cartKey => $data) {
            $qty           = is_array($data) ? (int) ($data['qty'] ?? 1) : (int) $data;
            $customAttrs   = is_array($data) ? ($data['custom'] ?? []) : [];
            $selectedAttrs = is_array($data) ? ($data['selected'] ?? []) : [];

            $parts       = explode('_', (string) $cartKey, 2);
            $productId   = (int) $parts[0];
            $variationId = isset($parts[1]) ? (int) $parts[1] : 0;

            $product = $this->products->findById($productId);
            if ($product === null) {
                continue;
            }

            $price         = $product->getEffectivePrice();
            $variationData = null;
            $variationRow  = null;

            if ($variationId > 0) {
                $variations = $this->products->findVariationsByProductId($productId);
                foreach ($variations as $v) {
                    if ((int) $v['id'] === $variationId) {
                        $variationRow  = $v;
                        $variationData = is_string($v['attributes'] ?? null) ? json_decode($v['attributes'], true) : ($v['attributes'] ?? []);
                        $variationData = $this->resolveVariationDisplayAttributes(
                            is_array($variationData) ? $variationData : [],
                            is_array($selectedAttrs) ? $selectedAttrs : [],
                        );
                        $price = !empty($v['sale_price']) && (!empty($v['sale_price_start']) || !empty($v['sale_price_end']))
                            ? (function () use ($v) {
                                $now   = new \DateTimeImmutable();
                                $start = !empty($v['sale_price_start']) ? new \DateTimeImmutable($v['sale_price_start']) : null;
                                $end   = !empty($v['sale_price_end']) ? new \DateTimeImmutable($v['sale_price_end']) : null;
                                if ($start !== null && $now < $start) {
                                    return (float) $v['price'];
                                }
                                if ($end !== null && $now >= $end) {
                                    return (float) $v['price'];
                                }
                                return (float) $v['sale_price'];
                            })()
                            : (!empty($v['sale_price']) ? (float) $v['sale_price'] : (float) $v['price']);
                        break;
                    }
                }
            }

            $price = round($price * $this->currentFxRate(), 2);

            $items[]  = [
                'product'       => $product,
                'qty'           => $qty,
                'price'         => $price,
                'variation_id'  => $variationId ?: null,
                'variation'     => $variationData,
                'variation_row' => $variationRow,
                'custom_attrs'  => $customAttrs,
            ];
            $subtotal += $price * $qty;
        }
        return [$items, $subtotal];
    }

    /**
     * Membership gate for the cart: returns the names of resolved items whose
     * product carries access_tags the current user does not hold.
     * Mirrors the single-product check in ShopController::addToCart.
     *
     * @param array<int, array{product: Product}> $items
     * @return array<int, string> product names keyed by product id
     */
    private function findRestrictedCartItems(array $items): array
    {
        $restricted = [];
        $userTags   = $this->resolveCurrentUserTags();
        foreach ($items as $item) {
            $product    = $item['product'];
            $accessTags = $product->getAccessTags();
            if ($accessTags === []) {
                continue;
            }
            if (empty(array_intersect($accessTags, $userTags))) {
                $restricted[(int) $product->getId()] = $product->getName();
            }
        }
        return $restricted;
    }

    /** @return string[] tags held by the current session user (empty for guests) */
    private function resolveCurrentUserTags(): array
    {
        if (!isset($this->subscriberRepo) || $this->subscriberRepo === null) {
            return [];
        }
        $userId = $this->session->get('user_id');
        if ($userId === null) {
            return [];
        }
        $subscriber = $this->subscriberRepo->findByUserId((int) $userId);
        return $subscriber !== null ? $subscriber->getTags() : [];
    }

    /** @param int[] $productIds */
    private function removeCartItems(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }
        $cart     = $this->session->get('cart', []);
        $filtered = [];
        foreach ($cart as $cartKey => $data) {
            $parts     = explode('_', (string) $cartKey, 2);
            $productId = (int) $parts[0];
            if (!in_array($productId, $productIds, true)) {
                $filtered[$cartKey] = $data;
            }
        }
        $this->session->set('cart', $filtered);
    }

    /** @return array<string,string> */
    private function validateBilling(array $body, array $checkoutFields): array
    {
        $errors = [];

        $required = $this->checkoutRequiredMap();
        $labels   = [
            'first_name' => 'First Name',
            'last_name'  => 'Last Name',
            'email'      => 'Email',
            'phone'      => 'Phone',
            'country'    => 'Country',
            'address'    => 'Address',
            'city'       => 'City',
            'state'      => 'State / Region',
            'postcode'   => 'Postcode',
            'note'       => 'Order Notes',
        ];
        foreach ($required as $field => $isRequired) {
            if (!$isRequired) {
                continue;
            }
            if (empty(trim((string) ($body[$field] ?? '')))) {
                $errors[$field] = $this->translator->get(':field is required.', [':field' => $this->translator->get($labels[$field] ?? ucwords(str_replace('_', ' ', $field)))]);
            }
        }

        foreach ($checkoutFields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || empty($field['required'])) {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            if ($type === 'static_text') {
                continue;
            }
            if ($type === 'checkbox') {
                $val        = $body[$name] ?? null;
                $emptyArray = is_array($val) && count($val) === 0;
                if ($val === null || $val === '' || $val === '0' || $val === 0 || $val === false || $emptyArray) {
                    $errors[$name] = $this->translator->get(':field is required.', [':field' => (string) ($field['label'] ?? ucwords(str_replace('_', ' ', $name)))]);
                }
                continue;
            }

            if (empty(trim((string) ($body[$name] ?? '')))) {
                $errors[$name] = $this->translator->get(':field is required.', [':field' => (string) ($field['label'] ?? ucwords(str_replace('_', ' ', $name)))]);
            }
        }

        if (!empty(trim((string) ($body['email'] ?? ''))) && !filter_var((string) $body['email'], FILTER_VALIDATE_EMAIL)) {
            $emailErrorField          = $this->resolveErrorFieldName('email', $checkoutFields);
            $errors[$emailErrorField] = $this->translator->get('Valid email is required.');
        }

        return $errors;
    }

    /**
     * Ensure canonical checkout keys exist even when admin used alternative field names
     * such as firstname/lastname while creating the form manually.
     *
     * @param array<string,mixed> $body
     * @param array<int,array{name:string,label:string,type:string,required:bool,options:array<int,string>}> $checkoutFields
     * @return array<string,mixed>
     */
    private function normalizeCheckoutSubmission(array $body, array $checkoutFields): array
    {
        $fieldNames = [];
        foreach ($checkoutFields as $field) {
            $name = trim((string) ($field['name'] ?? ''));
            if ($name !== '') {
                $fieldNames[] = $name;
            }
        }

        $aliases = [
            'first_name' => ['first_name', 'firstname', 'firstName'],
            'last_name'  => ['last_name', 'lastname', 'lastName', 'surname', 'soyad'],
            'email'      => ['email', 'e_mail', 'mail'],
            'phone'      => ['phone', 'telephone', 'tel'],
            'address'    => ['address', 'address1', 'street'],
            'city'       => ['city', 'town'],
            'state'      => ['state', 'province', 'region'],
            'postcode'   => ['postcode', 'postal_code', 'zip', 'zipcode'],
            'country'    => ['country', 'country_code'],
            'note'       => ['note', 'notes', 'order_note', 'ek_bilgi', 'aciklama'],
        ];

        foreach ($aliases as $canonical => $candidates) {
            if (!empty(trim((string) ($body[$canonical] ?? '')))) {
                continue;
            }

            foreach ($candidates as $candidate) {
                if (!in_array($candidate, $fieldNames, true) && !array_key_exists($candidate, $body)) {
                    continue;
                }

                $value = trim((string) ($body[$candidate] ?? ''));
                if ($value !== '') {
                    $body[$canonical] = $value;
                    break;
                }
            }
        }

        return $body;
    }

    /**
     * @param array<int,array{name:string,label:string,type:string,required:bool,options:array<int,string>}> $checkoutFields
     */
    private function resolveErrorFieldName(string $canonical, array $checkoutFields): string
    {
        $aliasMap = [
            'email' => ['email', 'e_mail', 'mail'],
        ];
        $candidates = $aliasMap[$canonical] ?? [$canonical];

        foreach ($checkoutFields as $field) {
            $name = (string) ($field['name'] ?? '');
            if (in_array($name, $candidates, true)) {
                return $name;
            }
        }

        return $canonical;
    }

    private function getSelectedCheckoutForm(): ?Form
    {
        $formId = (int) ($this->settings->get('checkout_form_id', '0') ?? '0');
        if ($formId <= 0) {
            return null;
        }

        $form = $this->forms->findById($formId);
        if ($form === null || !$form->isEnabled()) {
            return null;
        }
        return $form;
    }

    /** @return array<int, array{name:string,label:string,type:string,required:bool,options:array<int,string>}> */
    private function normalizeCheckoutFormFields(?Form $form): array
    {
        if ($form === null) {
            return [];
        }

        $allowedTypes = ['text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'tel', 'newsletter_subscribe', 'static_text'];
        $normalized   = [];

        foreach ($form->getFields() as $field) {
            $name    = trim((string) ($field['name'] ?? ''));
            $rawType = strtolower(trim((string) ($field['type'] ?? 'text')));
            if ($rawType === 'static_text') {
                $normalized[] = [
                    'name'     => '',
                    'label'    => trim((string) ($field['label'] ?? '')),
                    'type'     => 'static_text',
                    'required' => false,
                    'options'  => [],
                ];
                continue;
            }
            if ($name === '') {
                continue;
            }
            $type = $rawType;
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }

            $options = [];
            if (in_array($type, ['select', 'checkbox', 'radio'], true) && is_array($field['options'] ?? null)) {
                foreach ((array) $field['options'] as $opt) {
                    $optText = trim((string) $opt);
                    if ($optText !== '') {
                        $options[] = $optText;
                    }
                }
            }

            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                $label = ucwords(str_replace('_', ' ', $name));
            }

            $normalized[] = [
                'name'     => $name,
                'label'    => $label,
                'type'     => $type,
                'required' => (bool) ($field['required'] ?? false),
                'options'  => $options,
            ];
        }

        return $normalized;
    }

    /** @return array<string,bool> */
    private function checkoutRequiredMap(): array
    {
        $defaults = [
            'first_name' => true,
            'last_name'  => true,
            'email'      => true,
            'phone'      => true,
            'country'    => true,
            'address'    => true,
            'city'       => true,
            'state'      => true,
            'postcode'   => true,
            'note'       => false,
        ];

        $map     = [];
        $display = $this->checkoutDisplayMap();
        foreach ($defaults as $key => $default) {
            $raw       = $this->settings->get('checkout_required_' . $key, $default ? '1' : '0');
            $map[$key] = ((string) $raw === '1') && ($display[$key] ?? true);
        }

        return $map;
    }

    /** @return array<string,bool> */
    private function checkoutDisplayMap(): array
    {
        $defaults = [
            'first_name' => true,
            'last_name'  => true,
            'email'      => true,
            'phone'      => true,
            'country'    => true,
            'address'    => true,
            'city'       => true,
            'state'      => true,
            'postcode'   => true,
            'note'       => true,
        ];

        $map = [];
        foreach ($defaults as $key => $default) {
            $raw       = $this->settings->get('checkout_display_' . $key, $default ? '1' : '0');
            $map[$key] = (string) $raw === '1';
        }

        return $map;
    }

    /**
     * @param array<int,array{name:string,label:string,type:string,required:bool,options:array<int,string>}> $fields
     * @return array<int,array{name:string,label:string,type:string,required:bool,options:array<int,string>}>
     */
    private function filterCheckoutExtraFields(array $fields): array
    {
        $blocked = [
            'first_name', 'firstname', 'firstName',
            'last_name', 'lastname', 'lastName', 'surname', 'soyad',
            'email', 'e_mail', 'mail',
            'phone', 'telephone', 'tel',
            'address', 'address1', 'street',
            'city', 'town',
            'state', 'province', 'region',
            'postcode', 'postal_code', 'zip', 'zipcode',
            'country', 'country_code',
            'note', 'notes', 'order_note', 'ek_bilgi', 'aciklama',
        ];

        $result = [];
        foreach ($fields as $field) {
            $type = (string) ($field['type'] ?? 'text');
            $name = (string) ($field['name'] ?? '');
            if ($type !== 'static_text' && ($name === '' || in_array($name, $blocked, true))) {
                continue;
            }
            $result[] = $field;
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<int,array{name:string,label:string,type:string,required:bool,options:array<int,string>}> $checkoutFields
     * @return array<string,mixed>
     */
    private function extractCheckoutExtraFields(array $body, array $checkoutFields): array
    {
        $extras = [];
        foreach ($checkoutFields as $field) {
            $name = (string) ($field['name'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            if ($type === 'static_text' || $name === '') {
                continue;
            }

            $value = $body[$name] ?? null;
            if (is_array($value)) {
                $clean = array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $value), static fn ($v) => $v !== ''));
                if ($clean !== []) {
                    $extras[$name] = $clean;
                }
                continue;
            }

            $val = trim((string) $value);
            if ($val !== '') {
                $extras[$name] = $val;
            }
        }

        return $extras;
    }

    /**
     * Store checkout-attached form answers in form_submissions so form statistics/submissions are accurate.
     *
     * @param array<string,mixed> $body
     * @param array<int,array{name:string,label:string,type:string,required:bool,options:array<int,string>}> $checkoutFields
     */
    private function persistCheckoutFormSubmission(
        Request $request,
        Order $order,
        ?Form $checkoutForm,
        array $body,
        array $checkoutFields,
    ): void {
        if ($checkoutForm === null) {
            return;
        }

        $extras         = $this->extractCheckoutExtraFields($body, $checkoutFields);
        $submissionData = [
            '_source'       => 'checkout',
            '_order_number' => $order->getOrderNumber(),
            '_order_id'     => $order->getId(),
        ];

        foreach ($extras as $key => $value) {
            if (is_array($value)) {
                $submissionData[$key] = implode(', ', array_map(static fn ($v) => (string) $v, $value));
            } else {
                $submissionData[$key] = (string) $value;
            }
        }

        try {
            $ipMode     = $this->settings->get('ip_privacy_mode', 'mask') ?? 'mask';
            $anonymizer = new IpAnonymizer();

            $submission = new FormSubmission((int) $checkoutForm->getId(), $submissionData);
            $submission->setIpAddress($anonymizer->anonymize(vl_client_ip($request), $ipMode));
            $submission->setUserAgent($request->getHeaderLine('User-Agent'));
            $this->forms->saveSubmission($submission);
        } catch (\Throwable) {
            // Non-fatal: checkout should not fail if form submission logging fails.
        }
    }

    private function getGateway(string $name): ?PaymentGatewayInterface
    {
        foreach ($this->gateways as $gw) {
            if ($gw->getName() === $name) {
                return $gw;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function findVariationRow(int $productId, int $variationId): ?array
    {
        $rows = $this->products->findVariationsByProductId($productId);
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $variationId) {
                return $row;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $variation */
    private function isVariationPurchasable(array $variation): bool
    {
        $status = (string) ($variation['stock_status'] ?? 'in_stock');
        return in_array($status, ['in_stock', 'on_backorder'], true);
    }

    /**
     * @param array<string,mixed> $variationAttrs
     * @param array<string,mixed> $selectedAttrs
     * @return array<string,mixed>
     */
    private function resolveVariationDisplayAttributes(array $variationAttrs, array $selectedAttrs): array
    {
        if ($variationAttrs === []) {
            return $variationAttrs;
        }

        $display = [];
        foreach ($variationAttrs as $name => $rawValue) {
            $label = trim((string) $name);
            if ($label === '') {
                continue;
            }

            if (array_key_exists($label, $selectedAttrs)) {
                $selected = trim((string) $selectedAttrs[$label]);
                if ($selected !== '') {
                    $display[$label] = $selected;
                    continue;
                }
            }

            $display[$label] = $rawValue;
        }

        return $display;
    }

    /** @param array<string,mixed>|null $variation */
    private function resolveEffectiveMaxQty(Product $product, ?array $variation): ?int
    {
        $productTracked = $product->isStockTracked();
        $productQty     = $product->getStockQuantity();

        $variationTracked = false;
        $variationQty     = 0;
        if ($variation !== null) {
            $variationStatus  = (string) ($variation['stock_status'] ?? 'in_stock');
            $variationQty     = (int) ($variation['stock_quantity'] ?? 0);
            $variationTracked = ($variationStatus === 'in_stock' && $variationQty > 0);
        }

        if ($productTracked && $variationTracked) {
            return min($productQty, $variationQty);
        }
        if ($productTracked) {
            return $productQty;
        }
        if ($variationTracked) {
            return $variationQty;
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function orderMailContext(Order $order): array
    {
        $billing = $order->getBilling();

        return [
            'order'              => $order,
            'order_id'           => $order->getId(),
            'order_number'       => $order->getOrderNumber(),
            'order_total'        => $order->getTotal(),
            'order_status'       => $order->getStatus(),
            'shipping_estimate'  => $this->orderShippingEstimate($order),
            'customer_email'     => (string) ($billing['email'] ?? ''),
            'customer_name'      => trim((string) (($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''))),
        ];
    }

    /**
     * @return array{min: ?int, max: ?int}
     */
    private function orderShippingEstimate(Order $order): array
    {
        if ($this->deliveryEstimate === null) {
            return ['min' => null, 'max' => null];
        }

        $billing = $order->getBilling();
        $country = strtoupper(trim((string) ($billing['country'] ?? '')));

        $productIds   = [];
        $variationIds = [];
        foreach ($order->getItems() as $item) {
            $productIds[]   = (int) ($item['product_id'] ?? 0);
            $variationIds[] = (int) ($item['variation_id'] ?? 0);
        }

        $products    = [];
        foreach (array_unique(array_filter($productIds)) as $id) {
            $product = $this->products->findById($id);
            if ($product !== null) {
                $products[$id] = $product;
            }
        }

        $variationMap = [];
        foreach ($this->products->findVariationsByProductIds(array_unique(array_filter($variationIds))) as $rows) {
            foreach ($rows as $row) {
                $variationMap[(int) ($row['id'] ?? 0)] = $row;
            }
        }

        $rows = [];
        foreach ($order->getItems() as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if (!isset($products[$productId])) {
                continue;
            }
            $variationId = (int) ($item['variation_id'] ?? 0);
            $rows[]      = [
                'product'       => $products[$productId],
                'variation_row' => $variationId > 0 ? ($variationMap[$variationId] ?? null) : null,
            ];
        }

        return $this->deliveryEstimate->estimateForOrder($rows, $country !== '' ? $country : null);
    }

    /** @param array<string,string> $params */
    private function mailSubject(string $key, array $params, ?string $locale = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->get($key, $params, $locale);
        }

        return strtr($key, $params);
    }

    private function adminNotificationEmail(?string $customerEmail): ?string
    {
        $adminEmail = trim((string) ($this->settings->get('admin_email', '') ?? ''));
        if ($adminEmail === '') {
            $adminEmail = trim((string) ($this->settings->get('mail_from_address', '') ?? ''));
        }

        if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $customerEmail = strtolower(trim((string) $customerEmail));
        if ($customerEmail !== '' && strtolower($adminEmail) === $customerEmail) {
            return null;
        }

        return $adminEmail;
    }

    private function cartPath(): string
    {
        $prefix = (string) ($this->settings->get('routing_cart_prefix', '/cart') ?? '/cart');
        return '/' . ltrim($prefix, '/');
    }

    private function checkoutPath(string $suffix = ''): string
    {
        $prefix = (string) ($this->settings->get('routing_checkout_prefix', '/checkout') ?? '/checkout');
        $path   = '/' . ltrim($prefix, '/');
        if ($suffix !== '') {
            $path .= '/' . ltrim($suffix, '/');
        }

        return $path;
    }

    /** @param array<string, scalar> $queryParams */
    private function cartUrl(string $suffix = '', array $queryParams = []): string
    {
        $path = $this->cartPath();
        if ($suffix !== '') {
            $path .= '/' . ltrim($suffix, '/');
        }

        return site_url($path, [], $queryParams);
    }

    /** @param array<string, scalar> $queryParams */
    private function checkoutUrl(string $suffix = '', array $queryParams = []): string
    {
        return absolute_url($this->checkoutPath($suffix), [], $queryParams);
    }

    private function sendOrderConfirmationEmail(Order $order): void
    {
        if ($this->mail === null) {
            return;
        }
        $billing          = $order->getBilling();
        $email            = $billing['email'] ?? null;
        $policyLinks      = $this->policyLinks();
        $downloads        = $this->downloadRepo?->findGrantsByOrderId($order->getId()) ?? [];
        $refundPeriod     = $this->settings->get('refund_period_days', '14')           ?? '14';
        $refundPeriodDays = max(1, (int) $refundPeriod);
        $refundsEnabled   = $this->settings->get('refunds_enabled', '1');
        $orderStatusUrl   = $this->checkoutUrl('/success', ['order' => $order->getOrderNumber()]);
        $templateData     = $this->orderMailContext($order) + [
            'policy_links'        => $policyLinks,
            'downloads'           => $downloads,
            'refund_request'      => $this->buildRefundRequestPayload($order),
            'refund_period_days'  => $refundPeriodDays,
            'refunds_enabled'     => $refundsEnabled,
            'order_status_url'    => $orderStatusUrl,
        ];

        if (BankTransferDetailsService::isBankTransferMethod($order->getPaymentMethod())) {
            $templateData += $this->bankTransferContext($order);
        }

        $customerLocale = $order->getLocale();
        $adminLocale    = $this->settings->get('locale', 'en');

        // Send to customer
        if (!empty($email)) {
            try {
                $this->mail->sendTemplate(
                    $email,
                    $this->mailSubject('Order Confirmation — #:number', [':number' => $order->getOrderNumber()]),
                    'mail/order-confirmation',
                    $templateData,
                    locale: $customerLocale,
                    recipientType: 'customer'
                );
            } catch (\Throwable) {
                // Non-fatal
            }
        }

        // Send to admin
        $adminEmail = $this->adminNotificationEmail($email);
        if ($adminEmail !== null) {
            try {
                $this->mail->sendTemplate(
                    $adminEmail,
                    $this->mailSubject('New Order — #:number', [':number' => $order->getOrderNumber()], $adminLocale),
                    'mail/order-confirmation',
                    $templateData,
                    locale: $adminLocale,
                    recipientType: 'admin'
                );
            } catch (\Throwable) {
                // Non-fatal
            }
        }
    }

    private function sendPaymentReceivedEmail(Order $order): void
    {
        if ($this->mail === null) {
            return;
        }
        $billing     = $order->getBilling();
        $email       = $billing['email'] ?? null;
        $policyLinks = $this->policyLinks();

        $customerLocale = $order->getLocale();
        $adminLocale    = $this->settings->get('locale', 'en');

        // Send to customer
        if (!empty($email)) {
            $downloads = $this->downloadRepo?->findGrantsByOrderId($order->getId()) ?? [];
            try {
                $this->mail->sendTemplate(
                    $email,
                    $this->mailSubject('Payment Received — #:number', [':number' => $order->getOrderNumber()]),
                    'mail/payment-received',
                    $this->orderMailContext($order) + [
                        'policy_links'     => $policyLinks,
                        'downloads'        => $downloads,
                        'refund_request'   => $this->buildRefundRequestPayload($order),
                        'order_status_url' => $this->checkoutUrl('/success', ['order' => $order->getOrderNumber()]),
                    ],
                    locale: $customerLocale,
                    recipientType: 'customer'
                );
            } catch (\Throwable) {
                // Non-fatal
            }
        }

        // Send to admin
        $adminEmail = $this->adminNotificationEmail($email);
        if ($adminEmail !== null) {
            try {
                $this->mail->sendTemplate(
                    $adminEmail,
                    $this->mailSubject('Payment Received — Order #:number', [':number' => $order->getOrderNumber()], $adminLocale),
                    'mail/payment-received',
                    $this->orderMailContext($order) + [
                        'is_admin'         => true,
                        'downloads'        => $this->downloadRepo?->findGrantsByOrderId($order->getId()) ?? [],
                        'refund_request'   => $this->buildRefundRequestPayload($order),
                        'order_status_url' => $this->checkoutUrl('/success', ['order' => $order->getOrderNumber()]),
                    ],
                    locale: $adminLocale,
                    recipientType: 'admin'
                );
            } catch (\Throwable) {
                // Non-fatal
            }
        }
    }

    private function sendRefundAutoApprovedEmails(Order $order, string $reason): void
    {
        if ($this->mail === null) {
            return;
        }

        $billing       = $order->getBilling();
        $customerEmail = trim((string) ($billing['email'] ?? ''));
        $adminEmail    = trim((string) ($this->settings->get('admin_email', '') ?? ''));
        if ($adminEmail === '') {
            $adminEmail = trim((string) ($this->settings->get('mail_from_address', '') ?? ''));
        }

        $customerLocale = $order->getLocale();
        $adminLocale    = $this->settings->get('locale', 'en');

        if ($customerEmail !== '') {
            try {
                $this->mail->sendTemplate(
                    $customerEmail,
                    $this->translator?->get('Refund Request Approved — #:number', [':number' => $order->getOrderNumber()]) ?? 'Refund Request Approved',
                    'mail/refund-request-auto-approved',
                    [
                        'order'            => $order,
                        'reason'           => $reason,
                        'order_status_url' => $this->checkoutUrl('/success', ['order' => $order->getOrderNumber()]),
                    ],
                    locale: $customerLocale,
                    recipientType: 'customer'
                );
            } catch (\Throwable) {
            }
        }

        if ($adminEmail !== '') {
            try {
                $this->mail->sendTemplate(
                    $adminEmail,
                    $this->translator?->get('Refund Auto-Approved — Order #:number', [':number' => $order->getOrderNumber()], $adminLocale) ?? 'Refund Auto-Approved',
                    'mail/refund-request-auto-approved',
                    ['order' => $order, 'reason' => $reason, 'order_status_url' => $this->checkoutUrl('/success', ['order' => $order->getOrderNumber()])],
                    locale: $adminLocale,
                    recipientType: 'admin'
                );
            } catch (\Throwable) {
            }
        }
    }

    private function sendRefundRequestedEmails(Order $order, string $reason): void
    {
        if ($this->mail === null) {
            return;
        }

        $billing       = $order->getBilling();
        $customerEmail = trim((string) ($billing['email'] ?? ''));
        $adminEmail    = trim((string) ($this->settings->get('admin_email', '') ?? ''));
        if ($adminEmail === '') {
            $adminEmail = trim((string) ($this->settings->get('mail_from_address', '') ?? ''));
        }

        $customerLocale = $order->getLocale();
        $adminLocale    = $this->settings->get('locale', 'en');

        if ($customerEmail !== '') {
            try {
                $this->mail->sendTemplate(
                    $customerEmail,
                    $this->translator?->get('Refund Request Received — #:number', [':number' => $order->getOrderNumber()]) ?? 'Refund Request Received',
                    'mail/refund-request-customer',
                    [
                        'order'            => $order,
                        'reason'           => $reason,
                        'order_status_url' => $this->checkoutUrl('/success', ['order' => $order->getOrderNumber()]),
                    ],
                    locale: $customerLocale,
                    recipientType: 'customer'
                );
            } catch (\Throwable) {
            }
        }

        if ($adminEmail !== '') {
            try {
                $this->mail->sendTemplate(
                    $adminEmail,
                    $this->translator?->get('Refund Request Submitted — Order #:number', [':number' => $order->getOrderNumber()], $adminLocale) ?? 'Refund Request Submitted',
                    'mail/refund-request-admin',
                    ['order' => $order, 'reason' => $reason],
                    locale: $adminLocale,
                    recipientType: 'admin'
                );
            } catch (\Throwable) {
            }
        }
    }

    private function isRefundRequestEligible(Order $order): bool
    {
        if ($this->settings->get('refunds_enabled', '1') !== '1') {
            return false;
        }

        $status = $order->getStatus();
        if (in_array($status, ['cancelled', 'refunded', 'refund_requested'], true)) {
            return false;
        }

        $createdAt = $order->getCreatedAt();
        if ($createdAt === null) {
            return false;
        }

        $days     = max(1, (int) ($this->settings->get('refund_period_days', '14') ?? '14'));
        $deadline = $createdAt->modify('+' . $days . ' days');
        if ($deadline < new \DateTimeImmutable()) {
            return false;
        }

        $summary = $this->analyzeOrderRefundability($order);
        return !$summary['all_non_refundable'];
    }

    /** @return array{status:string,reason:?string,requested_at:?string,deadline:?string} */
    private function buildRefundRequestPayload(Order $order): array
    {
        $reason      = null;
        $requestedAt = null;

        $logs = $this->orderLogs?->findByOrderId($order->getId()) ?? [];
        foreach ($logs as $log) {
            if ($log->getEvent() === 'refund_requested') {
                $requestedAt = $log->getCreatedAt()->format('Y-m-d H:i');
                $note        = (string) ($log->getNote() ?? '');
                if (str_starts_with($note, 'Customer reason: ')) {
                    $reason = trim(substr($note, strlen('Customer reason: ')));
                }
            }
            if ($log->getEvent() === 'refund_request_rejected') {
                $note = (string) ($log->getNote() ?? '');
                if (str_starts_with($note, 'Reason: ')) {
                    $reason = trim(substr($note, strlen('Reason: ')));
                }
            }
        }

        $days     = max(1, (int) ($this->settings->get('refund_period_days', '14') ?? '14'));
        $deadline = $order->getCreatedAt()?->modify('+' . $days . ' days')->format('Y-m-d H:i');

        $nonRefundableSummary = $this->analyzeOrderRefundability($order);

        return [
            'status'                  => $order->getStatus(),
            'reason'                  => $reason,
            'requested_at'            => $requestedAt,
            'deadline'                => $deadline,
            'has_non_refundable'      => $nonRefundableSummary['has_non_refundable'],
            'has_refundable'          => $nonRefundableSummary['has_refundable'],
            'non_refundable_count'    => $nonRefundableSummary['non_refundable_count'],
            'all_non_refundable'      => $nonRefundableSummary['all_non_refundable'],
            'non_refundable_reasons'  => $nonRefundableSummary['reasons'],
        ];
    }

    /**
     * Resolves effective refund mode for a product + variation.
     */
    public function resolveEffectiveRefundMode(?Product $product, ?array $variation = null): string
    {
        if ($variation !== null && !empty($variation['refund_mode']) && $variation['refund_mode'] !== 'use_parent') {
            return $variation['refund_mode'];
        }

        if ($product !== null && $product->getRefundMode() !== 'use_global') {
            return $product->getRefundMode();
        }

        return 'allow';
    }

    /**
     * Analyzes whether items in an order are non-refundable (due to policy or digital download).
     *
     * @return array{has_non_refundable: bool, has_refundable: bool, non_refundable_count: int, total_items: int, all_non_refundable: bool, reasons: string[]}
     */
    public function analyzeOrderRefundability(Order $order): array
    {
        $items = $order->getItems();
        if (empty($items)) {
            return [
                'has_non_refundable'     => false,
                'has_refundable'         => false,
                'non_refundable_count'   => 0,
                'total_items'            => 0,
                'all_non_refundable'     => false,
                'reasons'                => [],
            ];
        }

        // Fetch download grants for order
        $grants             = $this->downloadRepo?->findGrantsByOrderId($order->getId()) ?? [];
        $downloadedGrantIds = [];
        foreach ($grants as $grant) {
            if ((int) ($grant['download_count'] ?? 0) > 0) {
                $downloadedGrantIds[(int) $grant['id']] = true;
            }
        }

        // Batch-load products and variations to avoid N+1 queries
        $productIds   = array_filter(array_map(static fn ($i) => (int) ($i['product_id'] ?? 0), $items));
        $variationIds = array_filter(array_map(static fn ($i) => (int) ($i['variation_id'] ?? 0), $items));

        $loadedProducts = [];
        if (!empty($productIds)) {
            foreach ($this->products->findByIds($productIds) as $p) {
                $loadedProducts[(int) $p->getId()] = $p;
            }
        }

        $loadedVariations = [];
        if (!empty($variationIds)) {
            $rowsMap = $this->products->findVariationsByProductIds($productIds);
            foreach ($rowsMap as $rows) {
                foreach ($rows as $row) {
                    $loadedVariations[(int) ($row['id'] ?? 0)] = $row;
                }
            }
        }

        $nonRefundableCount = 0;
        $refundableCount    = 0;
        $reasons            = [];

        foreach ($items as $item) {
            $productId   = (int) ($item['product_id'] ?? 0);
            $variationId = (int) ($item['variation_id'] ?? 0);
            $product     = $loadedProducts[$productId]     ?? null;
            $variation   = $loadedVariations[$variationId] ?? null;

            $mode = $this->resolveEffectiveRefundMode($product, $variation);

            // Check if digital file has been downloaded
            $isDownloadedDigital = false;
            if ($this->downloadRepo !== null) {
                foreach ($grants as $grant) {
                    if ((int) ($grant['product_id'] ?? 0) === $productId) {
                        if ($variationId > 0 && (int) ($grant['variation_id'] ?? 0) !== $variationId) {
                            continue;
                        }
                        if ((int) ($grant['download_count'] ?? 0) > 0) {
                            $isDownloadedDigital = true;
                            break;
                        }
                    }
                }
            }

            if ($mode === 'non_refundable' || $isDownloadedDigital) {
                $nonRefundableCount++;
                if ($isDownloadedDigital) {
                    $reasons[] = $this->translator?->get('Digital item ":name" has already been downloaded.', [':name' => $item['name'] ?? '']) ?? 'Digital item downloaded';
                } else {
                    $reasons[] = $this->translator?->get('":name" is marked as non-refundable.', [':name' => $item['name'] ?? '']) ?? 'Non-refundable item';
                }
            } else {
                $refundableCount++;
            }
        }

        $total = count($items);
        return [
            'has_non_refundable'    => $nonRefundableCount > 0,
            'has_refundable'        => $refundableCount    > 0,
            'non_refundable_count'  => $nonRefundableCount,
            'total_items'           => $total,
            'all_non_refundable'    => $nonRefundableCount === $total,
            'reasons'               => array_values(array_unique($reasons)),
        ];
    }

    /**
     * Builds policy page URLs from settings (returns/privacy/terms).
     * Only includes keys where a page slug has been configured.
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
     * Returns the currency code to use for the order.
     * Priority: session-selected currency -> store default from settings.
     * Validates against allowed/blocked lists.
     */
    private function activeOrderCurrency(): string
    {
        $default     = strtoupper($this->settings->get('currency', 'USD') ?? 'USD');
        $fromSession = strtoupper($this->session->get('selected_currency', '') ?? '');

        if ($fromSession === '') {
            return $default;
        }

        $allowedRaw = $this->settings->get('allowed_currencies', '')  ?? '';
        $blockedRaw = $this->settings->get('blocked_currencies', '')  ?? '';
        $allowed    = array_filter(array_map('strtoupper', array_map('trim', explode(',', $allowedRaw))));
        $blocked    = array_filter(array_map('strtoupper', array_map('trim', explode(',', $blockedRaw))));

        $ok = in_array($fromSession, array_values($allowed), true)
           && !in_array($fromSession, array_values($blocked), true);

        return $ok ? $fromSession : $default;
    }

    /**
     * Scope-aware coupon discount for resolved cart items.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array{discount: float, lines: array<int, float>}
     */
    private function couponDiscountForItems(Coupon $coupon, array $items): array
    {
        $fxRate   = $this->currentFxRate();
        $lines    = CouponValidator::cartLines($items);
        $discount = $coupon->calculateDiscountForLines($lines, $fxRate);
        return ['discount' => $discount, 'lines' => $coupon->allocateDiscount($lines, $fxRate)];
    }

    /**
     * Human-friendly coupon saving for the success message: "10%" for
     * percent coupons, converted currency amount for fixed coupons.
     */
    private function formatCouponSaving(Coupon $coupon, float $discount): string
    {
        if ($coupon->getType() === 'percent') {
            $pct   = (float) $coupon->getAmount();
            $label = $pct === floor($pct) ? (string) (int) $pct : rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
            return $label . '%';
        }

        $currency = $this->activeOrderCurrency();
        if (extension_loaded('intl')) {
            try {
                $locale = strtolower($this->translator?->getLocale() ?? 'en');
                $fmt    = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
                $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
                $fmt->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);
                $result = $fmt->formatCurrency($discount, $currency);
                if ($result !== false) {
                    return $result;
                }
            } catch (\Throwable) {
            }
        }
        return self::currencySymbol($currency) . number_format($discount, 2);
    }

    private static function currencySymbol(string $currency): string
    {
        static $symbols = null;
        if ($symbols === null) {
            $symbols = [];
            $refData = require dirname(__DIR__, 3) . '/config/reference-data.php';
            foreach (($refData['currencies'] ?? []) as $c) {
                $code = strtoupper((string) ($c['code'] ?? ''));
                $sym  = (string) ($c['symbol'] ?? '');
                if ($code !== '' && $sym !== '') {
                    $symbols[$code] = $sym;
                }
            }
        }
        return $symbols[strtoupper($currency)] ?? (strtoupper($currency) . ' ');
    }

    /**
     * @param int[] $cartProductIds
     * @param int[] $cartCategoryIds
     * @param int[] $cartVariationIds
     * @return array{valid: bool, coupon: Coupon|null, error: string|null}
     */
    private function validateCoupon(
        string $code,
        float $subtotal,
        string $email,
        array $cartProductIds = [],
        array $cartCategoryIds = [],
        array $cartVariationIds = [],
    ): array {
        if ($this->couponValidator !== null) {
            return $this->couponValidator->validateForCart(
                $code,
                $subtotal,
                $this->session->get('user_id'),
                $email,
                $cartProductIds,
                $cartCategoryIds,
                $cartVariationIds,
            );
        }

        $coupon = $this->coupons->findByCode($code);
        if ($coupon === null || !$coupon->isValid($subtotal)) {
            return ['valid' => false, 'coupon' => null, 'error' => 'Invalid or expired coupon code.'];
        }
        if ($coupon->isOnlyUsers() && $this->session->get('user_id') === null) {
            return ['valid' => false, 'coupon' => null, 'error' => 'This coupon requires you to be logged in.'];
        }
        return ['valid' => true, 'coupon' => $coupon, 'error' => null];
    }

    /**
     * Free-text PII stored on the user profile billing/shipping JSON that is
     * encrypted at rest. Plaintext: city, state, postcode, country.
     */
    private const USER_ADDRESS_ENCRYPTED_FIELDS = ['first_name', 'last_name', 'phone', 'company', 'address_1', 'address_2'];

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private function encryptUserAddressFields(array $address): array
    {
        if ($this->encrypter === null) {
            return $address;
        }
        foreach (self::USER_ADDRESS_ENCRYPTED_FIELDS as $field) {
            if (isset($address[$field]) && $address[$field] !== '') {
                $address[$field] = $this->encrypter->encrypt((string) $address[$field]);
            }
        }
        return $address;
    }

    /**
     * @param array<string,mixed> $address
     * @return array<string,mixed>
     */
    private function decryptUserAddressFields(array $address): array
    {
        if ($this->encrypter === null || $address === []) {
            return $address;
        }
        foreach (self::USER_ADDRESS_ENCRYPTED_FIELDS as $field) {
            if (isset($address[$field]) && $address[$field] !== '') {
                $address[$field] = $this->encrypter->decrypt((string) $address[$field]);
            }
        }
        return $address;
    }
}
