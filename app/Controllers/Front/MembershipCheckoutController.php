<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Membership\SubscriptionService;
use ValeApp\Application\Shop\CouponValidator;
use ValeApp\Domain\Membership\Entity\MembershipPlan;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Entity\Order;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\ExchangeRate\ExchangeRateService;
use ValeApp\Infrastructure\Payment\PayPalGateway;
use ValeApp\Infrastructure\Payment\StripeGateway;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

final class MembershipCheckoutController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly PlanRepositoryInterface $plans,
        private readonly SessionManager $session,
        private readonly DbalSettingsRepository $settings,
        private readonly Connection $db,
        private readonly SubscriptionService $subscriptionService,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly StripeGateway $stripe,
        private readonly PayPalGateway $paypal,
        private readonly MailService $mailer,
        private readonly UserRepositoryInterface $users,
        private readonly ExchangeRateService $exchangeRates,
        private readonly ?OrderLogRepositoryInterface $orderLogs = null,
        private readonly ?CouponRepositoryInterface $coupons = null,
        private readonly ?CouponValidator $couponValidator = null,
        private readonly ?OrderRepositoryInterface $orders = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $plans              = $this->plans->findAllActive();
        $activeSubscription = null;
        $userId             = $this->session->get('user_id');

        if ($userId !== null) {
            $sub = $this->subscriptionService->findActiveByUserId((int) $userId);
            if ($sub !== null) {
                $plan               = $this->plans->findById((int) $sub['plan_id']);
                $sub['plan']        = $plan;
                $activeSubscription = $sub;
            }
        }

        $stripeKey = $this->settings->get('stripe_publishable_key', '');

        return $this->twig->render($response, 'membership/plans.twig', [
            'plans'               => $plans,
            'active_subscription' => $activeSubscription,
            'stripe_key'          => $stripeKey,
        ]);
    }

    public function checkout(Request $request, Response $response, array $args): Response
    {
        $planId = (int) ($args['id'] ?? 0);
        $plan   = $this->plans->findById($planId);
        if ($plan === null || !$plan->isActive()) {
            $this->session->flash('error', 'Plan not found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $userId = $this->session->get('user_id');
        if ($userId === null) {
            $this->session->set('redirect_after_login', $this->membershipPrefix() . '/checkout/' . $planId);
            return $response->withHeader('Location', site_url('/login') . '?redirect=' . urlencode($this->membershipPrefix() . '/checkout/' . $planId))->withStatus(302);
        }

        $subscriber = $this->subscribers->findByUserId((int) $userId);
        if ($subscriber === null) {
            $user = $this->users->findById((int) $userId);
            if ($user !== null) {
                $emailStr    = (string) $user->getEmail();
                $existingSub = $this->subscribers->findByEmail($emailStr);
                if ($existingSub !== null) {
                    $existingSub->setUserId($user->getId());
                    $this->subscribers->save($existingSub);
                    $subscriber = $existingSub;
                } else {
                    $subscriber = new Subscriber($emailStr, bin2hex(random_bytes(16)));
                    $subscriber->setName($user->getName());
                    $subscriber->setStatus('active');
                    $subscriber->setUserId($user->getId());
                    $subscriber->setTier('free');
                    $subscriber->setSubscribedAt(new \DateTimeImmutable());
                    $subscriber->confirm();
                    $this->subscribers->save($subscriber);
                }
            } else {
                $this->session->flash('error', 'User not found.');
                return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
            }
        }

        $existingSub = $this->subscriptionService->findActiveByUserId((int) $userId);
        if ($existingSub !== null) {
            $this->session->flash('error', 'You already have an active subscription.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $stripeEnabled      = (bool) $this->settings->get('stripe_enabled', '0');
        $paypalEnabled      = (bool) $this->settings->get('paypal_enabled', '0');
        $stripeKey          = $this->settings->get('stripe_publishable_key', '');
        $stripeLabel        = $this->settings->get('stripe_label', 'Credit / Debit Card');
        $paypalLabel        = $this->settings->get('paypal_label', 'PayPal');

        return $this->twig->render($response, 'membership/checkout.twig', [
            'plan'             => $plan,
            'stripe_key'       => $stripeKey,
            'stripe_enabled'   => $stripeEnabled,
            'paypal_enabled'   => $paypalEnabled,
            'stripe_label'     => $stripeLabel,
            'paypal_label'     => $paypalLabel,
            'policy_links'     => $this->policyLinks(),
        ]);
    }

    public function createSubscription(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $planId = (int) ($body['plan_id'] ?? 0);

        $plan = $this->plans->findById($planId);
        if ($plan === null || !$plan->isActive()) {
            $this->session->flash('error', 'Plan not found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $userId = $this->session->get('user_id');
        if ($userId === null) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $subscriber = $this->subscribers->findByUserId((int) $userId);
        if ($subscriber === null) {
            $this->session->flash('error', 'Subscriber not found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $user = $this->fetchUser((int) $userId);
        if ($user === null) {
            $this->session->flash('error', 'User not found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $paymentMethod = $body['payment_method'] ?? $plan->getGateway();

        $couponCode = strtoupper(trim((string) ($body['coupon_code'] ?? '')));
        $discount   = 0.0;
        $duration   = 'once';

        if ($couponCode !== '' && $this->coupons !== null) {
            $result = $this->validatePlanCoupon($couponCode, $planId);
            if (!$result['valid']) {
                return $this->couponRedirectError($response, $planId, (string) $result['error']);
            }

            /** @var Coupon $coupon */
            $coupon = $result['coupon'];

            $userEmail = strtolower(trim((string) ($user['email'] ?? '')));

            if ($coupon->isOnlyUsers() && $userId === null) {
                return $this->couponRedirectError($response, $planId, 'This coupon requires you to be logged in.');
            }

            $allowedEmails = $coupon->getAllowedEmails();
            if (!empty($allowedEmails) && !in_array($userEmail, $allowedEmails, true)) {
                return $this->couponRedirectError(
                    $response,
                    $planId,
                    'This coupon is not valid for your email address.'
                );
            }

            if ($coupon->getMaxUsesPerEmail() > 0 && $userEmail !== '') {
                $emailUses = $this->countCouponUsesForEmail($coupon->getCode(), $userEmail);
                if ($emailUses >= $coupon->getMaxUsesPerEmail()) {
                    return $this->couponRedirectError(
                        $response,
                        $planId,
                        'You have already reached the maximum usage limit for this coupon.'
                    );
                }
            }

            $discount = $coupon->calculateDiscount((float) $plan->getPrice());
            $duration = $coupon->getMembershipDuration();
        }

        $this->session->set('membership_coupon_code_' . $planId, $couponCode ?: null);
        $this->session->set('membership_discount_amount_' . $planId, $discount > 0 ? $discount : null);
        $this->session->set('membership_coupon_duration_' . $planId, $duration);

        return match ($paymentMethod) {
            'stripe' => $this->createStripeSubscription($request, $response, $plan, $subscriber, $user, $discount, $duration, $couponCode),
            'paypal' => $this->createPayPalSubscription($request, $response, $plan, $subscriber, $user, $discount, $duration, $couponCode),
            default  => $this->createStripeSubscription($request, $response, $plan, $subscriber, $user, $discount, $duration, $couponCode),
        };
    }

    public function stripeReturn(Request $request, Response $response): Response
    {
        $sessionId = $request->getQueryParams()['session_id'] ?? '';
        error_log('[Membership] Stripe return: session_id=' . $sessionId);
        if ($sessionId === '') {
            $this->session->flash('error', 'Stripe session ID missing.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $userId = $this->session->get('user_id');
        if ($userId === null) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $planId = (int) ($this->session->get('stripe_pending_plan_id', 0));
        if ($planId === 0) {
            $this->session->flash('error', 'Session expired. Please try again.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $plan = $this->plans->findById($planId);
        if ($plan === null) {
            $this->session->flash('error', 'Plan not found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $subscriber = $this->subscribers->findByUserId((int) $userId);
        if ($subscriber === null) {
            $this->session->flash('error', 'Subscriber not found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $user = $this->fetchUser((int) $userId);

        try {
            $session = $this->stripe->retrieveCheckoutSession($sessionId);

            if ($session['payment_status'] !== 'paid' && $session['payment_status'] !== 'no_payment_required') {
                throw new \RuntimeException('Payment not completed.');
            }

            $stripeSubId = $session['subscription'] ?? ('LIFETIME-' . ($session['payment_intent'] ?? $sessionId));

            $trialEndsAt = $plan->getTrialDays() > 0
                ? new \DateTimeImmutable('+' . $plan->getTrialDays() . ' days')
                : null;

            $this->subscriptionService->createSubscription(
                (int) $userId,
                $plan,
                'stripe',
                $stripeSubId,
                $plan->getTrialDays() > 0 ? 'trialing' : 'active',
                $trialEndsAt,
            );

            if ($plan->getTrialDays() > 0 && $plan->getTrialTag() !== null && $plan->getTrialTag() !== '') {
                $this->subscriptionService->assignTrialTag($subscriber, $plan);
            } else {
                $this->subscriptionService->assignPlanTag($subscriber, $plan);
            }

            $customerData = [
                'name'  => $user['username'] ?? '',
                'email' => $user['email']    ?? '',
            ];

            $couponCode     = $this->session->get('membership_coupon_code_' . $planId);
            $discountAmount = (float) ($this->session->get('membership_discount_amount_' . $planId, 0.0));

            $currency       = $this->orderCurrency();
            $fxRate         = $this->orderFxRate();
            $basePrice      = (float) $plan->getPrice();
            $priceToRecord  = $basePrice;

            if ($discountAmount > 0.0) {
                $priceToRecord = max(0.0, $basePrice - $discountAmount);
            }

            $convertedPrice = round($priceToRecord * $fxRate, 2);

            // A pure free trial charges nothing at signup — recording a
            // completed zero payment would let the trial sweep flip it to
            // active early. Paid trials / discounted signups still record.
            $isFreeTrialSignup = $plan->getTrialDays() > 0 && $discountAmount <= 0.0;
            $amountCharged     = (int) ($session['amount_total'] ?? 0);

            if ($amountCharged > 0 || !$isFreeTrialSignup) {
                $orderId = $this->subscriptionService->recordPayment(
                    (int) $userId,
                    $plan,
                    'stripe',
                    $stripeSubId,
                    (string) $convertedPrice,
                    $currency,
                    $customerData,
                    'signup:' . $stripeSubId,
                );

                $this->stampMembershipCoupon((int) $orderId, $planId);
            }

            $this->orderLogs?->add(
                $orderId,
                'order_created',
                'Subscription created via Stripe Checkout.',
                'system',
                (int) $userId,
                $user['username'] ?? '',
            );

            $localSub   = $this->subscriptionService->findActiveByUserId((int) $userId);
            $subExpires = $localSub['expires_at'] ?? null;
            $this->sendSubscriptionMail(
                'welcome',
                (int) $userId,
                $plan->getName(),
                $subExpires ? (new \DateTimeImmutable($subExpires))->format('Y-m-d') : null,
                (string) $convertedPrice,
            );

            $this->session->set('stripe_pending_plan_id', null);
            $this->clearMembershipCouponSession($planId);
            $this->session->flash('success', 'Subscription created successfully!');
            return $response->withHeader('Location', $this->accountPrefix() . '/subscription')->withStatus(302);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Stripe subscription failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }
    }


    public function paypalReturn(Request $request, Response $response): Response
    {
        $params     = $request->getQueryParams();
        $safeParams = array_intersect_key($params, array_flip(['token', 'ba_token', 'paymentId', 'PayerID']));
        error_log('[Membership] PayPal return: ' . json_encode($safeParams));

        $token   = $params['token']    ?? '';
        $baToken = $params['ba_token'] ?? '';

        $userId = $this->session->get('user_id');
        if ($userId === null) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $planId = (int) ($this->session->get('paypal_pending_plan_id', 0));
        if ($planId === 0) {
            $this->session->flash('error', 'Session expired. Please try again.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $plan = $this->plans->findById($planId);
        if ($plan === null) {
            $this->session->flash('error', 'Plan not found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        // Handle one-time payment return
        if ($plan->getInterval() === 'one_time') {
            $paymentId = $params['paymentId'] ?? $token;
            if ($paymentId === '') {
                $this->session->flash('error', 'PayPal payment ID missing.');
                return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
            }
            return $this->paypalOneTimeReturn($request, $response, $paymentId);
        }

        $agreementToken = $token !== '' ? $token : $baToken;
        if ($agreementToken === '') {
            $this->session->flash('error', 'PayPal return token missing.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $subscriber = $this->subscribers->findByUserId((int) $userId);
        if ($subscriber === null) {
            $this->session->flash('error', 'Subscriber not found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $user = $this->fetchUser((int) $userId);

        try {
            $agreement = $this->paypal->executeBillingAgreement($agreementToken);

            $trialEndsAt = $plan->getTrialDays() > 0
                ? new \DateTimeImmutable('+' . $plan->getTrialDays() . ' days')
                : null;

            $this->subscriptionService->createSubscription(
                (int) $userId,
                $plan,
                'paypal',
                $agreement['id'],
                $plan->getTrialDays() > 0 ? 'trialing' : 'active',
                $trialEndsAt,
            );

            if ($plan->getTrialDays() > 0 && $plan->getTrialTag() !== null && $plan->getTrialTag() !== '') {
                $this->subscriptionService->assignTrialTag($subscriber, $plan);
            } else {
                $this->subscriptionService->assignPlanTag($subscriber, $plan);
            }

            $customerData = [
                'name'  => $user['username'] ?? '',
                'email' => $user['email']    ?? '',
            ];

            $couponCode     = $this->session->get('membership_coupon_code_' . $planId);
            $discountAmount = (float) ($this->session->get('membership_discount_amount_' . $planId, 0.0));

            $currency       = $this->orderCurrency();
            $fxRate         = $this->orderFxRate();
            $basePrice      = (float) $plan->getPrice();
            $priceToRecord  = $basePrice;

            if ($discountAmount > 0.0) {
                $priceToRecord = max(0.0, $basePrice - $discountAmount);
            }

            $convertedPrice = round($priceToRecord * $fxRate, 2);

            // Pure free trial: PayPal charges nothing at agreement execution.
            // Skip the signup record so the trial sweep stays authoritative.
            $isFreeTrialSignup = $plan->getTrialDays() > 0 && $discountAmount <= 0.0;
            $setupFeeCharged   = (float) ($this->session->get('membership_setup_fee_' . $planId, 0.0));

            if (!$isFreeTrialSignup || $setupFeeCharged > 0.0) {
                $orderId = $this->subscriptionService->recordPayment(
                    (int) $userId,
                    $plan,
                    'paypal',
                    $agreement['id'],
                    (string) $convertedPrice,
                    $currency,
                    $customerData,
                    'signup:' . $agreement['id'],
                );

                $this->stampMembershipCoupon((int) $orderId, $planId);
            }

            $this->orderLogs?->add(
                $orderId,
                'order_created',
                'Subscription created via PayPal.',
                'system',
                (int) $userId,
                $user['username'] ?? '',
            );

            $localSub   = $this->subscriptionService->findActiveByUserId((int) $userId);
            $subExpires = $localSub['expires_at'] ?? null;
            $this->sendSubscriptionMail(
                'welcome',
                (int) $userId,
                $plan->getName(),
                $subExpires ? (new \DateTimeImmutable($subExpires))->format('Y-m-d') : null,
                (string) $convertedPrice,
            );

            $this->session->set('paypal_pending_plan_id', null);
            $this->clearMembershipCouponSession($planId);
            $this->session->flash('success', 'PayPal subscription created successfully!');
            return $response->withHeader('Location', $this->accountPrefix() . '/subscription')->withStatus(302);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'PayPal subscription failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }
    }

    private function paypalOneTimeReturn(Request $request, Response $response, string $paymentId): Response
    {
        $userId = $this->session->get('user_id');
        $planId = (int) ($this->session->get('paypal_pending_plan_id', 0));

        $plan = $this->plans->findById($planId);
        if ($plan === null) {
            $this->session->flash('error', 'Plan not found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        // Idempotency: a webhook may already have completed this purchase.
        $gatewaySubId = 'LIFETIME-' . $paymentId;
        if ($this->subscriptionService->findByGatewayId($gatewaySubId) !== null) {
            $this->session->set('paypal_pending_plan_id', null);
            $this->clearMembershipCouponSession($planId);
            $this->session->flash('info', 'Life-time membership is already active.');
            return $response->withHeader('Location', $this->accountPrefix() . '/subscription')->withStatus(302);
        }

        $subscriber = $this->subscribers->findByUserId((int) $userId);
        $user       = $this->fetchUser((int) $userId);

        try {
            try {
                $result = $this->paypal->verifyPayment($paymentId);
            } catch (\Throwable) {
                // The webhook may have captured the order milliseconds before
                // the browser returned (ORDER_ALREADY_CAPTURED). Check the
                // order status instead of failing the purchase.
                $result = $this->paypal->getOrderStatus($paymentId)
                    ?? throw new \RuntimeException('Payment could not be verified.');
            }

            if ($result['status'] !== 'paid') {
                throw new \RuntimeException('Payment not completed.');
            }

            $this->subscriptionService->createSubscription(
                (int) $userId,
                $plan,
                'paypal',
                'LIFETIME-' . $paymentId,
                'active',
                null,
            );

            $this->subscriptionService->assignPlanTag($subscriber, $plan);

            $couponCode     = $this->session->get('membership_coupon_code_' . $planId);
            $discountAmount = (float) ($this->session->get('membership_discount_amount_' . $planId, 0.0));

            $currency      = $this->orderCurrency();
            $fxRate        = $this->orderFxRate();
            $basePrice     = (float) $plan->getPrice();
            $priceToRecord = $basePrice;

            if ($discountAmount > 0.0) {
                $priceToRecord = max(0.0, $basePrice - $discountAmount);
            }

            $price    = round($priceToRecord * $fxRate, 2);

            $orderId = $this->subscriptionService->recordPayment(
                (int) $userId,
                $plan,
                'paypal',
                $gatewaySubId,
                (string) $price,
                $currency,
                [
                    'name'  => $user['username'] ?? '',
                    'email' => $user['email']    ?? '',
                ]
            );

            $this->stampMembershipCoupon((int) $orderId, $planId);

            $this->session->set('paypal_pending_plan_id', null);
            $this->clearMembershipCouponSession($planId);
            $this->session->flash('success', 'Life-time membership active!');
            return $response->withHeader('Location', $this->accountPrefix() . '/subscription')->withStatus(302);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'PayPal payment failed: ' . $e->getMessage());
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }
    }

    public function cancel(Request $request, Response $response): Response
    {
        $userId = $this->session->get('user_id');

        if ($request->getMethod() === 'GET') {
            $this->session->flash('info', 'Subscription checkout cancelled.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        if ($userId === null) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $sub = $this->subscriptionService->findActiveByUserId((int) $userId);
        if ($sub === null) {
            $this->session->flash('error', 'No active subscription found.');
            return $response->withHeader('Location', $this->membershipPrefix())->withStatus(302);
        }

        $user = $this->fetchUser((int) $userId);

        try {
            $gateway      = $sub['gateway']                 ?? '';
            $gatewaySubId = $sub['gateway_subscription_id'] ?? '';

            if ($gateway === 'stripe' && $gatewaySubId !== '') {
                $this->stripe->cancelSubscription($gatewaySubId);
            } elseif ($gateway === 'paypal' && $gatewaySubId !== '') {
                $this->paypal->cancelBillingAgreement($gatewaySubId);
            }

            $this->subscriptionService->cancelSubscription($gatewaySubId);

            $orderRow = $this->db->fetchAssociative(
                'SELECT id FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE subscription_id = ? AND order_type = ? ORDER BY id DESC LIMIT 1',
                [$gatewaySubId, 'subscription'],
            );

            if ($orderRow !== false) {
                $this->orderLogs?->add(
                    (int) $orderRow['id'],
                    'subscription_cancelled',
                    'Cancelled by user.',
                    'user',
                    (int) $userId,
                    $user['username'] ?? '',
                );
            }

            $expiresAt = !empty($sub['expires_at']) ? (new \DateTimeImmutable($sub['expires_at']))->format('Y-m-d') : null;
            $this->sendSubscriptionMail('cancelled', (int) $userId, $sub['plan_name'] ?? 'Membership', $expiresAt);

            $this->session->flash('success', 'Subscription will be cancelled at the end of the billing period.');
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Failed to cancel subscription: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $response->withHeader('Location', $this->accountPrefix() . '/subscription')->withStatus(302);
    }

    public function stripeCheckoutSession(Request $request, Response $response): Response
    {
        return $this->createSubscription($request, $response);
    }

    private function createStripeSubscription(Request $request, Response $response, MembershipPlan $plan, Subscriber $subscriber, array $user, float $discount = 0.0, string $duration = 'once', string $couponCode = ''): Response
    {
        $planId = $plan->getId();
        $appUrl = __require_app_url();

        try {
            $customer = $this->stripe->createCustomer($user['email'], $user['username'] ?? '');

            $interval = match ($plan->getInterval()) {
                'daily'   => 'daily',
                'weekly'  => 'weekly',
                'monthly' => 'monthly',
                'yearly'  => 'yearly',
                default   => null,
            };

            $currency       = $this->orderCurrency();
            $fxRate         = $this->orderFxRate();

            $basePrice      = (float) $plan->getPrice();
            $priceToCharge  = $basePrice;

            if ($discount > 0.0 && $duration === 'forever') {
                $priceToCharge = max(0.0, $basePrice - $discount);
            }

            $convertedPrice = round($priceToCharge * $fxRate, 2);
            $planPriceCents = (int) round($convertedPrice * 100);

            $trialDays = $plan->getTrialDays() > 0 ? $plan->getTrialDays() : null;

            $trialPriceRaw = (float) ($plan->getTrialPrice() ?? '0.00');
            $trialPrice    = round($trialPriceRaw * $fxRate, 2);
            $trialCents    = (int) round($trialPrice * 100);

            if ($trialDays !== null && $trialCents > 0) {
                $this->stripe->createInvoiceItem(
                    $customer['id'],
                    $trialCents,
                    strtolower($currency),
                    'Trial: ' . $plan->getName(),
                );
            }

            $discountCents = 0;
            if ($discount > 0.0 && $duration === 'once') {
                $discountConverted = round($discount * $fxRate, 2);
                $discountCents     = (int) round($discountConverted * 100);
                if ($discountCents > 0) {
                    $this->stripe->createInvoiceItem(
                        $customer['id'],
                        -$discountCents,
                        strtolower($currency),
                        'Coupon discount: ' . $couponCode,
                    );
                }
            }

            $session = $this->stripe->createCheckoutSession(
                $customer['id'],
                $planPriceCents,
                strtolower($currency),
                $interval,
                $trialDays,
                $appUrl . $this->membershipPrefix() . '/stripe-return?session_id={CHECKOUT_SESSION_ID}',
                $appUrl . $this->membershipPrefix() . '/cancel',
                $planId,
                (int) $user['id'],
                $couponCode,
                $discountCents,
            );

            $this->session->set('stripe_pending_plan_id', $planId);

            return $response->withHeader('Location', $session['url'])->withStatus(302);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Stripe checkout failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            return $response->withHeader('Location', $this->membershipPrefix() . '/checkout/' . $planId)->withStatus(302);
        }
    }

    private function createPayPalSubscription(Request $request, Response $response, MembershipPlan $plan, Subscriber $subscriber, array $user, float $discount = 0.0, string $duration = 'once', string $couponCode = ''): Response
    {
        $planId = $plan->getId();
        $appUrl = __require_app_url();

        try {
            $currency       = $this->orderCurrency();
            $fxRate         = $this->orderFxRate();

            $basePrice      = (float) $plan->getPrice();
            $priceToCharge  = $basePrice;

            if ($discount > 0.0 && $duration === 'forever') {
                $priceToCharge = max(0.0, $basePrice - $discount);
            }

            $convertedPrice = round($priceToCharge * $fxRate, 2);

            if ($plan->getInterval() === 'one_time') {
                $orderNumber = 'SUB-PAY-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
                $billing     = [
                    'name'  => $user['username'] ?? '',
                    'email' => $user['email']    ?? '',
                ];
                $order = new Order($orderNumber, $billing, (int) $user['id']);

                $oneTimePrice = $basePrice;
                if ($discount > 0.0) {
                    $oneTimePrice = max(0.0, $basePrice - $discount);
                }
                $convertedOneTime = round($oneTimePrice * $fxRate, 2);

                $order->setTotal((float) $convertedOneTime);
                $order->setCurrency($currency);

                $payment = $this->paypal->createPayment(
                    $order,
                    $appUrl . $this->membershipPrefix() . '/paypal-return',
                    $appUrl . $this->membershipPrefix() . '/cancel'
                );

                // Persist BEFORE redirecting: PayPal's webhook frequently beats
                // the browser return; without a row the captured payment could
                // not be reconciled to a membership.
                $order->setStatus('pending');
                $order->setPaymentMethod('paypal');
                $this->orders?->save($order);

                $this->session->set('paypal_pending_plan_id', $planId);

                return $response->withHeader('Location', $payment['redirect_url'])->withStatus(302);
            }

            $trialDays  = $plan->getTrialDays();
            $trialPrice = round((float) ($plan->getTrialPrice() ?? '0.00') * $fxRate, 2);
            $setupFee   = null;

            if ($discount > 0.0 && $duration === 'once') {
                // Setup fee is the discounted price
                $setupFee = round(max(0.0, $basePrice - $discount) * $fxRate, 2);
                if ($trialDays === 0) {
                    $intervalDays = match ($plan->getInterval()) {
                        'daily'  => 1,
                        'weekly' => 7,
                        'yearly' => 365,
                        default  => 30, // monthly
                    };
                    $trialDays  = $intervalDays;
                    $trialPrice = 0.00;
                }
            }

            // The return handler needs to know whether the agreement
            // execution charged anything (setup fee / paid trial).
            $this->session->set(
                'membership_setup_fee_' . $planId,
                ($setupFee !== null && $setupFee > 0.0) ? $setupFee : (($trialPrice > 0.0 && $trialDays > 0) ? $trialPrice : null),
            );

            $billingPlan = $this->paypal->createBillingPlan(
                $plan->getName(),
                $plan->getDescription() ?? $plan->getName(),
                $convertedPrice,
                $currency,
                $plan->getInterval(),
                $appUrl . $this->membershipPrefix() . '/paypal-return',
                $appUrl . $this->membershipPrefix() . '/cancel',
                $trialDays,
                $trialPrice > 0 ? $trialPrice : null,
                $setupFee
            );

            $intervalInc = match ($plan->getInterval()) {
                'daily'  => 'day',
                'weekly' => 'week',
                'yearly' => 'year',
                default  => 'month',
            };

            $agreementStartDate = $trialDays > 0
                ? (new \DateTimeImmutable('+' . $trialDays . ' days'))->format('c')
                : (new \DateTimeImmutable('+1 ' . $intervalInc))->format('c');

            $agreement = $this->paypal->createBillingAgreement(
                $billingPlan['id'],
                $appUrl . $this->membershipPrefix() . '/paypal-return',
                $appUrl . $this->membershipPrefix() . '/cancel',
                $agreementStartDate,
            );

            $this->session->set('paypal_pending_plan_id', $planId);

            return $response->withHeader('Location', $agreement['redirect_url'])->withStatus(302);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'PayPal setup failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            return $response->withHeader('Location', $this->membershipPrefix() . '/checkout/' . $planId)->withStatus(302);
        }
    }

    private function orderCurrency(): string
    {
        $sessionCurrency = strtoupper($this->session->get('selected_currency', '') ?? '');
        if ($sessionCurrency !== '') {
            return $sessionCurrency;
        }
        return strtoupper($this->settings->get('currency', 'USD') ?? 'USD');
    }

    private function orderFxRate(): float
    {
        $defaultCurrency = strtoupper($this->settings->get('currency', 'USD') ?? 'USD');
        $currentCurrency = $this->orderCurrency();
        if ($currentCurrency === $defaultCurrency) {
            return 1.0;
        }
        $provider = $this->settings->get('fx_provider', 'none') ?? 'none';
        if ($provider === 'none') {
            return 1.0;
        }
        try {
            return $this->exchangeRates->getRate($defaultCurrency, $currentCurrency);
        } catch (\Throwable) {
            return 1.0;
        }
    }

    private function sendSubscriptionMail(string $type, int $userId, string $planName, ?string $expiresAt = null, ?string $amount = null): void
    {
        $user = $this->fetchUser($userId);
        if ($user === null) {
            return;
        }

        $currencySymbol = $this->settings->get('shop_currency_symbol', '$');
        $userEntity     = $this->users->findById($userId);
        $locale         = $userEntity ? $userEntity->getLocale() : 'en';

        $context = [
            'plan_name'       => $planName,
            'name'            => $user['username'] ?? '',
            'expires_at'      => $expiresAt,
            'amount'          => $amount,
            'currency_symbol' => $currencySymbol,
            'currency'        => $this->orderCurrency(),
            'membership_url'  => absolute_url('/membership', [], ['lang' => $locale]),
        ];

        $template = $type === 'cancelled' ? 'mail/subscription-cancelled' : 'mail/subscription-welcome';
        $subject  = $type === 'cancelled'
            ? 'Your subscription has been cancelled'
            : 'Your subscription is active!';

        try {
            $this->mailer->sendTemplate(
                $user['email'],
                $this->mailer->getTranslator()->get($subject, [], $locale),
                $template,
                $context,
                locale: $locale,
                recipientType: 'customer'
            );
        } catch (\Throwable $e) {
            error_log('Failed to send subscription mail: ' . $e->getMessage());
        }

        $adminEmail = $this->settings->get('site_email', '');
        if ($adminEmail !== '') {
            try {
                $adminLocale  = $this->settings->get('locale', 'en');
                $adminSubject = $type === 'cancelled'
                    ? $this->mailer->getTranslator()->get('[:plan] User cancelled subscription', [':plan' => $planName], $adminLocale)
                    : $this->mailer->getTranslator()->get('[:plan] New subscription started', [':plan' => $planName], $adminLocale);
                $this->mailer->sendTemplate($adminEmail, $adminSubject, $template, $context, locale: $adminLocale, recipientType: 'admin');
            } catch (\Throwable $e) {
                error_log('Failed to send admin subscription mail: ' . $e->getMessage());
            }
        }
    }

    private function accountPrefix(): string
    {
        return '/' . ltrim($this->settings->get('routing_account_prefix', '/account'), '/') ?: '/account';
    }

    private function membershipPrefix(): string
    {
        return '/' . ltrim($this->settings->get('routing_membership_prefix', '/membership'), '/') ?: '/membership';
    }

    private function fetchUser(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, email, name AS username FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
            [$userId],
        );
        return $row !== false ? $row : null;
    }

    public function applyCoupon(Request $request, Response $response): Response
    {
        $body   = (array) json_decode((string) $request->getBody(), true);
        $code   = strtoupper(trim($body['code'] ?? ''));
        $planId = (int) ($body['plan_id'] ?? 0);

        $plan = $this->plans->findById($planId);
        if ($plan === null || !$plan->isActive()) {
            return $this->jsonResponse($response, ['valid' => false, 'message' => 'Plan not found.']);
        }

        if ($code === '') {
            return $this->jsonResponse($response, ['valid' => false, 'message' => 'Please enter a coupon code.']);
        }

        if ($this->coupons === null) {
            return $this->jsonResponse($response, ['valid' => false, 'message' => 'Coupon system not configured.']);
        }

        $result = $this->validatePlanCoupon($code, $planId);
        if (!$result['valid']) {
            return $this->jsonResponse($response, ['valid' => false, 'message' => $result['error']]);
        }
        $coupon = $result['coupon'];

        // Max uses per email check
        $userId = $this->session->get('user_id');
        if ($coupon->getMaxUsesPerEmail() > 0 && $userId !== null) {
            $user = $this->users->findById((int) $userId);
            if ($user !== null) {
                $emailUses = $this->countCouponUsesForEmail($coupon->getCode(), (string) $user->getEmail());
                if ($emailUses >= $coupon->getMaxUsesPerEmail()) {
                    return $this->jsonResponse($response, ['valid' => false, 'message' => 'You have already reached the maximum usage limit for this coupon.']);
                }
            }
        }

        // Email restriction check
        if ($coupon->isOnlyUsers() && $userId === null) {
            return $this->jsonResponse($response, ['valid' => false, 'message' => 'This coupon requires you to be logged in.']);
        }

        $allowedEmails = $coupon->getAllowedEmails();
        if (!empty($allowedEmails)) {
            if ($userId === null) {
                return $this->jsonResponse($response, ['valid' => false, 'message' => 'This coupon is restricted. Please log in first.']);
            }
            $user = $this->users->findById((int) $userId);
            if ($user === null || !in_array(strtolower((string) $user->getEmail()), $allowedEmails, true)) {
                return $this->jsonResponse($response, ['valid' => false, 'message' => 'This coupon is not valid for your email address.']);
            }
        }

        // Calculate discount
        $basePrice  = (float) $plan->getPrice();
        $discount   = $coupon->calculateDiscount($basePrice);
        $finalPrice = max(0.0, $basePrice - $discount);

        return $this->jsonResponse($response, [
            'valid'       => true,
            'code'        => $coupon->getCode(),
            'discount'    => $discount,
            'final_price' => $finalPrice,
            'duration'    => $coupon->getMembershipDuration(),
            'message'     => 'Coupon applied successfully!',
        ]);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

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

    private function countCouponUsesForEmail(string $code, string $email): int
    {
        if ($this->coupons !== null) {
            return $this->coupons->countUsesByEmail($code, $email);
        }
        return 0;
    }

    /** @return array{valid: bool, coupon: Coupon|null, error: string|null} */
    private function validatePlanCoupon(string $code, int $planId): array
    {
        if ($this->couponValidator !== null) {
            return $this->couponValidator->validateForPlan($code, $planId);
        }

        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['valid' => false, 'coupon' => null, 'error' => 'Please enter a coupon code.'];
        }
        if ($this->coupons === null) {
            return ['valid' => false, 'coupon' => null, 'error' => 'Coupon system not configured.'];
        }

        $coupon = $this->coupons->findByCode($code);
        if ($coupon === null || !$coupon->isValid()) {
            return ['valid' => false, 'coupon' => null, 'error' => 'Invalid or expired coupon code.'];
        }
        if ($coupon->getTargetType() !== 'membership') {
            return ['valid' => false, 'coupon' => null, 'error' => 'This coupon is only valid for shop products.'];
        }

        $allowedPlans = $coupon->getAllowedMembershipIds();
        if (!empty($allowedPlans) && !in_array($planId, $allowedPlans, true)) {
            return ['valid' => false, 'coupon' => null, 'error' => 'This coupon is not valid for this plan.'];
        }

        $blockedPlans = $coupon->getBlockedMembershipIds();
        if (!empty($blockedPlans) && in_array($planId, $blockedPlans, true)) {
            return ['valid' => false, 'coupon' => null, 'error' => 'This coupon cannot be used with this plan.'];
        }

        return ['valid' => true, 'coupon' => $coupon, 'error' => null];
    }

    /**
     * Stamp coupon metadata on a recorded subscription order exactly once and count the use.
     * Safe against the webhook path doing the same concurrently.
     */
    private function stampMembershipCoupon(int $orderId, int $planId): void
    {
        $couponCode = $this->session->get('membership_coupon_code_' . $planId);
        if (!$couponCode || $this->coupons === null) {
            return;
        }

        $alreadyStamped = $this->db->fetchOne(
            'SELECT coupon_code FROM ' . VL_DB_PREFIX . 'orders WHERE id = ?',
            [$orderId],
        );
        if (!empty($alreadyStamped)) {
            return;
        }

        $discountAmount = (float) ($this->session->get('membership_discount_amount_' . $planId, 0.0));
        $converted      = round($discountAmount * $this->orderFxRate(), 2);

        $stamped = $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'orders SET coupon_code = ?, discount = ?'
            . " WHERE id = ? AND (coupon_code IS NULL OR coupon_code = '')",
            [$couponCode, $converted, $orderId],
        );
        if ($stamped === 0) {
            return;
        }

        $cEntity = $this->coupons->findByCode((string) $couponCode);
        if ($cEntity !== null && !$this->coupons->incrementUsesIfAvailable((int) $cEntity->getId())) {
            error_log('[Membership] Coupon usage limit reached during stamping: ' . $couponCode);
        }
    }

    private function clearMembershipCouponSession(int $planId): void
    {
        $this->session->set('membership_coupon_code_' . $planId, null);
        $this->session->set('membership_discount_amount_' . $planId, null);
        $this->session->set('membership_coupon_duration_' . $planId, null);
        $this->session->set('membership_setup_fee_' . $planId, null);
    }

    private function couponRedirectError(Response $response, int $planId, string $message): Response
    {
        $this->session->flash('error', $message);
        return $response
            ->withHeader('Location', $this->membershipPrefix() . '/checkout/' . $planId)
            ->withStatus(302);
    }
}
