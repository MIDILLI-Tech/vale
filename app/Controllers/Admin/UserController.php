<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Auth\RegisterUseCase;
use ValeApp\Application\Auth\TwoFactorService;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\User\Entity\User;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Auth\PasswordHasher;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Payment\PayPalGateway;
use ValeApp\Infrastructure\Payment\StripeGateway;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Admin panel: user management CRUD.
 */
final class UserController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly UserRepositoryInterface $users,
        private readonly RegisterUseCase $registerUseCase,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $session,
        private readonly Connection $db,
        private readonly DbalSettingsRepository $settings,
        private readonly TwoFactorService $twoFactorService,
        private readonly MailService $mail,
        private readonly EventDispatcherInterface $events,
        private readonly OutboundWebhookService $webhookService,
        private readonly AdminSearchCache $searchCache,
        private readonly ?StripeGateway $stripeGateway = null,
        private readonly ?PayPalGateway $paypalGateway = null,
    ) {
    }

    /**
     * GET /admin/users — List all users.
     */
    public function index(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $filters = [];
        if (!empty($params['search'])) {
            $filters['search'] = $params['search'];
        }

        $baseCount  = $this->users->count();
        $clientSide = $baseCount <= client_side_search_fetch_limit();

        if ($clientSide) {
            $pagination = new Pagination(1, 100000);
            $users      = $this->users->findAll($pagination, $filters);
            $total      = !empty($filters['search']) ? $this->users->count($filters) : $baseCount;
        } else {
            $page       = max(1, (int) ($params['page'] ?? 1));
            $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 20)));
            $pagination = new Pagination($page, $perPage, $params['sort'] ?? null, $params['dir'] ?? 'desc');

            $searchKey  = $this->userSearchKeyParts($filters, $pagination);
            $cached     = $searchKey !== null
                ? $this->searchCache->getObjects('user_search', $searchKey, [User::class])
                : null;
            $users      = $cached !== null ? $cached['items'] : null;

            if ($users === null) {
                $total = $this->users->count($filters);
                $users = $this->users->findAll($pagination, $filters);
                if ($searchKey !== null) {
                    $this->searchCache->setObjects('user_search', $searchKey, $total, $users, 120);
                }
            } else {
                $total = $cached['total'];
            }
        }

        $data = [
            'users'                    => $users,
            'roles'                    => $this->fetchAllRoles(),
            'total'                    => $total,
            'filters'                  => $params,
            'client_side'              => $clientSide,
            'page'                     => $clientSide ? 1 : $pagination->page,
            'per_page'                 => $clientSide ? 20 : $pagination->perPage,
            'base_url'                 => admin_url('/users'),
            'two_factor_global'        => $this->settings->get('two_factor_enabled_global', '0') === '1',
            'two_factor_forced_global' => $this->settings->get('two_factor_forced_global', '0')  === '1',
        ];

        if ($this->session->has('two_factor_new_codes')) {
            $data['two_factor_new_codes'] = $this->session->get('two_factor_new_codes');
            $this->session->remove('two_factor_new_codes');
        }

        return $this->twig->render($response, 'admin/users/index.twig', $data);
    }

    /**
     * GET /admin/users/create — Show create user form.
     */
    public function create(Request $request, Response $response): Response
    {
        $roles = $this->fetchAllRoles();

        return $this->twig->render($response, 'admin/users/form.twig', [
            'roles'                    => $roles,
            'user'                     => null,
            'is_edit'                  => false,
            'two_factor_global'        => $this->settings->get('two_factor_enabled_global', '0') === '1',
            'two_factor_forced_global' => $this->settings->get('two_factor_forced_global', '0')  === '1',
        ]);
    }

    /**
     * POST /admin/users — Store new user.
     */
    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        try {
            $newUser = $this->registerUseCase->execute([
                'name'                  => $body['name']                  ?? '',
                'email'                 => $body['email']                 ?? '',
                'password'              => $body['password']              ?? '',
                'password_confirmation' => $body['password_confirmation'] ?? '',
                'role'                  => $body['role']                  ?? 'subscriber',
            ]);

            $newUser->setEmailVerifiedAt(new \DateTimeImmutable());
            $newUser->setVerificationToken(null);

            if (!empty($body['enable_2fa']) && $this->settings->get('two_factor_enabled_global', '0') === '1') {
                $secret = $this->twoFactorService->generateSecret();
                $newUser->setTwoFactorSecret($secret);
            }

            $this->users->save($newUser);
            $this->searchCache->bump('user_search');

            // Send welcome email
            try {
                $this->mail->sendTemplate(
                    (string) $newUser->getEmail(),
                    $this->settings->get('site_name', 'Vale') . ' — ' . __('Welcome to :site', [':site' => $this->settings->get('site_name', 'Vale')]),
                    'mail/welcome',
                    [
                        'name'      => $newUser->getName(),
                        'site_name' => $this->settings->get('site_name', 'Vale'),
                        'login_url' => absolute_url('/login', [], ['lang' => $newUser->getLocale()]),
                    ],
                    locale: $newUser->getLocale(),
                    recipientType: 'customer',
                );
            } catch (\Throwable) {
                // silent
            }

            $this->events->doAction('user.registered', $newUser);
            $this->session->set('flash_success', $this->translator->get('User created successfully.'));

            return $response
                ->withHeader('Location', admin_url('/users'))
                ->withStatus(302);
        } catch (ValidationException $e) {
            return $this->twig->render($response->withStatus(422), 'admin/users/form.twig', [
                'errors'            => $e->getErrors(),
                'old'               => $body,
                'roles'             => $this->fetchAllRoles(),
                'user'              => null,
                'is_edit'           => false,
                'two_factor_global' => $this->settings->get('two_factor_enabled_global', '0') === '1',
            ]);
        }
    }

    /**
     * GET /admin/users/{id}/edit — Show edit form.
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $this->users->findById((int) $args['id']);

        if ($user === null) {
            return $response->withStatus(404);
        }

        $data = [
            'user'                     => $user,
            'roles'                    => $this->fetchAllRoles(),
            'is_edit'                  => true,
            'two_factor_global'        => $this->settings->get('two_factor_enabled_global', '0')  === '1',
            'two_factor_forced_global' => $this->settings->get('two_factor_forced_global', '0')   === '1',
        ];

        if ($this->session->has('two_factor_new_codes')) {
            $data['two_factor_new_codes'] = $this->session->get('two_factor_new_codes');
            $this->session->remove('two_factor_new_codes');
        }

        return $this->twig->render($response, 'admin/users/form.twig', $data);
    }

    /**
     * PUT /admin/users/{id} — Update user.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $this->users->findById((int) $args['id']);

        if ($user === null) {
            return $response->withStatus(404);
        }

        $currentRole  = $request->getAttribute('user_role');
        $isSuperAdmin = $currentRole === 'super_admin';

        // Only super_admin can modify another super_admin account
        if ($user->hasRole('super_admin') && !$isSuperAdmin) {
            $this->session->set(
                'flash_error',
                $this->translator->get('Only a Super Admin can modify another Super Admin account.'),
            );
            return $response
                ->withHeader('Location', admin_url('/users'))
                ->withStatus(302);
        }

        $body   = (array) $request->getParsedBody();
        $errors = [];

        $name = trim($body['name'] ?? '');
        if ($name === '') {
            $errors['name'] = [$this->translator->get('Name is required.')];
        }

        $email = trim($body['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = [$this->translator->get('A valid email is required.')];
        }

        if (!empty($errors)) {
            return $this->twig->render($response->withStatus(422), 'admin/users/form.twig', [
                'errors'            => $errors,
                'old'               => $body,
                'user'              => $user,
                'roles'             => $this->fetchAllRoles(),
                'is_edit'           => true,
                'two_factor_global' => $this->settings->get('two_factor_enabled_global', '0') === '1',
            ]);
        }

        if (!empty($body['disable_2fa'])) {
            $user->setTwoFactorEnabled(false);
            $user->setTwoFactorSecret(null);
            $user->setTwoFactorRecoveryCodes([]);
            $user->incrementSessionVersion();
            $this->users->save($user);
            $this->searchCache->bump('user_search');

            $msg = $this->settings->get('two_factor_forced_global', '0') === '1'
                ? 'Two-factor authentication reset for this user.'
                : 'Two-factor authentication disabled for this user.';

            $this->session->set('flash_success', $this->translator->get($msg));
            return $response
                ->withHeader('Location', admin_url('/users'))
                ->withStatus(302);
        }

        if (!empty($body['regenerate_2fa_codes'])) {
            $codes = [];
            for ($i = 0; $i < 8; $i++) {
                $codes[] = bin2hex(random_bytes(5));
            }
            $user->setTwoFactorRecoveryCodes($codes);
            $user->incrementSessionVersion();
            $this->users->save($user);
            $this->searchCache->bump('user_search');
            $this->session->set('flash_success', $this->translator->get('Recovery codes regenerated for this user.'));
            return $response
                ->withHeader('Location', admin_url('/users'))
                ->withStatus(302);
        }

        $user->setName($name);
        $user->setEmail(new Email($email));
        $wasActive = $user->isActive();
        $user->setIsActive(!empty($body['is_active']));
        $user->setLocale($body['locale'] ?? 'en');

        if (!empty($body['password'])) {
            $user->setPassword($this->hasher->hash($body['password']));
        }

        $previousRoles = $user->getRoles();
        $newRole       = $body['role'] ?? $previousRoles[0] ?? 'subscriber';

        // Only super_admin can assign the super_admin role
        if ($newRole === 'super_admin' && !$isSuperAdmin) {
            $newRole = $previousRoles[0] ?? 'subscriber';
        }

        $user->setRoles([$newRole]);

        // Invalidate this user's sessions when credentials, permissions or access changed
        if (
            !in_array($newRole, $previousRoles, true)
            || !empty($body['password'])
            || (!$user->isActive() && $wasActive)
        ) {
            $user->incrementSessionVersion();
        }

        if (!empty($body['enable_2fa']) && $this->settings->get('two_factor_enabled_global', '0') === '1' && !$user->isTwoFactorEnabled()) {
            $secret = $this->twoFactorService->generateSecret();
            $user->setTwoFactorSecret($secret);
            // We don't enable it here. The user will be forced to set it up on their next login.
        }

        if ($user->getEmailVerifiedAt() === null) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $user->setVerificationToken(null);
        }

        $this->users->save($user);
        $this->searchCache->bump('user_search');

        $this->session->set('flash_success', $this->translator->get('User updated successfully.'));

        return $response
            ->withHeader('Location', admin_url('/users'))
            ->withStatus(302);
    }

    /** POST /admin/bulk/users */
    public function bulkAction(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $rawIds = $body['ids'] ?? '[]';
        if (is_string($rawIds) && str_contains($rawIds, ',')) {
            $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        } else {
            $ids = array_filter(array_map('intval', is_array($rawIds) ? $rawIds : (array) json_decode((string) $rawIds, true)));
        }
        $action = $body['action'] ?? '';

        if ($ids === []) {
            $this->session->set('flash_error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/users'))->withStatus(302);
        }

        $currentUserId = (int) $this->session->get('user_id');
        $currentRole   = $this->session->get('user_role');
        $count         = 0;

        foreach ($ids as $id) {
            $id   = (int) $id;
            if ($id === $currentUserId) {
                continue;
            } // Prevent bulk action on self

            $user = $this->users->findById($id);
            if ($user === null) {
                continue;
            }

            // Only super_admin can modify another super_admin account
            if ($user->hasRole('super_admin') && $currentRole !== 'super_admin') {
                continue;
            }

            if ($action === 'delete') {
                $this->users->delete($id);
                $count++;
            } elseif ($action === 'anonymize') {
                $this->anonymizeUser($id);
                $count++;
            } elseif (in_array($action, ['activate', 'deactivate'], true)) {
                $user->setIsActive($action === 'activate');
                if ($action === 'deactivate') {
                    $user->incrementSessionVersion();
                }
                $this->users->save($user);
                $count++;
            }
        }
        $this->searchCache->bump('user_search');

        $this->session->set('flash_success', $this->translator->get(':count user(s) updated.', [':count' => (string) $count]));
        return $response->withHeader('Location', admin_url('/users'))->withStatus(302);
    }

    /**
     * DELETE /admin/users/{id} — Delete user.
     */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $targetId      = (int) $args['id'];
        $currentUserId = $request->getAttribute('user_id');
        $currentRole   = $request->getAttribute('user_role');

        $user = $this->users->findById($targetId);
        if ($user === null) {
            return $response->withStatus(404);
        }

        // Prevent self-deletion
        if ($targetId === $currentUserId) {
            $this->session->set('flash_error', $this->translator->get('You cannot delete your own account.'));
            return $response
                ->withHeader('Location', admin_url('/users'))
                ->withStatus(302);
        }

        // Only super_admin can delete another super_admin account
        if ($user->hasRole('super_admin') && $currentRole !== 'super_admin') {
            $this->session->set(
                'flash_error',
                $this->translator->get('Only a Super Admin can delete another Super Admin account.'),
            );
            return $response
                ->withHeader('Location', admin_url('/users'))
                ->withStatus(302);
        }

        // Anonymize comments before hard-deleting user
        $this->db->update(VL_DB_PREFIX . 'comments', [
            'author_name'  => 'Deleted User',
            'author_ip'    => null,
            'author_url'   => null,
            'user_id'      => null,
        ], ['user_id' => $targetId]);

        // Detach user from orders
        $this->db->update(VL_DB_PREFIX . 'orders', [
            'user_id' => null,
        ], ['user_id' => $targetId]);

        $this->users->delete($targetId);
        $this->searchCache->bump('user_search');
        $this->session->set('flash_success', $this->translator->get('User deleted. Comments anonymized, orders preserved.'));

        return $response
            ->withHeader('Location', admin_url('/users'))
            ->withStatus(302);
    }

    /**
     * POST /admin/users/{id}/anonymize — Anonymize user data (GDPR).
     */
    public function anonymize(Request $request, Response $response, array $args): Response
    {
        $targetId      = (int) $args['id'];
        $currentUserId = $request->getAttribute('user_id');
        $currentRole   = $request->getAttribute('user_role');

        $user = $this->users->findById($targetId);
        if ($user === null) {
            return $response->withStatus(404);
        }

        if ($targetId === $currentUserId) {
            $this->session->set('flash_error', $this->translator->get('You cannot anonymize your own account.'));
            return $response->withHeader('Location', admin_url('/users'))->withStatus(302);
        }

        if ($user->hasRole('super_admin') && $currentRole !== 'super_admin') {
            $this->session->set(
                'flash_error',
                $this->translator->get('Only a Super Admin can anonymize another Super Admin account.'),
            );
            return $response->withHeader('Location', admin_url('/users'))->withStatus(302);
        }

        $this->anonymizeUser($targetId);
        $this->searchCache->bump('user_search');

        $this->session->set('flash_success', $this->translator->get('User anonymized. Email preserved for record continuity.'));
        return $response->withHeader('Location', admin_url('/users'))->withStatus(302);
    }

    private function anonymizeUser(int $userId): void
    {
        // Anonymize user — keep email
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

        // Kill any active sessions for this user
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'users SET session_version = session_version + 1 WHERE id = ?',
            [$userId],
        );

        // Anonymize comments — keep email
        $this->db->update(VL_DB_PREFIX . 'comments', [
            'author_name'  => 'Deleted User',
            'author_ip'    => null,
            'author_url'   => null,
            'user_id'      => null,
        ], ['user_id' => $userId]);

        // Cancel active subscriptions at gateway
        $sub = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'user_subscriptions' . ' WHERE user_id = ? AND status IN (?, ?, ?) ORDER BY id DESC LIMIT 1',
            [$userId, 'active', 'trialing', 'cancelling'],
        );
        if ($sub !== false) {
            $gateway      = (string) ($sub['gateway'] ?? '');
            $gatewaySubId = (string) ($sub['gateway_subscription_id'] ?? '');
            if ($gatewaySubId !== '') {
                try {
                    if ($gateway === 'stripe' && $this->stripeGateway !== null) {
                        $this->stripeGateway->cancelSubscription($gatewaySubId);
                    } elseif ($gateway === 'paypal' && $this->paypalGateway !== null) {
                        $this->paypalGateway->cancelBillingAgreement($gatewaySubId);
                    }
                } catch (\Throwable $e) {
                    error_log('[User] Failed to cancel subscription at gateway: ' . $e->getMessage());
                }
            }
            $this->db->update(VL_DB_PREFIX . 'user_subscriptions', [
                'status'     => 'cancelled',
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], ['user_id' => $userId]);
        }

        // Unsubscribe from newsletter
        $this->db->update(VL_DB_PREFIX . 'newsletter_subscribers', [
            'status' => 'unsubscribed',
        ], ['user_id' => $userId]);

        // Detach user from orders
        $this->db->update(VL_DB_PREFIX . 'orders', [
            'user_id' => null,
        ], ['user_id' => $userId]);
    }

    /** @return array<array{id: int, slug: string, name: string}> */
    private function fetchAllRoles(): array
    {
        return $this->db->fetchAllAssociative('SELECT id, slug, name FROM ' . VL_DB_PREFIX . 'roles' . ' ORDER BY id');
    }

    /**
     * Build cache key parts when a server-side search/filter is active.
     * Returns null for unfiltered views (handled client-side).
     *
     * @return array<string, mixed>|null
     */
    private function userSearchKeyParts(array $filters, Pagination $pagination): ?array
    {
        $search = trim($filters['search'] ?? '');
        $role   = $filters['role'] ?? '';
        if ($search === '' && $role === '') {
            return null;
        }
        return [
            'v'        => 1,
            'search'   => $search,
            'role'     => $role,
            'sort'     => $pagination->sortBy  ?? '',
            'dir'      => $pagination->sortDir ?? 'desc',
            'page'     => $pagination->page,
            'perPage'  => $pagination->perPage,
        ];
    }
}
