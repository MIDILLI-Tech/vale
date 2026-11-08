<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Auth\LoginUseCase;
use ValeApp\Application\Auth\LogoutUseCase;
use ValeApp\Application\Auth\RegisterUseCase;
use ValeApp\Application\Auth\TwoFactorService;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

class AuthController
{
    use CaptchaTrait;

    public function __construct(
        private readonly Twig $twig,
        private readonly LoginUseCase $loginUseCase,
        private readonly LogoutUseCase $logoutUseCase,
        private readonly RegisterUseCase $registerUseCase,
        private readonly TwoFactorService $twoFactorService,
        private readonly SessionManager $session,
        private readonly MailService $mail,
        private readonly UserRepositoryInterface $users,
        private readonly DbalSettingsRepository $settings,
        private readonly RoleRepositoryInterface $roleRepo,
        private readonly EventDispatcherInterface $events,
        private readonly OutboundWebhookService $webhookService,
    ) {
    }

    public function showRegister(Request $request, Response $response): Response
    {
        $this->session->start();
        if ($this->session->has('user_id')) {
            $accountPrefix = $this->settings->get('routing_account_prefix', '/account') ?: '/account';
            return $response->withHeader('Location', $accountPrefix)->withStatus(302);
        }

        $registrationEnabled = $this->settings->get('registration_enabled', '1');
        if ($registrationEnabled === '0') {
            return $this->twig->render($response->withStatus(404), 'errors/feature-disabled.twig', [
                'feature' => 'registration',
                'title'   => 'Registration Disabled',
            ]);
        }

        $emailRegEnabled = $this->settings->get('email_registration_enabled', '1');
        $ssoRegEnabled   = $this->settings->get('sso_registration_enabled', '0');
        if ($emailRegEnabled === '0' && $ssoRegEnabled === '0') {
            return $this->twig->render($response->withStatus(404), 'errors/feature-disabled.twig', [
                'feature' => 'registration',
                'title'   => 'Registration Disabled',
            ]);
        }

        $redirect = $request->getQueryParams()['redirect'] ?? '';

        $templateData = [
            'redirect'         => $redirect,
            'register_captcha' => $this->buildCaptchaData('register_captcha_type'),
        ];

        // Steam email-completion registration flow
        if ($request->getQueryParams()['steam'] ?? '' === '1') {
            $templateData['steam_register'] = true;
            $templateData['steam_name']     = $this->session->get('sso_temp_name', '');
            $templateData['steam_avatar']   = $this->session->get('sso_temp_avatar', '');
        }

        return $this->twig->render($response, 'auth/register.twig', $templateData);
    }

    public function register(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        // ── Handle Steam SSO email-completion registration ──
        if (!empty($body['_sso_steam_register'])) {
            $email = trim($body['email'] ?? '');

            if ($email === '') {
                return $this->twig->render($response->withStatus(422), 'auth/register.twig', [
                    'errors'         => ['email' => ['Email is required.']],
                    'old'            => $body,
                    'steam_register' => true,
                    'steam_name'     => $this->session->get('sso_temp_name', ''),
                ]);
            }

            $steamName      = $this->session->get('sso_temp_name', 'Steam User');
            $steamAvatar    = $this->session->get('sso_temp_avatar');
            $ssoProvider    = $this->session->get('sso_temp_provider', 'steam');
            $ssoProviderId  = $this->session->get('sso_temp_provider_id', '');

            $this->session->remove('sso_temp_name');
            $this->session->remove('sso_temp_avatar');
            $this->session->remove('sso_temp_provider');
            $this->session->remove('sso_temp_provider_id');

            if ($ssoProviderId === '') {
                $this->session->flash('error', __('Steam session expired. Please try again.'));
                return $response->withHeader('Location', site_url('/register'))->withStatus(302);
            }

            try {
                $emailVo = new Email($email);
            } catch (\InvalidArgumentException) {
                return $this->twig->render($response->withStatus(422), 'auth/register.twig', [
                    'errors'         => ['email' => ['Invalid email address.']],
                    'old'            => $body,
                    'steam_register' => true,
                    'steam_name'     => $steamName,
                ]);
            }

            // Check duplicate email
            if ($this->users->findByEmail($emailVo) !== null) {
                return $this->twig->render($response->withStatus(422), 'auth/register.twig', [
                    'errors'         => ['email' => ['This email is already registered.']],
                    'old'            => $body,
                    'steam_register' => true,
                    'steam_name'     => $steamName,
                ]);
            }

            $hashedPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);

            $user = new User($emailVo, $hashedPassword, $steamName);
            $user->setSsoProvider($ssoProvider);
            $user->setSsoProviderId($ssoProviderId);
            if ($steamAvatar !== null) {
                $user->setAvatar($steamAvatar);
            }
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $user->recordLogin();
            $this->users->save($user);
            $this->events->doAction('user.registered', $user);

            $this->establishUserSession($user);
            // Send welcome email (Steam users have email verified)
            try {
                $this->mail->sendTemplate(
                    (string) $user->getEmail(),
                    $this->settings->get('site_name', 'Vale') . ' — ' . __('Welcome to :site', [':site' => $this->settings->get('site_name', 'Vale')]),
                    'mail/welcome',
                    [
                        'name'      => $user->getName(),
                        'site_name' => $this->settings->get('site_name', 'Vale'),
                        'login_url' => absolute_url('/login', [], ['lang' => $user->getLocale()]),
                    ],
                    locale: $user->getLocale(),
                    recipientType: 'customer',
                );
            } catch (\Throwable) {
                // silent
            }

            $this->session->remove('sso_provider');
            $this->session->remove('sso_is_register');

            $redirect = trim($body['redirect'] ?? '');
            if ($redirect === '' && $this->session->has('redirect_after_login')) {
                $redirect = $this->session->get('redirect_after_login');
                $this->session->remove('redirect_after_login');
            }

            $this->session->flash('success', 'Account created! Welcome aboard.');
            $target = $redirect !== '' ? $redirect : ($this->settings->get('routing_account_prefix', '/account') ?: '/account');
            if (!$this->isSafeInternalUrl($target)) {
                $target = $this->settings->get('routing_account_prefix', '/account') ?: '/account';
            }

            return $response->withHeader('Location', $target)->withStatus(302);
        }

        // ── Normal email/password registration ──
        $captchaError = $this->validateCaptcha($body, 'register_captcha_type');
        if ($captchaError !== null) {
            return $this->twig->render($response->withStatus(422), 'auth/register.twig', [
                'errors'           => ['captcha' => [$captchaError]],
                'old'              => $body,
                'register_captcha' => $this->buildCaptchaData('register_captcha_type'),
            ]);
        }

        try {
            $body['role']         = $this->settings->get('registration_default_role', 'subscriber');
            $body['verify_email'] = $this->settings->get('registration_verify_email', '1') === '1';
            $user                 = $this->registerUseCase->execute($body);

            $token = $user->getVerificationToken();
            if ($token !== null) {
                $verifyUrl = absolute_url('/verify-email', [], ['lang' => $user->getLocale(), 'token' => $token]);

                try {
                    $this->mail->sendTemplate(
                        (string) $user->getEmail(),
                        $this->settings->get('site_name', 'Vale') . ' — ' . $this->mail->getTranslator()->get('Verify your email address', [], $user->getLocale()),
                        'mail/email-verify',
                        [
                            'name'       => $user->getName(),
                            'verify_url' => $verifyUrl,
                        ],
                        locale: $user->getLocale(),
                        recipientType: 'customer',
                    );
                } catch (\Throwable) {
                    // Mail send failed — still allow registration
                }
            }

            // Send welcome email only if email is already verified or verification is disabled
            $shouldSendWelcome = $user->getEmailVerifiedAt()              !== null
                || $this->settings->get('registration_verify_email', '1') !== '1';

            if ($shouldSendWelcome) {
                try {
                    $this->mail->sendTemplate(
                        (string) $user->getEmail(),
                        $this->settings->get('site_name', 'Vale') . ' — ' . __('Welcome to :site', [':site' => $this->settings->get('site_name', 'Vale')]),
                        'mail/welcome',
                        [
                            'name'      => $user->getName(),
                            'site_name' => $this->settings->get('site_name', 'Vale'),
                            'login_url' => absolute_url('/login', [], ['lang' => $user->getLocale()]),
                        ],
                        locale: $user->getLocale(),
                        recipientType: 'customer',
                    );
                } catch (\Throwable) {
                    // silent
                }
            }

            $redirect = trim($body['redirect'] ?? '');

            if ($user->getEmailVerifiedAt() !== null) {
                $this->session->flash('success', 'Account created! You can now log in.');
            } else {
                $this->session->flash('success', 'Account created! Please check your email to verify your address before logging in.');
            }

            $target = site_url('/login');
            if ($redirect !== '') {
                $target .= '?redirect=' . urlencode($redirect);
            }
            return $response->withHeader('Location', $target)->withStatus(302);
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            return $this->twig->render($response->withStatus(422), 'auth/register.twig', [
                'errors'           => $errors,
                'old'              => $body,
                'register_captcha' => $this->buildCaptchaData('register_captcha_type'),
            ]);
        }
    }

    public function showLogin(Request $request, Response $response): Response
    {
        $this->session->start();
        if ($this->session->has('user_id')) {
            $redirect = trim($request->getQueryParams()['redirect'] ?? '');
            if ($redirect === '' && $this->session->has('redirect_after_login')) {
                $redirect = $this->session->get('redirect_after_login');
                $this->session->remove('redirect_after_login');
            }

            $target = $redirect !== '' ? $redirect : ($this->settings->get('routing_account_prefix', '/account') ?: '/account');

            if (!$this->isSafeInternalUrl($target)) {
                $target = $this->settings->get('routing_account_prefix', '/account') ?: '/account';
            }

            return $response
                ->withHeader('Location', $target)
                ->withStatus(302);
        }

        $redirect = $request->getQueryParams()['redirect'] ?? '';

        return $this->twig->render($response, 'auth/login.twig', [
            'redirect'      => $redirect,
            'login_captcha' => $this->buildCaptchaData('login_captcha_type'),
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $body     = (array) $request->getParsedBody();
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $remember = isset($body['remember']);
        $redirect = trim($body['redirect'] ?? '');

        $captchaError = $this->validateCaptcha($body, 'login_captcha_type');
        if ($captchaError !== null) {
            return $this->twig->render($response->withStatus(422), 'auth/login.twig', [
                'error'         => $captchaError,
                'old_email'     => $email,
                'redirect'      => $redirect,
                'login_captcha' => $this->buildCaptchaData('login_captcha_type'),
            ]);
        }

        try {
            $ip     = vl_client_ip($request);
            $result = $this->loginUseCase->execute($email, $password, $remember, $ip);

            if (!empty($result['two_factor_required'])) {
                $this->session->set('two_factor_user_id', $result['id']);
                $this->session->set('two_factor_remember', $remember);
                $this->session->set('two_factor_redirect', $redirect);

                return $response->withHeader('Location', site_url('/login/two-factor'))->withStatus(302);
            }

            if (!empty($result['two_factor_setup_required'])) {
                $accountPrefix = $this->settings->get('routing_account_prefix', '/account') ?: '/account';

                return $response->withHeader('Location', $accountPrefix . '/two-factor/forced-setup')->withStatus(302);
            }

            $emailVo = new Email($email);
            $user    = $this->users->findByEmail($emailVo);

            if ($user !== null && $user->getEmailVerifiedAt() === null) {
                $this->session->destroy();
                return $this->twig->render($response->withStatus(403), 'auth/login.twig', [
                    'error'     => 'Please verify your email address before logging in. Check your inbox for the verification link.',
                    'old_email' => $email,
                    'redirect'  => $redirect,
                ]);
            }

            if ($redirect === '' && $this->session->has('redirect_after_login')) {
                $redirect = $this->session->get('redirect_after_login');
                $this->session->remove('redirect_after_login');
            }

            $target = $redirect !== '' ? $redirect : '/';

            if (!$this->isSafeInternalUrl($target)) {
                $target = '/';
            }

            return $response
                ->withHeader('Location', $target)
                ->withStatus(302);
        } catch (ValidationException $e) {
            $errors     = $e->getErrors();
            $firstError = reset($errors);

            return $this->twig->render($response->withStatus(422), 'auth/login.twig', [
                'error'         => $firstError[0] ?? 'Login failed.',
                'old_email'     => $email,
                'redirect'      => $redirect,
                'login_captcha' => $this->buildCaptchaData('login_captcha_type'),
            ]);
        }
    }

    public function showTwoFactorChallenge(Request $request, Response $response): Response
    {
        if (!$this->session->has('two_factor_user_id')) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        return $this->twig->render($response, 'auth/two-factor.twig');
    }

    public function twoFactorChallenge(Request $request, Response $response): Response
    {
        $userId = $this->session->get('two_factor_user_id');
        if (!$userId) {
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $user = $this->users->findById((int) $userId);
        if ($user === null || !$user->isTwoFactorEnabled() || !$user->isActive()) {
            // Re-check activation: the account may have been deactivated
            // between the password step and this challenge.
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $body = (array) $request->getParsedBody();
        $code = trim($body['code'] ?? '');

        // Verify TOTP or Recovery Code
        $verified = false;
        if (strlen($code) === 6 && is_numeric($code)) {
            $slice = $this->twoFactorService->verifyCodeSlice((string) $user->getTwoFactorSecret(), $code);
            if ($slice !== null) {
                $lastSlice = $user->getTotpLastSlice() ?? 0;
                if ($slice <= $lastSlice) {
                    // Replay: this timeslice was already consumed
                    error_log('[2FA] TOTP replay detected for user #' . $user->getId());
                } else {
                    $user->setTotpLastSlice($slice);
                    $this->users->save($user);
                    $verified = true;
                }
            }
        } else {
            // Check recovery codes (timing-safe)
            $recoveryCodes = $user->getTwoFactorRecoveryCodes();
            foreach ($recoveryCodes as $candidate) {
                if (is_string($candidate) && $candidate !== '' && hash_equals($candidate, $code)) {
                    $verified = true;
                    break;
                }
            }
            if ($verified) {
                // Consume recovery code
                $newCodes = array_values(array_diff($recoveryCodes, [$code]));
                $user->setTwoFactorRecoveryCodes($newCodes);
                $this->users->save($user);
            }
        }

        if (!$verified) {
            return $this->twig->render($response->withStatus(422), 'auth/two-factor.twig', [
                'error' => 'Invalid authentication code.',
            ]);
        }

        // Complete Login
        $this->session->remove('two_factor_user_id');
        $remember = $this->session->get('two_factor_remember', false);
        $redirect = $this->session->get('two_factor_redirect', '');
        $this->session->remove('two_factor_remember');
        $this->session->remove('two_factor_redirect');

        // We need to re-login to set session variables, but without password check.
        // For simplicity, we manually set the session here as the password was already verified.
        $this->establishUserSession($user);

        if ($remember) {
            $this->session->set('_remember_me', true);
        }

        $target = $redirect !== '' ? $redirect : '/';
        if (!$this->isSafeInternalUrl($target)) {
            $target = '/';
        }

        return $response->withHeader('Location', $target)->withStatus(302);
    }

    public function verifyEmail(Request $request, Response $response): Response
    {
        $token = trim($request->getQueryParams()['token'] ?? '');

        if ($token === '') {
            return $this->twig->render($response->withStatus(400), 'auth/login.twig', [
                'error' => 'Invalid verification link.',
            ]);
        }

        $user = $this->users->findByVerificationToken($token);

        if ($user === null) {
            return $this->twig->render($response->withStatus(400), 'auth/login.twig', [
                'error' => 'Invalid or expired verification link.',
            ]);
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setVerificationToken(null);
        $user->setVerificationTokenExpiresAt(null);
        $user->setIsActive(true);
        $this->users->save($user);

        // Fire user.registered event after email verification
        $this->events->doAction('user.registered', $user);

        // Send welcome email after verification
        try {
            $this->mail->sendTemplate(
                (string) $user->getEmail(),
                $this->settings->get('site_name', 'Vale') . ' — ' . __('Welcome to :site', [':site' => $this->settings->get('site_name', 'Vale')]),
                'mail/welcome',
                [
                    'name'      => $user->getName(),
                    'site_name' => $this->settings->get('site_name', 'Vale'),
                    'login_url' => absolute_url('/login', [], ['lang' => $user->getLocale()]),
                ],
                locale: $user->getLocale(),
                recipientType: 'customer',
            );
        } catch (\Throwable) {
            // silent
        }

        $this->session->flash('success', 'Email verified! You can now log in.');
        return $response->withHeader('Location', site_url('/login'))->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $redirect = trim($request->getQueryParams()['redirect'] ?? '');

        if (!$this->isSafeInternalUrl($redirect)) {
            $redirect = '';
        }

        $this->logoutUseCase->execute();

        $target = site_url('/login');
        if ($redirect !== '') {
            $target .= '?redirect=' . urlencode($redirect);
        }

        return $response
            ->withHeader('Location', $target)
            ->withStatus(302);
    }

    public function ssoRedirect(Request $request, Response $response, array $args): Response
    {
        $this->session->start();
        $provider = $args['provider'] ?? '';

        $refData      = require __DIR__ . '/../../../config/reference-data.php';
        $ssoProviders = $refData['sso_providers'] ?? [];

        if (!isset($ssoProviders[$provider])) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $isRegister = ($request->getQueryParams()['register'] ?? '') === '1';
        $redirect   = $request->getQueryParams()['redirect'] ?? '';

        if ($redirect !== '') {
            $this->session->set('redirect_after_login', $redirect);
        }

        if ($isRegister) {
            $regEnabled = $this->settings->get('sso_registration_enabled', '0');
            if ($regEnabled !== '1') {
                return $this->twig->render($response->withStatus(404), 'errors/feature-disabled.twig', [
                    'feature' => 'registration',
                    'title'   => 'Registration Disabled',
                ]);
            }
        } else {
            $loginEnabled = $this->settings->get('sso_login_enabled', '0');
            if ($loginEnabled !== '1') {
                return $this->twig->render($response->withStatus(404), 'errors/feature-disabled.twig', [
                    'feature' => 'sso login',
                    'title'   => 'SSO Login Disabled',
                ]);
            }
        }

        $this->session->set('sso_provider', $provider);
        $this->session->set('sso_is_register', $isRegister);

        $callbackUrl = $this->buildSiteUrl() . '/auth/sso/callback/' . $provider;

        // Steam uses OpenID instead of OAuth2
        if ($provider === 'steam') {
            return $this->steamOpenIdRedirect($callbackUrl, (string) ($ssoProviders['steam']['url'] ?? ''));
        }

        $clientId     = $this->settings->get('sso_' . $provider . '_client_id', '');
        $clientSecret = $this->settings->get('sso_' . $provider . '_client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            $this->session->flash('error', __('%s SSO is not yet configured. An administrator must set the OAuth client credentials.', ['%s' => $ssoProviders[$provider]['label'] ?? $provider]));
            $target = $isRegister ? site_url('/register') : site_url('/login');
            return $response->withHeader('Location', $target)->withStatus(302);
        }

        $state = bin2hex(random_bytes(16));
        $this->session->set('sso_state', $state);

        $scopes  = $this->getProviderScopes($provider);
        $authUrl = $this->buildProviderAuthUrl($provider, $ssoProviders[$provider]['url'], $clientId, $callbackUrl, $state, $scopes);

        return $response->withHeader('Location', $authUrl)->withStatus(302);
    }

    public function ssoCallback(Request $request, Response $response, array $args): Response
    {
        $this->session->start();
        $provider = $args['provider'] ?? '';

        $refData      = require __DIR__ . '/../../../config/reference-data.php';
        $ssoProviders = $refData['sso_providers'] ?? [];

        if (!isset($ssoProviders[$provider])) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $isRegister = $this->session->get('sso_is_register', false);

        // ── Steam OpenID callback ──
        if ($provider === 'steam') {
            return $this->steamOpenIdCallback($request, $response, (array) $ssoProviders['steam']);
        }

        // ── OAuth2 callback ──
        $expectedState = $this->session->get('sso_state', '');
        $givenState    = $request->getQueryParams()['state'] ?? '';
        if ($expectedState === '' || !hash_equals($expectedState, $givenState)) {
            $this->session->flash('error', __('Invalid SSO state. Please try again.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $error = $request->getQueryParams()['error'] ?? '';
        if ($error !== '') {
            $this->session->flash('error', __('SSO authentication was cancelled or failed.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $code = $request->getQueryParams()['code'] ?? '';
        if ($code === '') {
            $this->session->flash('error', __('No authorization code received from provider.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $clientId     = $this->settings->get('sso_' . $provider . '_client_id', '');
        $clientSecret = $this->settings->get('sso_' . $provider . '_client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            $this->session->flash('error', __('SSO client credentials are not configured.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $callbackUrl = $this->buildSiteUrl() . '/auth/sso/callback/' . $provider;

        // Exchange code for access token
        $tokenResponse = $this->exchangeCodeForToken($provider, $ssoProviders[$provider]['token_url'] ?? '', $clientId, $clientSecret, $code, $callbackUrl);
        if ($tokenResponse === null) {
            $this->session->flash('error', __('Failed to obtain access token from provider.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        $accessToken = $tokenResponse['access_token'] ?? '';
        if ($accessToken === '') {
            $this->session->flash('error', __('No access token received from provider.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        // Fetch user info from provider
        $userInfo = $this->fetchOAuthUserInfo($provider, $ssoProviders[$provider]['user_url'] ?? '', $accessToken);
        if ($userInfo === null) {
            $this->session->flash('error', __('Failed to fetch user info from provider.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        // Extract standardised fields
        [$ssoProviderId, $email, $name, $avatar] = $this->parseOAuthUserInfo($provider, $userInfo);

        // Whether the provider attests ownership of the email address.
        // Only provider-verified emails may auto-link to local accounts.
        $emailVerified = $this->providerEmailVerified($provider, $userInfo);

        // GitHub: /user endpoint returns null email unless public — fetch from /user/emails instead.
        // The emails endpoint also gives us a verified flag we can trust.
        if ($provider === 'github') {
            $emailsUrl  = (string) ($ssoProviders['github']['emails_url'] ?? '');
            if ($email === null) {
                $emailData = $this->fetchOAuthUserInfo($provider, $emailsUrl, $accessToken);
                if (is_array($emailData)) {
                    foreach ($emailData as $entry) {
                        if (($entry['primary'] ?? false) && ($entry['verified'] ?? false)) {
                            $email         = $entry['email'];
                            $emailVerified = true;
                            break;
                        }
                    }
                }
            } else {
                // Public profile email is not proof of ownership — confirm it
                $emailData = $this->fetchOAuthUserInfo($provider, $emailsUrl, $accessToken);
                if (is_array($emailData)) {
                    foreach ($emailData as $entry) {
                        if (strcasecmp((string) ($entry['email'] ?? ''), (string) $email) === 0 && ($entry['verified'] ?? false)) {
                            $emailVerified = true;
                            break;
                        }
                    }
                }
            }
        }

        if ($ssoProviderId === null) {
            $this->session->flash('error', __('Could not identify your account from the provider response.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        // Find or create user
        $user = $this->users->findBySsoProvider($provider, $ssoProviderId);

        if ($user === null && $email !== null) {
            // Check if a user with this email already exists and link SSO
            try {
                $emailVo = new Email($email);
            } catch (\InvalidArgumentException) {
                $this->session->flash('error', __('Invalid email received from SSO provider.'));
                return $response->withHeader('Location', site_url('/login'))->withStatus(302);
            }
            $existingUser = $this->users->findByEmail($emailVo);
            if ($existingUser !== null) {
                if (!$emailVerified) {
                    // Never auto-link an unverified provider email to an
                    // existing local account — that would enable takeover.
                    $this->session->flash('error', __('An account with this email already exists. Please log in with your password to link it.'));
                    return $response->withHeader('Location', site_url('/login'))->withStatus(302);
                }
                $existingUser->setSsoProvider($provider);
                $existingUser->setSsoProviderId($ssoProviderId);
                $existingUser->recordLogin();
                $this->users->save($existingUser);
                $user = $existingUser;
            }
        }

        if ($user === null) {
            $regEnabled = $this->settings->get('registration_enabled', '1');

            if ($regEnabled !== '1') {
                $this->session->flash('error', __('Registration is currently closed to new members.'));
                return $response->withHeader('Location', site_url('/login'))->withStatus(302);
            }

            $ssoRegEnabled = $this->settings->get('sso_registration_enabled', '0');
            if ($ssoRegEnabled !== '1') {
                $this->session->flash('info', __('No account found with this SSO. You can register with your email and link your SSO account later.'));
                $target = site_url('/register');
                if ($this->session->has('redirect_after_login')) {
                    $target .= '?redirect=' . urlencode($this->session->get('redirect_after_login'));
                }
                return $response->withHeader('Location', $target)->withStatus(302);
            }

            // Create new user
            if ($email === null) {
                $this->session->flash('error', __('Email is required but was not provided by the SSO provider.'));
                $target = site_url('/register');
                if ($this->session->has('redirect_after_login')) {
                    $target .= '?redirect=' . urlencode($this->session->get('redirect_after_login'));
                }
                return $response->withHeader('Location', $target)->withStatus(302);
            }

            try {
                $emailVo = new Email($email);
            } catch (\InvalidArgumentException) {
                $this->session->flash('error', __('Invalid email received from SSO provider.'));
                $target = site_url('/register');
                if ($this->session->has('redirect_after_login')) {
                    $target .= '?redirect=' . urlencode($this->session->get('redirect_after_login'));
                }
                return $response->withHeader('Location', $target)->withStatus(302);
            }

            $name           = $name ?: $email;
            $hashedPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);

            $user = new User($emailVo, $hashedPassword, $name);
            $user->setSsoProvider($provider);
            $user->setSsoProviderId($ssoProviderId);
            if ($avatar !== null) {
                $user->setAvatar($avatar);
            }
            // The provider authenticated this human; auto-verify new accounts
            // so the login gate cannot lock them out. Account LINKING to an
            // existing local user remains gated by $emailVerified above.
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $user->recordLogin();
            $this->users->save($user);
            $this->events?->doAction('user.registered', $user);

            // Send welcome email
            try {
                $this->mail->sendTemplate(
                    (string) $user->getEmail(),
                    $this->settings->get('site_name', 'Vale') . ' — ' . __('Welcome to :site', [':site' => $this->settings->get('site_name', 'Vale')]),
                    'mail/welcome',
                    [
                        'name'      => $user->getName(),
                        'site_name' => $this->settings->get('site_name', 'Vale'),
                        'login_url' => absolute_url('/login', [], ['lang' => $user->getLocale()]),
                    ],
                    locale: $user->getLocale(),
                    recipientType: 'customer',
                );
            } catch (\Throwable) {
                // silent
            }
        } else {
            // Existing SSO user — update info and log in
            if ($name !== null) {
                $user->setName($name);
            }
            if ($avatar !== null) {
                $user->setAvatar($avatar);
            }
            $user->recordLogin();
            $this->users->save($user);
        }

        // Log the user in
        $this->establishUserSession($user);

        $this->session->remove('sso_state');
        $this->session->remove('sso_provider');
        $this->session->remove('sso_is_register');

        $this->session->flash('success', sprintf('Welcome back, %s!', $user->getName()));

        $redirect = '';
        if ($this->session->has('redirect_after_login')) {
            $redirect = $this->session->get('redirect_after_login');
            $this->session->remove('redirect_after_login');
        }

        $target = $redirect !== '' ? $redirect : '/';
        if (!$this->isSafeInternalUrl($target)) {
            $target = '/';
        }

        return $response->withHeader('Location', $target)->withStatus(302);
    }

    /**
     * Redirect to Steam OpenID endpoint.
     */
    private function steamOpenIdRedirect(string $callbackUrl, string $loginUrl): Response
    {
        $params = http_build_query([
            'openid.ns'         => 'http://specs.openid.net/auth/2.0',
            'openid.mode'       => 'checkid_setup',
            'openid.return_to'  => $callbackUrl,
            'openid.realm'      => $this->getRealmFromCallback($callbackUrl),
            'openid.identity'   => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ]);
        $response = new \Slim\Psr7\Response();
        return $response->withHeader('Location', $loginUrl . '?' . $params)->withStatus(302);
    }

    /**
     * Handle Steam OpenID callback.
     *
     * @param array<string, string> $steamProvider sso_providers.steam entry from reference-data.php
     */
    private function steamOpenIdCallback(Request $request, Response $response, array $steamProvider): Response
    {
        // PHP's parse_str() converts dots (.) to underscores (_) in key names
        // when called without a second parameter (e.g. for $_GET population).
        // Normalise all underscore-versions back to dot-versions.
        $params = $request->getQueryParams();
        $params = $this->normaliseOpenIdKeys($params);

        // Fall back to $_GET if PSR-7 query params are empty
        if (empty($params['openid.mode']) && !empty($_GET)) {
            $params = $this->normaliseOpenIdKeys($_GET);
        }

        $mode = $params['openid.mode'] ?? '';

        if ($mode === 'cancel') {
            $this->session->flash('error', __('Steam login was cancelled.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        if ($mode !== 'id_res') {
            // Try POST body
            $body = $this->normaliseOpenIdKeys((array) $request->getParsedBody());
            $mode = $body['openid.mode'] ?? $mode;
            if ($mode === 'id_res') {
                $params = $body;
            } else {
                $qs = $_SERVER['QUERY_STRING'] ?? '(empty)';
                $this->session->flash('error', __('Steam auth failed') . ' (mode: ' . ($mode ?: 'none') . '). Query: ' . substr($qs, 0, 200));
                return $response->withHeader('Location', site_url('/login'))->withStatus(302);
            }
        }

        // Build verification request
        $verifyParams = $params;
        unset($verifyParams['openid.mode']);
        $verifyParams['openid.mode'] = 'check_authentication';

        $verifyUrl = (string) ($steamProvider['url'] ?? '');
        if ($verifyUrl === '') {
            throw new \RuntimeException(
                'Missing url for Steam in config/reference-data.php (sso_providers.steam) — called from steamOpenIdCallback().'
            );
        }

        $verifyBody = http_build_query($verifyParams);
        $resp       = @file_get_contents($verifyUrl, false, stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $verifyBody,
            ],
        ]));

        if ($resp === false || !str_contains($resp, 'is_valid:true')) {
            $this->session->flash('error', __('Steam OpenID verification failed.'));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        // Extract Steam ID from claimed_id (format: https://steamcommunity.com/openid/id/76561197960287930)
        $claimedId = $params['openid.claimed_id'] ?? '';
        $steamId   = preg_replace('~^https?://steamcommunity\.com/openid/id/~', '', $claimedId);

        if ($steamId === '' || $steamId === $claimedId) {
            $this->session->flash('error', __('Could not extract Steam ID') . '. claimed_id: ' . substr($claimedId, 0, 150));
            return $response->withHeader('Location', site_url('/login'))->withStatus(302);
        }

        // Fetch Steam user info via Steam Web API
        $steamApiKey  = $this->settings->get('steam_api_key', '');
        $playerName   = 'Steam User';
        $playerAvatar = null;

        if ($steamApiKey !== '') {
            $apiBase = (string) ($steamProvider['api_url'] ?? '');
            if ($apiBase === '') {
                throw new \RuntimeException(
                    'Missing api_url for Steam in config/reference-data.php (sso_providers.steam) — called from steamOpenIdCallback().'
                );
            }
            $apiUrl     = $apiBase . '/ISteamUser/GetPlayerSummaries/v2/?key=' . urlencode($steamApiKey) . '&steamids=' . urlencode($steamId);
            $playerResp = @file_get_contents($apiUrl);
            if ($playerResp !== false) {
                $playerData = json_decode($playerResp, true);
                $player     = $playerData['response']['players'][0] ?? null;
                if ($player !== null) {
                    $playerName   = $player['personaname'] ?? $playerName;
                    $playerAvatar = $player['avatarfull']  ?? null;
                }
            }
        }

        // Find or create user by Steam ID
        $user = $this->users->findBySsoProvider('steam', $steamId);

        if ($user === null) {
            $regEnabled = $this->settings->get('registration_enabled', '1');

            if ($regEnabled !== '1') {
                $this->session->flash('error', __('Registration is currently closed to new members.'));
                return $response->withHeader('Location', site_url('/login'))->withStatus(302);
            }

            $ssoRegEnabled = $this->settings->get('sso_registration_enabled', '0');
            if ($ssoRegEnabled !== '1') {
                $this->session->flash('info', __('No account found with this SSO. You can register with your email and link your SSO account later.'));
                $target = site_url('/register');
                if ($this->session->has('redirect_after_login')) {
                    $target .= '?redirect=' . urlencode($this->session->get('redirect_after_login'));
                }
                return $response->withHeader('Location', $target)->withStatus(302);
            }

            // Cannot create a Steam user without email — require the user to provide it
            $this->session->set('sso_temp_name', $playerName);
            $this->session->set('sso_temp_avatar', $playerAvatar);
            $this->session->set('sso_temp_provider', 'steam');
            $this->session->set('sso_temp_provider_id', $steamId);
            $this->session->flash('info', __('Steam account verified! Please provide an email address to complete registration.'));
            $target = site_url('/register') . '?steam=1';
            if ($this->session->has('redirect_after_login')) {
                $target .= '&redirect=' . urlencode($this->session->get('redirect_after_login'));
            }
            return $response->withHeader('Location', $target)->withStatus(302);
        }

        // Existing Steam user — log them in
        if ($playerAvatar !== null) {
            $user->setAvatar($playerAvatar);
        }
        $user->recordLogin();
        $this->users->save($user);

        $this->establishUserSession($user);

        $this->session->remove('sso_provider');
        $this->session->remove('sso_is_register');

        $this->session->flash('success', sprintf('Welcome back, %s!', $user->getName()));

        $redirect = '';
        if ($this->session->has('redirect_after_login')) {
            $redirect = $this->session->get('redirect_after_login');
            $this->session->remove('redirect_after_login');
        }

        $target = $redirect !== '' ? $redirect : '/';
        if (!$this->isSafeInternalUrl($target)) {
            $target = '/';
        }

        return $response->withHeader('Location', $target)->withStatus(302);
    }

    /**
     * Exchange OAuth2 authorization code for an access token.
     * @return array<string, mixed>|null
     */
    protected function exchangeCodeForToken(string $provider, string $tokenUrl, string $clientId, string $clientSecret, string $code, string $callbackUrl): ?array
    {
        if ($tokenUrl === '' || $clientId === '' || $clientSecret === '') {
            return null;
        }

        $postData = [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $callbackUrl,
            'grant_type'    => 'authorization_code',
        ];

        // GitHub prefers Accept: application/json for the response but still
        // expects a form-urlencoded body for the token exchange request.
        $wantsJsonResponse = $provider === 'github';

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: Vale-SSO/1.0',
        ];

        if ($wantsJsonResponse) {
            $headers[] = 'Accept: application/json';
        }

        $content = http_build_query($postData);

        $resp = @file_get_contents($tokenUrl, false, stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $content,
                'ignore_errors' => true,
            ],
        ]));

        if ($resp === false) {
            return null;
        }

        // If we asked for JSON and got JSON, decode it
        if ($wantsJsonResponse && str_starts_with(trim($resp), '{')) {
            return json_decode($resp, true);
        }

        // Fallback for non-JSON responses (e.g. GitHub without Accept header, or other providers)
        $decoded = json_decode($resp, true);
        if ($decoded !== null) {
            return $decoded;
        }

        // Last resort: try form-encoded parsing
        parse_str($resp, $result);
        return $result;
    }

    /**
     * Fetch user info from the provider's user API.
     * @return array<string, mixed>|null
     */
    protected function fetchOAuthUserInfo(string $provider, string $userUrl, string $accessToken): ?array
    {
        if ($userUrl === '' || $accessToken === '') {
            return null;
        }

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'User-Agent: Vale-SSO/1.0',
        ];

        $resp = @file_get_contents($userUrl, false, stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ]));

        if ($resp === false) {
            return null;
        }

        if (!json_validate($resp)) {
            return null;
        }

        return json_decode($resp, true);
    }

    /**
     * Parse provider-specific user info into standardised fields.
     * @param array<string, mixed> $userInfo
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string} [providerId, email, name, avatarUrl]
     */
    private function parseOAuthUserInfo(string $provider, array $userInfo): array
    {
        return match ($provider) {
            'google' => [
                (string) ($userInfo['sub'] ?? ''),
                $userInfo['email']   ?? null,
                $userInfo['name']    ?? null,
                $userInfo['picture'] ?? null,
            ],
            'microsoft' => [
                (string) ($userInfo['id'] ?? ''),
                $userInfo['mail']        ?? $userInfo['userPrincipalName'] ?? null,
                $userInfo['displayName'] ?? null,
                null, // avatar not available from basic Graph /me endpoint
            ],
            'twitter' => [
                $userInfo['data']['id'] ?? '',
                null, // Twitter/X OAuth 2.0 does not provide email by default
                $userInfo['data']['name']              ?? $userInfo['data']['username'] ?? null,
                $userInfo['data']['profile_image_url'] ?? null,
            ],
            'github' => [
                (string) ($userInfo['id'] ?? ''),
                $userInfo['email']      ?? null,
                $userInfo['name']       ?? $userInfo['login'] ?? null,
                $userInfo['avatar_url'] ?? null,
            ],
            'facebook' => [
                (string) ($userInfo['id'] ?? ''),
                $userInfo['email']                  ?? null,
                $userInfo['name']                   ?? null,
                $userInfo['picture']['data']['url'] ?? null,
            ],
            default => [(string) ($userInfo['id'] ?? ''), null, null, null],
        };
    }

    /**
     * Whether the provider attests that the returned email address is owned
     * and verified by the user. Used to guard SSO-to-local account linking.
     */
    private function providerEmailVerified(string $provider, array $userInfo): bool
    {
        return match ($provider) {
            'google'    => ($userInfo['email_verified'] ?? false) === true,
            'facebook'  => ($userInfo['email'] ?? null)                                  !== null, // email only returned for verified accounts
            'microsoft' => ($userInfo['mail'] ?? $userInfo['userPrincipalName'] ?? null) !== null,
            default     => false, // e.g. GitHub public profile email — confirmed via /user/emails instead
        };
    }

    /**
     * Normalise OpenID parameter keys: PHP's parse_str() converts dots (.) to
     * underscores (_) when populating $_GET. This restores only the first dot
     * separator after "openid", leaving internal underscores intact.
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normaliseOpenIdKeys(array $params): array
    {
        $normalised = [];
        foreach ($params as $k => $v) {
            if (str_starts_with($k, 'openid_')) {
                $k = 'openid.' . substr($k, 7);
            }
            $normalised[$k] = $v;
        }
        return $normalised;
    }

    private function getRealmFromCallback(string $callbackUrl): string
    {
        $parts = parse_url($callbackUrl);
        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'localhost');
    }

    /** @return string[] */
    private function getProviderScopes(string $provider): array
    {
        return match ($provider) {
            'google'    => ['openid', 'email', 'profile'],
            'microsoft' => ['openid', 'email', 'profile', 'User.Read'],
            'twitter'   => ['tweet.read', 'users.read'],
            'github'    => ['read:user', 'user:email'],
            'facebook'  => ['email', 'public_profile'],
            'steam'     => [],
            default     => ['email', 'profile'],
        };
    }

    private function buildProviderAuthUrl(string $provider, string $baseUrl, string $clientId, string $callbackUrl, string $state, array $scopes): string
    {
        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        $params    = [
            'client_id'     => $clientId,
            'redirect_uri'  => $callbackUrl,
            'state'         => $state,
            'response_type' => 'code',
        ];
        if (!empty($scopes)) {
            $params['scope'] = implode(' ', $scopes);
        }
        return $baseUrl . $separator . http_build_query($params);
    }

    private function buildSiteUrl(): string
    {
        return __require_app_url();
    }

    /**
     * Common method to establish a user session after successful SSO/password authentication.
     */
    private function establishUserSession(User $user): void
    {
        $this->session->regenerate();
        $this->session->set('user_id', $user->getId());
        $this->session->set('user_name', $user->getName());
        $this->session->set('user_email', (string) $user->getEmail());

        $role = User::resolvePrimaryRole($user->getRoles());

        $this->session->set('user_role', $role);
        $this->session->set('user_roles', $user->getRoles());
        $this->session->set('user_locale', $user->getLocale());
        $this->session->set('user_permissions', $this->roleRepo->findPermissionSlugsForUser($user->getId()));
        $this->session->set('session_version', $user->getSessionVersion());
    }

    /**
     * Ensures a URL is a safe internal path (starts with / but not // or /\).
     */
    private function isSafeInternalUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        return str_starts_with($url, '/')
            && !str_starts_with($url, '//')
            && !str_starts_with($url, '/\\');
    }
}
