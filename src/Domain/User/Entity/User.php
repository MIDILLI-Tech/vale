<?php

declare(strict_types=1);

namespace ValeApp\Domain\User\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Domain\Shared\ValueObject\Email;

/**
 * User domain entity.
 */
class User extends BaseEntity
{
    private Email $email;
    private string $password;
    private string $name;
    private ?string $avatar                                 = null;
    private string $locale                                  = 'en';
    private bool $isActive                                  = true;
    private ?\DateTimeImmutable $emailVerifiedAt            = null;
    private ?string $verificationToken                      = null;
    private ?\DateTimeImmutable $verificationTokenExpiresAt = null;
    private ?\DateTimeImmutable $lastLoginAt                = null;
    private ?string $ssoProvider                            = null;
    private ?string $ssoProviderId                          = null;

    private ?string $twoFactorSecret                        = null;
    /** @var string[] */
    private array $twoFactorRecoveryCodes                   = [];
    private bool $twoFactorEnabled                          = false;
    private ?int $totpLastSlice                             = null;

    private int $sessionVersion = 0;

    /** @var string[] */
    private array $roles = [];

    public function __construct(Email $email, string $hashedPassword, string $name)
    {
        $this->email    = $email;
        $this->password = $hashedPassword;
        $this->name     = $name;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function setEmail(Email $email): void
    {
        $this->email = $email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): void
    {
        $this->avatar = $avatar;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $at): void
    {
        $this->emailVerifiedAt = $at;
    }

    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(?string $token): void
    {
        $this->verificationToken = $token;
    }

    public function getVerificationTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->verificationTokenExpiresAt;
    }

    public function setVerificationTokenExpiresAt(?\DateTimeImmutable $at): void
    {
        $this->verificationTokenExpiresAt = $at;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function getSsoProvider(): ?string
    {
        return $this->ssoProvider;
    }

    public function setSsoProvider(?string $ssoProvider): void
    {
        $this->ssoProvider = $ssoProvider;
    }

    public function getSsoProviderId(): ?string
    {
        return $this->ssoProviderId;
    }

    public function setSsoProviderId(?string $ssoProviderId): void
    {
        $this->ssoProviderId = $ssoProviderId;
    }

    public function getTwoFactorSecret(): ?string
    {
        return $this->twoFactorSecret;
    }

    public function setTwoFactorSecret(?string $secret): void
    {
        $this->twoFactorSecret = $secret;
    }

    /** @return string[] */
    public function getTwoFactorRecoveryCodes(): array
    {
        return $this->twoFactorRecoveryCodes;
    }

    /** @param string[] $codes */
    public function setTwoFactorRecoveryCodes(array $codes): void
    {
        $this->twoFactorRecoveryCodes = $codes;
    }

    public function isTwoFactorEnabled(): bool
    {
        return $this->twoFactorEnabled;
    }

    public function setTwoFactorEnabled(bool $enabled): void
    {
        $this->twoFactorEnabled = $enabled;
    }

    public function getTotpLastSlice(): ?int
    {
        return $this->totpLastSlice;
    }

    public function setTotpLastSlice(?int $slice): void
    {
        $this->totpLastSlice = $slice;
    }

    public function getSessionVersion(): int
    {
        return $this->sessionVersion;
    }

    public function setSessionVersion(int $version): void
    {
        $this->sessionVersion = $version;
    }

    public function incrementSessionVersion(): void
    {
        $this->sessionVersion++;
    }

    public function recordLogin(): void
    {
        $this->lastLoginAt = new \DateTimeImmutable();
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param string[] $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function hasRole(string $roleSlug): bool
    {
        return in_array($roleSlug, $this->roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('super_admin') || $this->hasRole('admin');
    }

    /**
     * Primary role used for the session snapshot — highest-priority role wins.
     *
     * @param string[] $roles
     */
    public static function resolvePrimaryRole(array $roles): string
    {
        foreach (['super_admin', 'admin'] as $priority) {
            if (in_array($priority, $roles, true)) {
                return $priority;
            }
        }

        return $roles[0] ?? 'subscriber';
    }

    /**
     * Hydrate a User from a database row.
     *
     * @param array<string, mixed> $row
     * @param string[] $roles
     */
    public static function fromRow(array $row, array $roles = []): self
    {
        $user = new self(
            new Email($row['email']),
            $row['password'],
            $row['name'],
        );

        $user->id       = (int) $row['id'];
        $user->avatar   = $row['avatar'] ?? null;
        $user->locale   = $row['locale'] ?? 'en';
        $user->isActive = (bool) ($row['is_active'] ?? true);
        $user->roles    = $roles;

        if (!empty($row['email_verified_at'])) {
            $user->emailVerifiedAt = new \DateTimeImmutable($row['email_verified_at']);
        }
        if (!empty($row['verification_token'])) {
            $user->verificationToken = $row['verification_token'];
        }
        if (!empty($row['verification_token_expires_at'])) {
            $user->verificationTokenExpiresAt = new \DateTimeImmutable($row['verification_token_expires_at']);
        }
        if (!empty($row['last_login_at'])) {
            $user->lastLoginAt = new \DateTimeImmutable($row['last_login_at']);
        }
        if (!empty($row['sso_provider'])) {
            $user->ssoProvider = $row['sso_provider'];
        }
        if (!empty($row['sso_provider_id'])) {
            $user->ssoProviderId = $row['sso_provider_id'];
        }
        if (!empty($row['two_factor_secret'])) {
            $user->twoFactorSecret = $row['two_factor_secret'];
        }
        if (!empty($row['two_factor_recovery_codes'])) {
            $user->twoFactorRecoveryCodes = is_string($row['two_factor_recovery_codes']) ? (json_decode($row['two_factor_recovery_codes'], true) ?: []) : [];
        }
        if (isset($row['two_factor_enabled'])) {
            $user->twoFactorEnabled = (bool) $row['two_factor_enabled'];
        }
        if (isset($row['totp_last_slice']) && $row['totp_last_slice'] !== null) {
            $user->totpLastSlice = (int) $row['totp_last_slice'];
        }
        if (isset($row['session_version'])) {
            $user->sessionVersion = (int) $row['session_version'];
        }
        if (!empty($row['created_at'])) {
            $user->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $user->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }

        return $user;
    }
}
