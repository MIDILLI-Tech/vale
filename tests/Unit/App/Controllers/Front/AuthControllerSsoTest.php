<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Front\AuthController;
use ValeApp\Application\Auth\LoginUseCase;
use ValeApp\Application\Auth\LogoutUseCase;
use ValeApp\Application\Auth\RegisterUseCase;
use ValeApp\Application\Auth\TwoFactorService;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\RateLimiter;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class AuthControllerSsoTest extends TestCase
{
    private function createLoginUseCase(): LoginUseCase
    {
        return new LoginUseCase(
            $this->createStub(UserRepositoryInterface::class),
            new PasswordHasher(PASSWORD_BCRYPT, 4),
            $this->createStub(SessionManager::class),
            $this->createStub(RoleRepositoryInterface::class),
            new RateLimiter(sys_get_temp_dir() . '/vl_sso_test_' . uniqid()),
            $this->createStub(DbalSettingsRepository::class)
        );
    }

    private function createRegisterUseCase(): RegisterUseCase
    {
        return new RegisterUseCase(
            $this->createStub(UserRepositoryInterface::class),
            $this->createStub(SubscriberRepositoryInterface::class),
            new PasswordHasher(PASSWORD_BCRYPT, 4)
        );
    }

    private function createLogoutUseCase(): LogoutUseCase
    {
        return new LogoutUseCase(
            $this->createStub(SessionManager::class)
        );
    }

    private function createTwoFactorService(): TwoFactorService
    {
        return new TwoFactorService();
    }

    private function createMailService(): MailService
    {
        return new MailService(
            $this->createStub(MailInterface::class),
            $this->createStub(Twig::class),
            $this->createStub(DbalSettingsRepository::class),
            $this->createStub(Translator::class),
            $this->createStub(ThemeManager::class)
        );
    }

    private function createWebhookService(): OutboundWebhookService
    {
        return new OutboundWebhookService(
            $this->createStub(WebhookRepositoryInterface::class)
        );
    }

    private function createSsoController(
        SessionManager $session,
        DbalSettingsRepository $settings,
        UserRepositoryInterface $users,
        array $oauthUserInfo,
    ): AuthController {
        $twig             = $this->createStub(Twig::class);
        $loginUseCase     = $this->createLoginUseCase();
        $logoutUseCase    = $this->createLogoutUseCase();
        $registerUseCase  = $this->createRegisterUseCase();
        $twoFactorService = $this->createTwoFactorService();
        $mail             = $this->createMailService();
        $roleRepo         = $this->createStub(RoleRepositoryInterface::class);
        $events           = $this->createStub(EventDispatcherInterface::class);
        $webhookService   = $this->createWebhookService();

        return new class (
            $twig,
            $loginUseCase,
            $logoutUseCase,
            $registerUseCase,
            $twoFactorService,
            $session,
            $mail,
            $users,
            $settings,
            $roleRepo,
            $events,
            $webhookService,
            $oauthUserInfo
        ) extends AuthController {
            private array $oauthUserInfo;

            public function __construct(
                Twig $twig,
                LoginUseCase $loginUseCase,
                LogoutUseCase $logoutUseCase,
                RegisterUseCase $registerUseCase,
                TwoFactorService $twoFactorService,
                SessionManager $session,
                MailService $mail,
                UserRepositoryInterface $users,
                DbalSettingsRepository $settings,
                RoleRepositoryInterface $roleRepo,
                EventDispatcherInterface $events,
                OutboundWebhookService $webhookService,
                array $oauthUserInfo,
            ) {
                parent::__construct($twig, $loginUseCase, $logoutUseCase, $registerUseCase, $twoFactorService, $session, $mail, $users, $settings, $roleRepo, $events, $webhookService);
                $this->oauthUserInfo = $oauthUserInfo;
            }

            protected function exchangeCodeForToken(string $provider, string $tokenUrl, string $clientId, string $clientSecret, string $code, string $callbackUrl): ?array
            {
                return ['access_token' => 'dummy_token'];
            }

            protected function fetchOAuthUserInfo(string $provider, string $userUrl, string $accessToken): ?array
            {
                return $this->oauthUserInfo;
            }
        };
    }

    public function testBlocksNewUserWhenGlobalRegistrationDisabled(): void
    {
        $session = $this->createMock(SessionManager::class);
        $session->method('get')->willReturnCallback(function (string $key, mixed $default = null) {
            return match ($key) {
                'sso_is_register' => false,
                'sso_state'       => 'test_state',
                default           => $default,
            };
        });

        $session->expects($this->once())
            ->method('flash')
            ->with('error', 'Registration is currently closed to new members.');

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(function (string $key, ?string $default = '') {
            return match ($key) {
                'registration_enabled'     => '0',
                'sso_google_client_id'     => 'client_id',
                'sso_google_client_secret' => 'client_secret',
                default                    => $default,
            };
        });

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findBySsoProvider')->willReturn(null);
        $users->method('findByEmail')->willReturn(null);

        $controller = $this->createSsoController($session, $settings, $users, [
            'sub'            => 'google_123',
            'email'          => 'newuser@example.com',
            'name'           => 'New User',
            'email_verified' => true,
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/auth/sso/callback/google')
            ->withQueryParams(['state' => 'test_state', 'code' => 'valid_code']);

        $result = $controller->ssoCallback($request, new Response(), ['provider' => 'google']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertStringContainsString('/login', $result->getHeaderLine('Location'));
    }

    public function testRedirectsNewUserToRegisterWhenSsoRegistrationDisabledButGlobalEnabled(): void
    {
        $session = $this->createMock(SessionManager::class);
        $session->method('get')->willReturnCallback(function (string $key, mixed $default = null) {
            return match ($key) {
                'sso_is_register' => false,
                'sso_state'       => 'test_state',
                default           => $default,
            };
        });

        $session->expects($this->once())
            ->method('flash')
            ->with('info', 'No account found with this SSO. You can register with your email and link your SSO account later.');

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(function (string $key, ?string $default = '') {
            return match ($key) {
                'registration_enabled'     => '1',
                'sso_registration_enabled' => '0',
                'sso_google_client_id'     => 'client_id',
                'sso_google_client_secret' => 'client_secret',
                default                    => $default,
            };
        });

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findBySsoProvider')->willReturn(null);
        $users->method('findByEmail')->willReturn(null);

        $controller = $this->createSsoController($session, $settings, $users, [
            'sub'            => 'google_123',
            'email'          => 'newuser@example.com',
            'name'           => 'New User',
            'email_verified' => true,
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/auth/sso/callback/google')
            ->withQueryParams(['state' => 'test_state', 'code' => 'valid_code']);

        $result = $controller->ssoCallback($request, new Response(), ['provider' => 'google']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertStringContainsString('/register', $result->getHeaderLine('Location'));
    }

    public function testAllowsExistingUserWhenSsoRegistrationDisabled(): void
    {
        $existingUser = new User(new Email('existing@example.com'), 'password_hash', 'Existing User');
        $existingUser->setId(10);

        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturnCallback(function (string $key, mixed $default = null) {
            return match ($key) {
                'sso_is_register' => false,
                'sso_state'       => 'test_state',
                default           => $default,
            };
        });

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(function (string $key, ?string $default = '') {
            return match ($key) {
                'registration_enabled'     => '1',
                'sso_registration_enabled' => '0',
                'sso_google_client_id'     => 'client_id',
                'sso_google_client_secret' => 'client_secret',
                default                    => $default,
            };
        });

        $roleRepo = $this->createStub(RoleRepositoryInterface::class);
        $roleRepo->method('findPermissionSlugsForUser')->willReturn([]);

        $users = $this->createStub(UserRepositoryInterface::class);
        $users->method('findBySsoProvider')->willReturn($existingUser);

        $twig             = $this->createStub(Twig::class);
        $loginUseCase     = $this->createLoginUseCase();
        $logoutUseCase    = $this->createLogoutUseCase();
        $registerUseCase  = $this->createRegisterUseCase();
        $twoFactorService = $this->createTwoFactorService();
        $mail             = $this->createMailService();
        $events           = $this->createStub(EventDispatcherInterface::class);
        $webhookService   = $this->createWebhookService();

        $controller = new class (
            $twig,
            $loginUseCase,
            $logoutUseCase,
            $registerUseCase,
            $twoFactorService,
            $session,
            $mail,
            $users,
            $settings,
            $roleRepo,
            $events,
            $webhookService
        ) extends AuthController {
            protected function exchangeCodeForToken(string $provider, string $tokenUrl, string $clientId, string $clientSecret, string $code, string $callbackUrl): ?array
            {
                return ['access_token' => 'dummy_token'];
            }

            protected function fetchOAuthUserInfo(string $provider, string $userUrl, string $accessToken): ?array
            {
                return [
                    'sub'            => 'google_123',
                    'email'          => 'existing@example.com',
                    'name'           => 'Existing User',
                    'email_verified' => true,
                ];
            }
        };

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/auth/sso/callback/google')
            ->withQueryParams(['state' => 'test_state', 'code' => 'valid_code']);

        $result = $controller->ssoCallback($request, new Response(), ['provider' => 'google']);

        $this->assertSame(302, $result->getStatusCode());
        $this->assertSame('/', $result->getHeaderLine('Location'));
    }
}
