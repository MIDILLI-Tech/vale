<?php

declare(strict_types=1);

namespace ValeApp\Application\Auth;

use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Security\RateLimiter;
use ValeApp\Infrastructure\Session\SessionManager;

/**
 * Use case: Authenticate a user with email + password.
 */
final class LoginUseCase
{
    /** @var array{enabled: bool, lifetime: int} */
    private readonly array $rememberConfig;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $session,
        private readonly RoleRepositoryInterface $roleRepo,
        private readonly RateLimiter $limiter,
        private readonly SettingsRepositoryInterface $settings,
        array $rememberConfig = [],
    ) {
        $this->rememberConfig = array_merge(
            ['enabled' => true, 'lifetime' => 43200],
            $rememberConfig,
        );
    }

    /**
     * @return array{id: int, name: string, email: string, role: string, two_factor_required: bool, two_factor_setup_required: bool}
     * @throws ValidationException on invalid credentials
     */
    public function execute(string $email, string $password, bool $remember = false, string $clientIp = '127.0.0.1'): array
    {
        $user = $this->users->findByEmail(new Email($email));

        if ($user === null || !$this->hasher->verify($password, $user->getPassword())) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }

        // Gate deactivated accounts before any branch — a suspended user must
        // not reach the 2FA challenge path either.
        if (!$user->isActive()) {
            throw ValidationException::withMessages(['email' => 'Your account has been deactivated.']);
        }

        if ($user->isTwoFactorEnabled()) {
            return [
                'id'                  => $user->getId(),
                'name'                => $user->getName(),
                'email'               => (string) $user->getEmail(),
                'role'                => User::resolvePrimaryRole($user->getRoles()),
                'two_factor_required' => true,
            ];
        }

        // Rehash if needed (algo/cost upgrade)
        if ($this->hasher->needsRehash($user->getPassword())) {
            $user->setPassword($this->hasher->hash($password));
            $this->users->save($user);
        }

        // Record login timestamp
        $user->recordLogin();
        $this->users->updateLastLogin($user->getId());

        // Build session
        $this->session->regenerate();

        if ($remember && $this->rememberConfig['enabled']) {
            $this->session->set('_remember_me', true);
            $this->session->set('_remember_lifetime', $this->rememberConfig['lifetime']);
        }

        $this->limiter->clear('login', $clientIp);

        $primaryRole = User::resolvePrimaryRole($user->getRoles());

        $permissions = $this->roleRepo->findPermissionSlugsForUser($user->getId());

        $this->session->set('user_id', $user->getId());
        $this->session->set('user_name', $user->getName());
        $this->session->set('user_email', (string) $user->getEmail());
        $this->session->set('user_role', $primaryRole);
        $this->session->set('user_roles', $user->getRoles());
        $this->session->set('user_locale', $user->getLocale());
        $this->session->set('user_permissions', $permissions);
        $this->session->set('session_version', $user->getSessionVersion());

        // Force 2FA enrollment when requested globally.
        // If global force is OFF, users are not restricted to the setup page
        // even if a secret has been provisioned for them.
        $twoFactorForced        = $this->settings->get('two_factor_forced_global', '0') === '1';
        $twoFactorSetupRequired = $twoFactorForced && !$user->isTwoFactorEnabled();

        if ($twoFactorSetupRequired) {
            $this->session->set('two_factor_pending', true);
        }

        return [
            'id'                        => $user->getId(),
            'name'                      => $user->getName(),
            'email'                     => (string) $user->getEmail(),
            'role'                      => $primaryRole,
            'two_factor_required'       => false,
            'two_factor_setup_required' => $twoFactorSetupRequired,
        ];
    }
}
