<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class CouponController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly CouponRepositoryInterface $coupons,
        private readonly SessionManager $session,
        private readonly ProductCategoryRepositoryInterface $categories,
        private readonly PlanRepositoryInterface $plans,
    ) {
    }

    /** @return int[] */
    private static function parseIds(string $raw): array
    {
        return array_values(array_filter(array_map('intval', array_map('trim', explode(',', $raw)))));
    }

    /** @return string[] */
    private static function parseEmails(string $raw): array
    {
        return array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', $raw))));
    }

    /**
     * Join the optional prefix and code fields as-is (no separator added —
     * type it into the prefix yourself if you want one, e.g. "ABC-").
     * Already-prefixed codes are left untouched (no doubling).
     */
    private static function combinePrefix(string $prefix, string $code): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Z0-9\-_]/i', '', trim($prefix)));
        $code   = strtoupper(trim($code));
        if ($prefix === '' || $code === '' || str_starts_with($code, $prefix)) {
            return $code;
        }
        return $prefix . $code;
    }

    public static function validateAmount(string $type, float $amount): ?string
    {
        if ($amount < 0) {
            return 'Coupon amount cannot be negative.';
        }
        if ($type === 'percent' && $amount > 100) {
            return 'Percentage discount cannot exceed 100.';
        }
        return null;
    }

    public function index(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $coupons = $this->coupons->findAll();
        return $this->twig->render($response, 'admin/coupons/index.twig', [
            'coupons'          => $coupons,
            'filters'          => $params,
            'categories'       => $this->categories->findAll(),
            'membership_plans' => $this->plans->findAll(),
            'coupons_json'     => array_values(array_map(fn (Coupon $c) => [
                'id'                     => $c->getId(),
                'code'                   => $c->getCode(),
                'type'                   => $c->getType(),
                'amount'                 => $c->getAmount(),
                'min_order'              => $c->getMinOrder(),
                'max_uses'               => $c->getMaxUses(),
                'max_uses_per_email'     => $c->getMaxUsesPerEmail(),
                'starts_at'              => $c->getStartsAt()?->format('Y-m-d\TH:i'),
                'expires_at'             => $c->getExpiresAt()?->format('Y-m-d\TH:i'),
                'enabled'                => $c->isEnabled(),
                'only_users'             => $c->isOnlyUsers(),
                'free_shipping'          => $c->hasFreeShipping(),
                'allowed_product_ids'    => $c->getAllowedProductIds(),
                'blocked_product_ids'    => $c->getBlockedProductIds(),
                'allowed_variation_ids'  => $c->getAllowedVariationIds(),
                'blocked_variation_ids'  => $c->getBlockedVariationIds(),
                'allowed_category_ids'   => $c->getAllowedCategoryIds(),
                'blocked_category_ids'   => $c->getBlockedCategoryIds(),
                'allowed_emails'         => $c->getAllowedEmails(),
                'uses_count'             => $c->getUsesCount(),
                'target_type'            => $c->getTargetType(),
                'apply_scope'            => $c->getApplyScope(),
                'allowed_membership_ids' => $c->getAllowedMembershipIds(),
                'blocked_membership_ids' => $c->getBlockedMembershipIds(),
                'membership_duration'    => $c->getMembershipDuration(),
            ], $coupons)),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/coupons/form.twig', [
            'coupon'     => null,
            'is_edit'    => false,
            'categories' => $this->categories->findAll(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $code = self::combinePrefix((string) ($body['prefix'] ?? ''), (string) ($body['code'] ?? ''));

        if ($code === '') {
            $this->session->flash('error', $this->translator->get('Coupon code is required.'));
            return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
        }

        // Prevent duplicate codes
        if ($this->coupons->findByCode($code) !== null) {
            $this->session->flash('error', $this->translator->get('A coupon with this code already exists.'));
            return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
        }

        $couponType   = in_array($body['type'] ?? '', ['percent', 'fixed_cart'], true) ? $body['type'] : 'percent';
        $couponAmount = (float) str_replace(',', '.', $body['amount'] ?? '0');

        $amountError = self::validateAmount($couponType, $couponAmount);
        if ($amountError !== null) {
            $this->session->flash('error', $this->translator->get($amountError));
            return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
        }

        $coupon = new Coupon(
            $code,
            $couponType,
            $couponAmount,
        );
        $coupon->setMinOrder(!empty($body['min_order']) ? (float) str_replace(',', '.', $body['min_order']) : null);
        $coupon->setMaxUses(!empty($body['max_uses']) ? (int) $body['max_uses'] : null);
        $coupon->setMaxUsesPerEmail(!empty($body['max_uses_per_email']) ? (int) $body['max_uses_per_email'] : 0);
        if (!empty($body['starts_at'])) {
            try {
                $coupon->setStartsAt(new \DateTimeImmutable($body['starts_at']));
            } catch (\Throwable) {
            }
        }
        $coupon->setEnabled(!empty($body['enabled']));
        $coupon->setFreeShipping(!empty($body['free_shipping']));
        $coupon->setOnlyUsers(!empty($body['only_users']));
        $coupon->setAllowedProductIds(self::parseIds($body['allowed_product_ids'] ?? ''));
        $coupon->setBlockedProductIds(self::parseIds($body['blocked_product_ids'] ?? ''));
        $coupon->setAllowedVariationIds(self::parseIds($body['allowed_variation_ids'] ?? ''));
        $coupon->setBlockedVariationIds(self::parseIds($body['blocked_variation_ids'] ?? ''));
        $coupon->setAllowedCategoryIds(self::parseIds(implode(',', (array) ($body['allowed_category_ids'] ?? []))));
        $coupon->setBlockedCategoryIds(self::parseIds(implode(',', (array) ($body['blocked_category_ids'] ?? []))));
        $coupon->setAllowedEmails(self::parseEmails($body['allowed_emails'] ?? ''));
        $coupon->setTargetType($body['target_type'] ?? 'product');
        $coupon->setApplyScope((string) ($body['apply_scope'] ?? Coupon::APPLY_SCOPE_ALL));
        $coupon->setAllowedMembershipIds(self::parseIds($body['allowed_membership_ids'] ?? ''));
        $coupon->setBlockedMembershipIds(self::parseIds($body['blocked_membership_ids'] ?? ''));
        $coupon->setMembershipDuration($body['membership_duration'] ?? 'once');
        if (!empty($body['expires_at'])) {
            try {
                $coupon->setExpiresAt(new \DateTimeImmutable($body['expires_at']));
            } catch (\Throwable) {
            }
        }

        $this->coupons->save($coupon);
        $this->session->flash('success', $this->translator->get('Coupon created.'));
        return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $coupon = $this->coupons->findById((int) $args['id']);
        if ($coupon === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/coupons/form.twig', [
            'coupon'     => $coupon,
            'is_edit'    => true,
            'categories' => $this->categories->findAll(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $coupon = $this->coupons->findById((int) $args['id']);
        if ($coupon === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $code = self::combinePrefix((string) ($body['prefix'] ?? ''), (string) ($body['code'] ?? ''));

        if ($code !== '') {
            $existing = $this->coupons->findByCode($code);
            if ($existing !== null && $existing->getId() !== $coupon->getId()) {
                $this->session->flash('error', $this->translator->get('A coupon with this code already exists.'));
                return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
            }
            $coupon->setCode($code);
        }

        if (in_array($body['type'] ?? '', ['percent', 'fixed_cart'], true)) {
            $coupon->setType($body['type']);
        }
        $couponType   = $coupon->getType();
        $couponAmount = (float) str_replace(',', '.', (string) ($body['amount'] ?? $coupon->getAmount()));

        $amountError = self::validateAmount($couponType, $couponAmount);
        if ($amountError !== null) {
            $this->session->flash('error', $this->translator->get($amountError));
            return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
        }

        $coupon->setAmount($couponAmount);
        $minOrder = !empty($body['min_order']) ? (float) str_replace(',', '.', (string) $body['min_order']) : null;
        $coupon->setMinOrder($minOrder);
        $coupon->setMaxUses(!empty($body['max_uses']) ? (int) $body['max_uses'] : null);
        $coupon->setMaxUsesPerEmail(!empty($body['max_uses_per_email']) ? (int) $body['max_uses_per_email'] : 0);
        if (!empty($body['starts_at'])) {
            try {
                $coupon->setStartsAt(new \DateTimeImmutable($body['starts_at']));
            } catch (\Throwable) {
            }
        } else {
            $coupon->setStartsAt(null);
        }
        $coupon->setEnabled(!empty($body['enabled']));
        $coupon->setFreeShipping(!empty($body['free_shipping']));
        $coupon->setOnlyUsers(!empty($body['only_users']));
        $coupon->setAllowedProductIds(self::parseIds($body['allowed_product_ids'] ?? ''));
        $coupon->setBlockedProductIds(self::parseIds($body['blocked_product_ids'] ?? ''));
        $coupon->setAllowedVariationIds(self::parseIds($body['allowed_variation_ids'] ?? ''));
        $coupon->setBlockedVariationIds(self::parseIds($body['blocked_variation_ids'] ?? ''));
        $coupon->setAllowedCategoryIds(self::parseIds(implode(',', (array) ($body['allowed_category_ids'] ?? []))));
        $coupon->setBlockedCategoryIds(self::parseIds(implode(',', (array) ($body['blocked_category_ids'] ?? []))));
        $coupon->setAllowedEmails(self::parseEmails($body['allowed_emails'] ?? ''));
        $coupon->setTargetType($body['target_type'] ?? 'product');
        $coupon->setApplyScope((string) ($body['apply_scope'] ?? $coupon->getApplyScope()));
        $coupon->setAllowedMembershipIds(self::parseIds($body['allowed_membership_ids'] ?? ''));
        $coupon->setBlockedMembershipIds(self::parseIds($body['blocked_membership_ids'] ?? ''));
        $coupon->setMembershipDuration($body['membership_duration'] ?? 'once');
        if (!empty($body['expires_at'])) {
            try {
                $coupon->setExpiresAt(new \DateTimeImmutable($body['expires_at']));
            } catch (\Throwable) {
            }
        } else {
            $coupon->setExpiresAt(null);
        }

        $this->coupons->save($coupon);
        $this->session->flash('success', $this->translator->get('Coupon updated.'));
        return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->coupons->delete((int) $args['id']);
        $this->session->flash('success', $this->translator->get('Coupon deleted.'));
        return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
    }

    /** @return int[] */
    private static function parseBulkIds(mixed $raw): array
    {
        if (is_string($raw) && str_contains($raw, ',')) {
            $ids = array_filter(array_map('intval', explode(',', $raw)));
        } else {
            $ids = array_filter(array_map('intval', is_array($raw) ? $raw : (array) json_decode((string) $raw, true)));
        }
        return array_values($ids);
    }

    public function bulkAction(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $ids    = self::parseBulkIds($body['ids'] ?? '[]');
        $action = $body['action'] ?? '';

        if ($ids === []) {
            $this->session->flash('error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
        }

        $count = 0;
        foreach ($ids as $id) {
            $coupon = $this->coupons->findById((int) $id);
            if ($coupon === null) {
                continue;
            }

            if ($action === 'enable') {
                $coupon->setEnabled(true);
                $this->coupons->save($coupon);
                $count++;
            } elseif ($action === 'disable') {
                $coupon->setEnabled(false);
                $this->coupons->save($coupon);
                $count++;
            }
        }

        $message = $this->translator->get(':count coupon(s) updated.', [':count' => (string) $count]);
        $this->session->flash('success', $message);
        return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
    }

    public function bulkDelete(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $ids  = self::parseBulkIds($body['ids'] ?? '[]');

        if ($ids === []) {
            $this->session->flash('error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
        }

        $count = 0;
        foreach ($ids as $id) {
            if ($this->coupons->findById((int) $id) === null) {
                continue;
            }
            $this->coupons->delete((int) $id);
            $count++;
        }

        $message = $this->translator->get(':count coupon(s) deleted.', [':count' => (string) $count]);
        $this->session->flash('success', $message);
        return $response->withHeader('Location', admin_url('/coupons'))->withStatus(302);
    }

    /** JSON list for the editor coupon picker — returns all coupons (id, code, type, amount, enabled). */
    public function listJson(Request $request, Response $response): Response
    {
        $coupons = $this->coupons->findAll();
        $data    = array_values(array_map(fn (Coupon $c) => [
            'id'                 => $c->getId(),
            'code'               => $c->getCode(),
            'type'               => $c->getType(),
            'amount'             => $c->getAmount(),
            'enabled'            => $c->isEnabled(),
            'only_users'         => $c->isOnlyUsers(),
            'max_uses_per_email' => $c->getMaxUsesPerEmail(),
            'starts_at'          => $c->getStartsAt()?->format('Y-m-d\TH:i'),
            'expires_at'         => $c->getExpiresAt()?->format('Y-m-d\TH:i'),
        ], $coupons));

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
