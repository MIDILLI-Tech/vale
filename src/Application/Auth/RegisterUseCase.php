<?php

declare(strict_types=1);

namespace ValeApp\Application\Auth;

use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Auth\PasswordHasher;

/**
 * Use case: Register a new user account.
 */
final class RegisterUseCase
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly PasswordHasher $hasher,
        private readonly int $minPasswordLength = 8,
    ) {
    }

    /**
     * @param array{name: string, email: string, password: string, password_confirmation: string, role?: string} $data
     */
    public function execute(array $data): User
    {
        $errors = [];

        if (empty(trim($data['name'] ?? ''))) {
            $errors['name'] = 'Name is required.';
        }

        $emailStr = trim($data['email'] ?? '');
        if ($emailStr === '' || !filter_var($emailStr, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'A valid email is required.';
        }

        $password = $data['password'] ?? '';
        if (strlen($password) < $this->minPasswordLength) {
            $errors['password'] = "Password must be at least {$this->minPasswordLength} characters.";
        } elseif (!preg_match('/^(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*\W).{8,}$/', $password)) {
            $errors['password'] = __('Password must include at least one uppercase letter, one lowercase letter, one number, and one special character.');
        }

        if ($password !== ($data['password_confirmation'] ?? '')) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $email = new Email($emailStr);

        // Check uniqueness
        if ($this->users->findByEmail($email) !== null) {
            throw ValidationException::withMessages(['email' => 'This email is already registered.']);
        }

        $user = new User($email, $this->hasher->hash($password), trim($data['name']));
        $user->setRoles([$data['role'] ?? 'subscriber']);

        if (!empty($data['verify_email'])) {
            // Generate email verification token (24h expiry)
            $token = bin2hex(random_bytes(32));
            $user->setVerificationToken($token);
            $user->setVerificationTokenExpiresAt(new \DateTimeImmutable('+24 hours'));
            $user->setIsActive(false);
        } else {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }

        $this->users->save($user);

        // Create linked newsletter subscriber
        $existingSub = $this->subscribers->findByEmail($emailStr);
        if ($existingSub !== null) {
            $existingSub->setUserId($user->getId());
            $existingSub->setTier('free');
            $this->subscribers->save($existingSub);
        } else {
            $sub = new Subscriber($emailStr, bin2hex(random_bytes(16)));
            $sub->setName(trim($data['name']));
            $sub->setUserId($user->getId());
            $sub->setTier('free');
            $sub->setSubscribedAt(new \DateTimeImmutable());
            $this->subscribers->save($sub);
        }

        return $user;
    }
}
