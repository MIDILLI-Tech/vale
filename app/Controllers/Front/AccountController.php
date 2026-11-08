<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Auth\TwoFactorService;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Membership\SubscriptionService;
use ValeApp\Application\Seo\SeoState;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\Shop\Repository\OrderLogRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Payment\PayPalGateway;
use ValeApp\Infrastructure\Payment\StripeGateway;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\Encrypter;
use ValeApp\Infrastructure\Session\SessionManager;

final class AccountController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly SessionManager $session,
        private readonly DbalSettingsRepository $settings,
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasher $hasher,
        private readonly Connection $db,
        private readonly SubscriptionService $subscriptionService,
        private readonly PlanRepositoryInterface $plans,
        private readonly TwoFactorService $twoFactorService,
        private readonly SeoState $seoState,
        private readonly ?OrderRepositoryInterface $orders = null,
        private readonly ?OrderLogRepositoryInterface $orderLogs = null,
        private readonly ?MailService $mailer = null,
        private readonly ?SubscriberRepositoryInterface $subscribers = null,
        private readonly ?StripeGateway $stripe = null,
        private readonly ?PayPalGateway $paypal = null,
        private readonly ?Encrypter $encrypter = null,
    ) {
    }

    private function accountPrefix(): string
    {
        return '/' . ltrim($this->settings->get('routing_account_prefix', '/account'), '/') ?: '/account';
    }

    public function index(Request $request, Response $response): Response
    {
        return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
    }

    public function profile(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');
        $user   = $this->users->findById($userId);

        if ($user === null) {
            $this->session->flash('error', 'User not found.');
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            return $this->updateProfile($request, $response, $user);
        }

        $data = [
            'user' => $user,
        ];

        if ($this->session->has('two_factor_new_codes')) {
            $data['two_factor_new_codes'] = $this->session->get('two_factor_new_codes');
            $this->session->remove('two_factor_new_codes');
        }

        return $this->twig->render($response, 'account/profile.twig', $data);
    }

    public function resendVerification(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');
        $user   = $this->users->findById($userId);

        if ($user === null) {
            $this->session->flash('error', __('User not found.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        if ($user->getEmailVerifiedAt() !== null) {
            $this->session->flash('success', __('Your email is already verified.'));
            return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
        }

        $user->setVerificationToken(bin2hex(random_bytes(32)));
        $user->setVerificationTokenExpiresAt(new \DateTimeImmutable('+24 hours'));
        $this->users->save($user);

        if ($this->mailer !== null) {
            try {
                $verifyUrl = absolute_url('/verify-email', [], ['lang' => $user->getLocale(), 'token' => $user->getVerificationToken()]);
                $this->mailer->sendTemplate(
                    (string) $user->getEmail(),
                    'Verify your email address',
                    'mail/email-verify',
                    [
                        'name'       => $user->getName(),
                        'verify_url' => $verifyUrl,
                    ],
                    locale: $user->getLocale(),
                    recipientType: 'customer',
                );
            } catch (\Throwable) {
                // silent
            }
        }

        $this->session->flash('success', __('Verification email sent. Please check your inbox.'));
        return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
    }

    public function setupTwoFactor(Request $request, Response $response): Response
    {
        return $response->withHeader('Location', site_url($this->accountPrefix() . '/two-factor/setup'))->withStatus(302);
    }

    public function enableTwoFactor(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');
        $user   = $this->users->findById($userId);

        if ($user === null) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $body   = (array) $request->getParsedBody();
        $secret = $body['secret'] ?? '';
        $code   = $body['code']   ?? '';

        $slice = $this->twoFactorService->verifyCodeSlice($secret, $code);
        if ($slice !== null) {
            $codes = $this->twoFactorService->generateRecoveryCodes();
            $user->setTwoFactorSecret($secret);
            $user->setTwoFactorEnabled(true);
            $user->setTwoFactorRecoveryCodes($codes);
            $user->setTotpLastSlice($slice);
            $user->incrementSessionVersion();
            $this->users->save($user);

            $this->session->set('session_version', $user->getSessionVersion());
            $this->session->set('two_factor_new_codes', $codes);

            // Release the forced-setup gate now that 2FA is enrolled.
            if ($this->session->has('two_factor_pending')) {
                $this->session->remove('two_factor_pending');
            }

            $this->session->flash('success', 'Two-factor authentication has been enabled.');
            return $this->twig->render($response, 'account/two-factor-confirmed.twig', [
                'user'                 => $user,
                'two_factor_new_codes' => $codes,
            ]);
        } else {
            $this->session->flash('error', 'Invalid authentication code. Please try again.');
            return $response->withHeader('Location', site_url($this->accountPrefix() . '/two-factor/setup'))->withStatus(302);
        }
    }

    public function disableTwoFactor(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');
        $user   = $this->users->findById($userId);

        if ($user === null) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        if ($this->settings->get('two_factor_forced_global', '0') === '1') {
            $this->session->flash('error', 'Two-factor authentication cannot be disabled because it is required by the administrator.');
            return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
        }

        $body         = (array) $request->getParsedBody();
        $code         = trim($body['code'] ?? '');
        $recoveryCode = trim($body['recovery_code'] ?? '');

        $verified = false;

        if ($code !== '') {
            $verified = $this->twoFactorService->verifyCode((string) $user->getTwoFactorSecret(), $code);
        } elseif ($recoveryCode !== '') {
            $recoveryCodes = $user->getTwoFactorRecoveryCodes();
            foreach ($recoveryCodes as $candidate) {
                if (is_string($candidate) && $candidate !== '' && hash_equals($candidate, $recoveryCode)) {
                    $verified = true;
                    break;
                }
            }
            if ($verified) {
                $user->setTwoFactorRecoveryCodes(array_values(array_diff($recoveryCodes, [$recoveryCode])));
            }
        }

        if (!$verified) {
            $this->session->flash('error', 'Invalid code. 2FA could not be disabled.');
            return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
        }

        $user->setTwoFactorEnabled(false);
        $user->setTwoFactorSecret(null);
        $user->setTwoFactorRecoveryCodes([]);
        $user->incrementSessionVersion();
        $this->users->save($user);

        $this->session->set('session_version', $user->getSessionVersion());
        $this->session->flash('success', 'Two-factor authentication has been disabled.');

        return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
    }

    public function showTwoFactorSetup(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');
        $user   = $this->users->findById($userId);

        if ($user === null) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        if ($user->isTwoFactorEnabled()) {
            $this->session->remove('two_factor_pending');
            return $response->withHeader('Location', site_url($this->accountPrefix() . '/profile'))->withStatus(302);
        }

        // Use existing secret if available (e.g. provisioned by admin or from previous failed attempt)
        // If not, generate and SAVE it immediately so it remains stable during setup.
        $secret = $user->getTwoFactorSecret();
        if (!$secret) {
            $secret = $this->twoFactorService->generateSecret();
            $user->setTwoFactorSecret($secret);
            $this->users->save($user);
        }

        $data = [
            'user'                => $user,
            'two_factor_force'    => $this->session->has('two_factor_pending') || $this->settings->get('two_factor_forced_global', '0') === '1',
            'two_factor_secret'   => $secret,
            'two_factor_qr_code'  => $this->twoFactorService->generateQrCodeBase64(
                $this->twoFactorService->getQrCodeUri(
                    $secret,
                    (string) $user->getEmail(),
                    $this->settings->get('site_name', 'Vale')
                )
            ),
        ];

        // Ensure language switcher can handle the forced-setup page correctly
        $locales        = explode(',', $this->settings->get('content_languages', 'en'));
        $translationMap = [];
        $accountPrefix  = $this->accountPrefix();
        foreach ($locales as $l) {
            $l                  = trim($l);
            $translationMap[$l] = $accountPrefix . '/two-factor/forced-setup';
        }
        $this->seoState->setTranslations($translationMap);

        return $this->twig->render($response, 'account/two-factor-setup.twig', $data);
    }

    public function regenerateRecoveryCodes(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');
        $user   = $this->users->findById($userId);

        if ($user === null || !$user->isTwoFactorEnabled()) {
            return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
        }

        $codes = $this->twoFactorService->generateRecoveryCodes();
        $user->setTwoFactorRecoveryCodes($codes);
        $this->users->save($user);

        $this->session->set('two_factor_new_codes', $codes);
        $this->session->flash('success', 'Recovery codes have been regenerated.');

        return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
    }

    private function updateProfile(Request $request, Response $response, User $user): Response
    {
        $body            = (array) $request->getParsedBody();
        $name            = trim($body['name'] ?? '');
        $email           = trim($body['email'] ?? '');
        $currentPassword = $body['current_password'] ?? '';
        $newPassword     = $body['new_password']     ?? '';
        $locale          = trim($body['locale'] ?? $user->getLocale());

        $errors = [];

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        if ($email === '') {
            $errors[] = 'Email is required.';
        }

        if ($newPassword !== '') {
            if ($currentPassword === '') {
                $errors[] = 'Current password is required to set a new password.';
            } elseif (!$this->hasher->verify($currentPassword, $user->getPassword())) {
                $errors[] = 'Current password is incorrect.';
            } elseif (strlen($newPassword) < 6) {
                $errors[] = 'New password must be at least 6 characters.';
            }
        }

        if (!empty($errors)) {
            return $this->twig->render($response->withStatus(422), 'account/profile.twig', [
                'user'   => $user,
                'errors' => $errors,
            ]);
        }

        $user->setName($name);
        $user->setLocale($locale);

        $emailChanged = false;
        if ($email !== (string) $user->getEmail()) {
            if ($this->settings->get('enable_email_change', '1') !== '1') {
                $this->session->flash('error', __('Email change is not allowed by the administrator.'));
                return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
            }

            $emailVo = new Email($email);

            $existing = $this->users->findByEmail($emailVo);
            if ($existing !== null && $existing->getId() !== $user->getId()) {
                $this->session->flash('error', __('This email is already in use.'));
                return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
            }

            $user->setEmail($emailVo);
            $user->setEmailVerifiedAt(null);
            $user->setVerificationToken(bin2hex(random_bytes(32)));
            $user->setVerificationTokenExpiresAt(new \DateTimeImmutable('+24 hours'));
            $emailChanged = true;

            if ($this->settings->get('registration_verify_email', '1') === '1' && $this->mailer !== null) {
                try {
                    $verifyUrl = absolute_url('/verify-email', [], ['lang' => $user->getLocale(), 'token' => $user->getVerificationToken()]);
                    $this->mailer->sendTemplate(
                        (string) $user->getEmail(),
                        'Verify your email address',
                        'mail/email-verify',
                        [
                            'name'       => $user->getName(),
                            'verify_url' => $verifyUrl,
                        ],
                        locale: $user->getLocale(),
                        recipientType: 'customer',
                    );
                } catch (\Throwable) {
                    // silent
                }
            }
        }

        if ($newPassword !== '') {
            $user->setPassword($this->hasher->hash($newPassword));
        }

        $this->users->save($user);

        if ($newPassword !== '') {
            $this->db->executeStatement(
                'UPDATE ' . VL_DB_PREFIX . 'users SET session_version = session_version + 1 WHERE id = ?',
                [$user->getId()],
            );
            $newVersion = $this->db->fetchOne(
                'SELECT session_version FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
                [$user->getId()],
            );
            if ($newVersion !== false) {
                $this->session->set('session_version', (int) $newVersion);
            }
        }

        $this->session->set('user_name', $user->getName());
        $this->session->set('user_locale', $user->getLocale());

        $this->session->flash('success', $emailChanged
            ? __('Profile updated. Please check your email to verify your new address.')
            : __('Profile updated successfully.'));
        return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
    }

    public function addresses(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');
        $row    = $this->db->fetchAssociative(
            'SELECT billing_address, shipping_address FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
            [$userId],
        );

        $billingAddress  = [];
        $shippingAddress = [];
        if ($row !== false) {
            $billingAddress  = $row['billing_address']   !== null ? $this->decryptUserAddressFields(json_decode($row['billing_address'], true)  ?? []) : [];
            $shippingAddress = $row['shipping_address']  !== null ? $this->decryptUserAddressFields(json_decode($row['shipping_address'], true) ?? []) : [];
        }

        if ($request->getMethod() === 'POST') {
            return $this->updateAddresses($request, $response, $userId, $billingAddress, $shippingAddress);
        }

        return $this->twig->render($response, 'account/addresses.twig', [
            'billing'   => $billingAddress,
            'shipping'  => $shippingAddress,
            'countries' => $this->getCountries(),
        ]);
    }

    private function updateAddresses(Request $request, Response $response, int $userId, array $currentBilling, array $currentShipping): Response
    {
        $body = (array) $request->getParsedBody();

        $billing = [
            'first_name' => trim($body['billing_first_name'] ?? $currentBilling['first_name'] ?? ''),
            'last_name'  => trim($body['billing_last_name'] ?? $currentBilling['last_name'] ?? ''),
            'company'    => trim($body['billing_company'] ?? $currentBilling['company'] ?? ''),
            'address_1'  => trim($body['billing_address_1'] ?? $currentBilling['address_1'] ?? ''),
            'address_2'  => trim($body['billing_address_2'] ?? $currentBilling['address_2'] ?? ''),
            'city'       => trim($body['billing_city'] ?? $currentBilling['city'] ?? ''),
            'state'      => trim($body['billing_state'] ?? $currentBilling['state'] ?? ''),
            'postcode'   => trim($body['billing_postcode'] ?? $currentBilling['postcode'] ?? ''),
            'country'    => trim($body['billing_country'] ?? $currentBilling['country'] ?? ''),
            'phone'      => trim($body['billing_phone'] ?? $currentBilling['phone'] ?? ''),
        ];

        $sameAsBilling = isset($body['same_as_billing']);
        if ($sameAsBilling) {
            $shipping = $billing;
        } else {
            $shipping = [
                'first_name' => trim($body['shipping_first_name'] ?? $currentShipping['first_name'] ?? ''),
                'last_name'  => trim($body['shipping_last_name'] ?? $currentShipping['last_name'] ?? ''),
                'company'    => trim($body['shipping_company'] ?? $currentShipping['company'] ?? ''),
                'address_1'  => trim($body['shipping_address_1'] ?? $currentShipping['address_1'] ?? ''),
                'address_2'  => trim($body['shipping_address_2'] ?? $currentShipping['address_2'] ?? ''),
                'city'       => trim($body['shipping_city'] ?? $currentShipping['city'] ?? ''),
                'state'      => trim($body['shipping_state'] ?? $currentShipping['state'] ?? ''),
                'postcode'   => trim($body['shipping_postcode'] ?? $currentShipping['postcode'] ?? ''),
                'country'    => trim($body['shipping_country'] ?? $currentShipping['country'] ?? ''),
                'phone'      => trim($body['shipping_phone'] ?? $currentShipping['phone'] ?? ''),
            ];
        }

        $this->db->update(VL_DB_PREFIX . 'users', [
            'billing_address'  => json_encode($this->encryptUserAddressFields($billing)),
            'shipping_address' => json_encode($this->encryptUserAddressFields($shipping)),
            'updated_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $userId]);

        $this->session->flash('success', 'Addresses updated successfully.');
        return $response->withHeader('Location', $this->accountPrefix() . '/addresses')->withStatus(302);
    }

    public function orders(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');

        $allOrders = [];
        if ($this->orders !== null) {
            $allOrders = $this->orders->findByUserId($userId, client_side_search_fetch_limit(), 0);
        }

        $ordersJson = [];
        foreach ($allOrders as $o) {
            if ($o->getOrderType() === 'subscription') {
                $itemsLabel = $o->getPlanName() ?: 'Membership';
            } else {
                $names      = array_map(fn ($i) => $i['name'] ?? '', $o->getItems());
                $all        = implode(', ', $names);
                $itemsLabel = mb_strlen($all) > 40 ? mb_substr($all, 0, 40) . '...' : $all;
            }
            $ordersJson[] = [
                'orderNumber' => $o->getOrderNumber(),
                'createdAt'   => $o->getCreatedAt()->format('Y-m-d'),
                'orderType'   => $o->getOrderType(),
                'itemsLabel'  => $itemsLabel,
                'total'       => number_format((float) $o->getTotal(), 2, '.', ''),
                'status'      => $o->getStatus(),
            ];
        }

        return $this->twig->render($response, 'account/orders.twig', [
            'orders'     => $allOrders,
            'ordersJson' => json_encode($ordersJson, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function subscription(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');

        $activeSubscription = null;
        $sub                = $this->subscriptionService->findActiveByUserId($userId);
        if ($sub !== null) {
            $plan               = $this->plans->findById((int) $sub['plan_id']);
            $sub['plan']        = $plan;
            $activeSubscription = $sub;
        }

        return $this->twig->render($response, 'account/subscription.twig', [
            'active_subscription' => $activeSubscription,
        ]);
    }

    public function cancelSubscription(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');

        if ($request->getMethod() !== 'POST') {
            $this->session->flash('info', 'Subscription checkout cancelled.');
            return $response->withHeader('Location', $this->accountPrefix() . '/subscription')->withStatus(302);
        }

        if ($userId === 0) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $sub = $this->subscriptionService->findActiveByUserId($userId);
        if ($sub === null) {
            $this->session->flash('error', 'No active subscription found.');
            return $response->withHeader('Location', $this->accountPrefix() . '/subscription')->withStatus(302);
        }

        $user = $this->fetchUser($userId);

        try {
            $gateway      = $sub['gateway']                 ?? '';
            $gatewaySubId = (string) ($sub['gateway_subscription_id'] ?? '');
            $isLifetime   = str_starts_with($gatewaySubId, 'LIFETIME-');

            if (!$isLifetime) {
                if ($gateway === 'stripe' && $gatewaySubId !== '' && $this->stripe !== null) {
                    $this->stripe->cancelSubscription($gatewaySubId);
                } elseif ($gateway === 'paypal' && $gatewaySubId !== '' && $this->paypal !== null) {
                    $this->paypal->cancelBillingAgreement($gatewaySubId);
                }
            }

            $this->subscriptionService->cancelSubscription($gatewaySubId);

            $orderRow = $this->db->fetchAssociative(
                'SELECT id FROM ' . VL_DB_PREFIX . 'orders' . ' WHERE subscription_id = ? AND order_type = ? ORDER BY id DESC LIMIT 1',
                [$gatewaySubId, 'subscription'],
            );

            if ($orderRow !== false && $this->orderLogs !== null) {
                $this->orderLogs->add(
                    (int) $orderRow['id'],
                    'subscription_cancelled',
                    'Cancelled by user.',
                    'user',
                    $userId,
                    $user['username'] ?? '',
                );
            }

            $expiresAt = !empty($sub['expires_at']) ? (new \DateTimeImmutable($sub['expires_at']))->format('Y-m-d') : null;
            $this->sendSubscriptionMail('cancelled', $userId, $sub['plan_name'] ?? 'Membership', $expiresAt);

            // PayPal agreements and lifetime purchases terminate immediately;
            // Stripe cancellations take effect at the end of the period.
            if ($gateway === 'paypal' || $isLifetime) {
                $this->session->flash('success', 'Your subscription has been cancelled.');
            } else {
                $this->session->flash('success', 'Subscription will be cancelled at the end of the billing period.');
            }
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Failed to cancel subscription: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $response->withHeader('Location', $this->accountPrefix() . '/subscription')->withStatus(302);
    }

    public function delete(Request $request, Response $response): Response
    {
        $userId = (int) $this->session->get('user_id');

        if ($this->settings->get('gdpr_account_deletion_enabled', '1') !== '1') {
            $this->session->flash('error', 'Account deletion is not enabled.');
            return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
        }

        if ($request->getMethod() === 'POST') {
            return $this->deleteAccount($request, $response, $userId);
        }

        return $this->twig->render($response, 'account/delete.twig');
    }

    public function downloadData(Request $request, Response $response): Response
    {
        if ($this->settings->get('gdpr_download_enabled', '0') !== '1') {
            $this->session->flash('error', 'Data download is not enabled.');
            return $response->withHeader('Location', $this->accountPrefix() . '/delete')->withStatus(302);
        }

        $userId = (int) $this->session->get('user_id');
        $user   = $this->users->findById($userId);

        if ($user === null) {
            return $response->withStatus(404);
        }

        $data = $this->gatherUserData($user);

        $filename = 'personal-data-' . date('Y-m-d') . '.json';
        $payload  = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($payload);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStatus(200);
    }

    private function gatherUserData(User $user): array
    {
        $userId = $user->getId();
        $email  = (string) $user->getEmail();

        $data = [
            'profile' => [
                'name'       => $user->getName(),
                'email'      => $email,
                'locale'     => $user->getLocale(),
                'created_at' => $user->getCreatedAt()?->format('Y-m-d H:i:s'),
            ],
            'addresses'    => [],
            'orders'       => [],
            'subscription' => null,
            'comments'     => [],
            'newsletter'   => null,
        ];

        // Addresses
        $row = $this->db->fetchAssociative(
            'SELECT billing_address, shipping_address FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
            [$userId],
        );
        if ($row) {
            $data['addresses'] = [
                'billing'  => $this->decryptUserAddressFields(json_decode((string) $row['billing_address'], true) ?? []),
                'shipping' => $this->decryptUserAddressFields(json_decode((string) $row['shipping_address'], true) ?? []),
            ];
        }

        // Orders
        if ($this->orders) {
            $orders = $this->orders->findByUserId($userId, 1000, 0);
            foreach ($orders as $order) {
                $data['orders'][] = [
                    'order_number'     => $order->getOrderNumber(),
                    'status'           => $order->getStatus(),
                    'total'            => $order->getTotal(),
                    'currency'         => $order->getCurrency(),
                    'created_at'       => $order->getCreatedAt()->format('Y-m-d H:i:s'),
                    'items'            => $order->getItems(),
                    'payment_method'   => $order->getPaymentMethod(),
                    'billing'          => $order->getBilling(),
                    'shipping_address' => $order->getShippingAddress(),
                    'customer_note'    => $order->getCustomerNote(),
                    'tracking_number'  => $order->getTrackingNumber(),
                    'carrier'          => $order->getCarrier(),
                ];
            }
        }

        // Subscription
        $sub = $this->subscriptionService->findActiveByUserId($userId);
        if ($sub) {
            $data['subscription'] = [
                'plan_name'  => $sub['plan_name']  ?? '',
                'status'     => $sub['status']     ?? '',
                'expires_at' => $sub['expires_at'] ?? '',
                'gateway'    => $sub['gateway']    ?? '',
            ];
        }

        // Comments
        $commentRows = $this->db->fetchAllAssociative(
            'SELECT body, entity_type, entity_id, status, created_at FROM ' . VL_DB_PREFIX . 'comments WHERE user_id = ? OR author_email = ?',
            [$userId, $email]
        );
        foreach ($commentRows as $cr) {
            $data['comments'][] = $cr;
        }

        // Newsletter
        if ($this->subscribers) {
            $subscriber = $this->subscribers->findByEmail($email);
            if ($subscriber) {
                $data['newsletter'] = [
                    'status'     => $subscriber->getStatus(),
                    'tags'       => $subscriber->getTags(),
                    'created_at' => $subscriber->getCreatedAt()?->format('Y-m-d H:i:s'),
                ];
            }
        }

        return $data;
    }

    private function deleteAccount(Request $request, Response $response, int $userId): Response
    {
        if ($this->settings->get('gdpr_account_deletion_enabled', '1') !== '1') {
            $this->session->flash('error', 'Account deletion is not enabled.');
            return $response->withHeader('Location', $this->accountPrefix() . '/profile')->withStatus(302);
        }

        $body    = (array) $request->getParsedBody();
        $confirm = trim($body['confirm'] ?? '');

        if ($confirm !== 'DELETE') {
            $this->session->flash('error', 'Please type DELETE to confirm account deletion.');
            return $response->withHeader('Location', $this->accountPrefix() . '/delete')->withStatus(302);
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->session->flash('error', 'User not found.');
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        // Cancel any active subscriptions
        $sub = $this->subscriptionService->findActiveByUserId($userId);
        if ($sub !== null) {
            $gateway      = $sub['gateway']                 ?? '';
            $gatewaySubId = $sub['gateway_subscription_id'] ?? '';
            if ($gateway === 'stripe' && $gatewaySubId !== '' && $this->stripe !== null) {
                try {
                    $this->stripe->cancelSubscription($gatewaySubId);
                } catch (\Throwable) {
                }
            } elseif ($gateway === 'paypal' && $gatewaySubId !== '' && $this->paypal !== null) {
                try {
                    $this->paypal->cancelBillingAgreement($gatewaySubId);
                } catch (\Throwable) {
                }
            }
            $this->db->update(VL_DB_PREFIX . 'user_subscriptions', [
                'status'     => 'cancelled',
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], ['user_id' => $userId]);
        }

        // Unsubscribe from newsletter
        if ($this->subscribers !== null) {
            $subscriber = $this->subscribers->findByUserId($userId);
            if ($subscriber !== null) {
                $subscriber->setStatus('unsubscribed');
                $subscriber->setTags([]);
                $this->subscribers->save($subscriber);
            }
        }

        // Anonymize user (GDPR) — preserve orders, keep email
        $this->db->update(VL_DB_PREFIX . 'users', [
            'name'                          => 'Deleted User',
            'password'                      => bin2hex(random_bytes(32)),
            'avatar'                        => null,
            'is_active'                     => 0,
            'email_verified_at'             => null,
            'verification_token'            => null,
            'verification_token_expires_at' => null,
            'billing_address'               => null,
            'shipping_address'              => null,
            'updated_at'                    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['id' => $userId]);

        // Anonymize comments (GDPR) — keep email
        $this->db->update(VL_DB_PREFIX . 'comments', [
            'author_name'  => 'Deleted User',
            'author_ip'    => null,
            'author_url'   => null,
            'user_id'      => null,
        ], ['user_id' => $userId]);

        // Detach user from orders (orders preserved, user_id set to null)
        $this->db->update(VL_DB_PREFIX . 'orders', [
            'user_id' => null,
        ], ['user_id' => $userId]);

        // Clear session
        $this->session->destroy();

        $this->session->flash('info', 'Your account has been deleted. Your order history has been preserved.');
        return $response->withHeader('Location', '/')->withStatus(302);
    }

    private function fetchUser(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, email, name AS username FROM ' . VL_DB_PREFIX . 'users WHERE id = ?',
            [$userId],
        );
        return $row !== false ? $row : null;
    }

    private function sendSubscriptionMail(string $type, int $userId, string $planName, ?string $expiresAt = null): void
    {
        if ($this->mailer === null) {
            return;
        }

        $user = $this->fetchUser($userId);
        if ($user === null) {
            return;
        }

        $currencySymbol = $this->settings->get('shop_currency_symbol', '$');

        $context = [
            'plan_name'       => $planName,
            'name'            => $user['username'] ?? '',
            'expires_at'      => $expiresAt,
            'amount'          => null,
            'currency_symbol' => $currencySymbol,
        ];

        $template = 'mail/subscription-cancelled';
        $subject  = 'Your subscription has been cancelled';

        try {
            $recipientLocale = $this->settings->get('default_locale', 'en');
            if ($userId) {
                $userEntity = $this->users->findById($userId);
                if ($userEntity) {
                    $recipientLocale = $userEntity->getLocale();
                }
            }
            $context['membership_url'] = absolute_url('/membership', [], ['lang' => $recipientLocale]);
            $this->mailer->sendTemplate($user['email'], $subject, $template, $context, locale: $recipientLocale, recipientType: 'customer');
        } catch (\Throwable $e) {
            error_log('Failed to send subscription mail: ' . $e->getMessage());
        }

        $adminEmail = $this->settings->get('site_email', '');
        if ($adminEmail !== '') {
            try {
                $adminLocale  = $this->settings->get('locale', 'en');
                $adminSubject = $this->mailer->getTranslator()->get('[:plan] User cancelled subscription', [':plan' => $planName], $adminLocale);
                $this->mailer->sendTemplate($adminEmail, $adminSubject, $template, $context, locale: $adminLocale, recipientType: 'admin');
            } catch (\Throwable $e) {
                error_log('Failed to send admin subscription mail: ' . $e->getMessage());
            }
        }
    }

    private function getCountries(): array
    {
        $countries = [
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
        asort($countries);
        return $countries;
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
