<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;
use ValeApp\Infrastructure\Payment\Money;

class Coupon extends BaseEntity
{
    private string $code;
    private string $type                   = 'percent'; // percent|fixed_cart
    private float $amount                  = 0.0;
    private ?float $minOrder               = null;
    private ?int $maxUses                  = null;
    private int $maxUsesPerEmail           = 0;
    private int $usesCount                 = 0;
    private ?\DateTimeImmutable $startsAt  = null;
    private ?\DateTimeImmutable $expiresAt = null;
    private bool $enabled                  = true;
    private bool $freeShipping             = false;
    private bool $onlyUsers                = false;
    /** @var int[] */
    private array $allowedProductIds = [];
    /** @var int[] */
    private array $blockedProductIds = [];
    /** @var int[] Variation IDs (vl_product_variations.id); kept separate from product IDs to avoid collisions */
    private array $allowedVariationIds = [];
    /** @var int[] */
    private array $blockedVariationIds = [];
    /** @var int[] */
    private array $allowedCategoryIds = [];
    /** @var int[] */
    private array $blockedCategoryIds = [];
    /** @var string[] Lowercase emails; empty = no restriction */
    private array $allowedEmails           = [];
    private string $targetType             = 'product'; // product|membership
    /** @var int[] */
    private array $allowedMembershipIds    = [];
    /** @var int[] */
    private array $blockedMembershipIds    = [];
    private string $membershipDuration     = 'once'; // once|forever
    private string $applyScope             = self::APPLY_SCOPE_ALL; // all_eligible|cheapest|most_expensive

    public const string APPLY_SCOPE_ALL       = 'all_eligible';
    public const string APPLY_SCOPE_CHEAPEST  = 'cheapest';
    public const string APPLY_SCOPE_EXPENSIVE = 'most_expensive';
    public const array APPLY_SCOPES           = [
        self::APPLY_SCOPE_ALL,
        self::APPLY_SCOPE_CHEAPEST,
        self::APPLY_SCOPE_EXPENSIVE,
    ];

    public function __construct(string $code, string $type, float $amount)
    {
        $this->code   = strtoupper(trim($code));
        $this->type   = $type;
        $this->amount = $amount;
    }

    public function getCode(): string
    {
        return $this->code;
    }
    public function getType(): string
    {
        return $this->type;
    }
    public function getAmount(): float
    {
        return $this->amount;
    }
    public function getMinOrder(): ?float
    {
        return $this->minOrder;
    }
    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }
    public function getMaxUsesPerEmail(): int
    {
        return $this->maxUsesPerEmail;
    }
    public function getUsesCount(): int
    {
        return $this->usesCount;
    }
    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }
    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    /** @return int[] */ public function getAllowedProductIds(): array
    {
        return $this->allowedProductIds;
    }
    /** @return int[] */ public function getBlockedProductIds(): array
    {
        return $this->blockedProductIds;
    }
    /** @return int[] */ public function getAllowedVariationIds(): array
    {
        return $this->allowedVariationIds;
    }
    /** @return int[] */ public function getBlockedVariationIds(): array
    {
        return $this->blockedVariationIds;
    }
    /** @return int[] */ public function getAllowedCategoryIds(): array
    {
        return $this->allowedCategoryIds;
    }
    /** @return int[] */ public function getBlockedCategoryIds(): array
    {
        return $this->blockedCategoryIds;
    }
    /** @return string[] */ public function getAllowedEmails(): array
    {
        return $this->allowedEmails;
    }
    public function getTargetType(): string
    {
        return $this->targetType;
    }
    public function setTargetType(string $targetType): void
    {
        $this->targetType = $targetType;
    }
    /** @return int[] */ public function getAllowedMembershipIds(): array
    {
        return $this->allowedMembershipIds;
    }
    /** @param int[] $ids */ public function setAllowedMembershipIds(array $ids): void
    {
        $this->allowedMembershipIds = $ids;
    }
    /** @return int[] */ public function getBlockedMembershipIds(): array
    {
        return $this->blockedMembershipIds;
    }
    /** @param int[] $ids */ public function setBlockedMembershipIds(array $ids): void
    {
        $this->blockedMembershipIds = $ids;
    }
    public function getMembershipDuration(): string
    {
        return $this->membershipDuration;
    }
    public function setMembershipDuration(string $duration): void
    {
        $this->membershipDuration = $duration;
    }
    public function getApplyScope(): string
    {
        return $this->applyScope;
    }
    public function setApplyScope(string $scope): void
    {
        $this->applyScope = in_array($scope, self::APPLY_SCOPES, true) ? $scope : self::APPLY_SCOPE_ALL;
    }

    public function setCode(string $code): void
    {
        $this->code = strtoupper(trim($code));
    }
    public function setType(string $type): void
    {
        $this->type = $type;
    }
    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
    }
    public function setMinOrder(?float $min): void
    {
        $this->minOrder = $min;
    }
    public function setMaxUses(?int $max): void
    {
        $this->maxUses = $max;
    }
    public function setMaxUsesPerEmail(int $max): void
    {
        $this->maxUsesPerEmail = max(0, $max);
    }
    public function setUsesCount(int $count): void
    {
        $this->usesCount = $count;
    }
    public function setStartsAt(?\DateTimeImmutable $at): void
    {
        $this->startsAt = $at;
    }
    public function setExpiresAt(?\DateTimeImmutable $at): void
    {
        $this->expiresAt = $at;
    }
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
    /** @param int[] $ids */ public function setAllowedProductIds(array $ids): void
    {
        $this->allowedProductIds = $ids;
    }
    /** @param int[] $ids */ public function setBlockedProductIds(array $ids): void
    {
        $this->blockedProductIds = $ids;
    }
    /** @param int[] $ids */ public function setAllowedVariationIds(array $ids): void
    {
        $this->allowedVariationIds = $ids;
    }
    /** @param int[] $ids */ public function setBlockedVariationIds(array $ids): void
    {
        $this->blockedVariationIds = $ids;
    }
    /** @param int[] $ids */    public function setAllowedCategoryIds(array $ids): void
    {
        $this->allowedCategoryIds = $ids;
    }
    /** @param int[] $ids */    public function setBlockedCategoryIds(array $ids): void
    {
        $this->blockedCategoryIds = $ids;
    }
    /** @param string[] $emails */     public function hasFreeShipping(): bool
    {
        return $this->freeShipping;
    }
    public function setFreeShipping(bool $freeShipping): void
    {
        $this->freeShipping = $freeShipping;
    }
    public function isOnlyUsers(): bool
    {
        return $this->onlyUsers;
    }
    public function setOnlyUsers(bool $onlyUsers): void
    {
        $this->onlyUsers = $onlyUsers;
    }
    public function setAllowedEmails(array $emails): void
    {
        $this->allowedEmails = array_map('strtolower', $emails);
    }

    /**
     * Calculate discount amount for a given subtotal.
     */
    public function calculateDiscount(float $subtotal, float $fxRate = 1.0): float
    {
        if ($this->type === 'percent') {
            return Money::toFloat(Money::round2(Money::mul((string) $subtotal, Money::div((string) $this->amount, '100'))));
        }
        // Fixed amounts are stored in the store's base currency — convert to
        // the display/charge currency before clamping.
        $amount = Money::mul((string) $this->amount, (string) $fxRate);
        $capped = Money::comp($amount, (string) $subtotal) > 0 ? (string) $subtotal : $amount;

        return Money::toFloat(Money::round2($capped));
    }

    /**
     * Per-line eligibility against the product/variation/category restrictions.
     * Blocked entries always win; when both allowed products and allowed
     * categories are set, a line must satisfy both.
     * Parent restrictions cascade to variations: an allowed parent covers all
     * its variations, a blocked parent blocks all its variations.
     *
     * @param int[] $categoryIds
     */
    public function isEligibleLine(int $productId, array $categoryIds, int $variationId = 0): bool
    {
        if (!empty($this->blockedProductIds) && in_array($productId, $this->blockedProductIds, true)) {
            return false;
        }
        if ($variationId > 0 && !empty($this->blockedVariationIds) && in_array($variationId, $this->blockedVariationIds, true)) {
            return false;
        }
        if (!empty($this->blockedCategoryIds) && !empty(array_intersect($categoryIds, $this->blockedCategoryIds))) {
            return false;
        }
        $hasProductScope   = !empty($this->allowedProductIds) || !empty($this->allowedVariationIds);
        $productScopeHit   = in_array($productId, $this->allowedProductIds, true)
            || ($variationId > 0 && in_array($variationId, $this->allowedVariationIds, true));
        if ($hasProductScope && !$productScopeHit) {
            return false;
        }
        if (!empty($this->allowedCategoryIds) && empty(array_intersect($categoryIds, $this->allowedCategoryIds))) {
            return false;
        }
        return true;
    }

    /**
     * Filter cart lines down to the coupon-eligible subset, preserving keys.
     *
     * @param array<int, array{price: float, qty: int, product_id: int, category_ids: int[], variation_id?: int}> $lines
     * @return array<int, array{price: float, qty: int, product_id: int, category_ids: int[], variation_id?: int}>
     */
    public function eligibleLines(array $lines): array
    {
        $eligible = [];
        foreach ($lines as $index => $line) {
            $price = (float) ($line['price'] ?? 0.0);
            $qty   = (int) ($line['qty'] ?? 0);
            if ($price <= 0.0 || $qty <= 0) {
                continue;
            }
            if ($this->isEligibleLine((int) ($line['product_id'] ?? 0), (array) ($line['category_ids'] ?? []), (int) ($line['variation_id'] ?? 0))) {
                $eligible[$index] = $line;
            }
        }
        return $eligible;
    }

    /**
     * Discount base for the current scope: eligible-lines total for
     * all_eligible, otherwise the single cheapest/most-expensive
     * eligible unit price.
     *
     * @param array<int, array{price: float, qty: int, product_id: int, category_ids: int[], variation_id?: int}> $lines
     * @return array{base: float, target: int|null}
     */
    public function discountBase(array $lines): array
    {
        $eligible = $this->eligibleLines($lines);
        if ($eligible === []) {
            return ['base' => 0.0, 'target' => null];
        }
        if ($this->applyScope === self::APPLY_SCOPE_CHEAPEST || $this->applyScope === self::APPLY_SCOPE_EXPENSIVE) {
            $target     = null;
            $targetUnit = null;
            foreach ($eligible as $index => $line) {
                $unit = (float) ($line['price'] ?? 0.0);
                if ($target === null
                    || ($this->applyScope === self::APPLY_SCOPE_CHEAPEST && $unit < $targetUnit)
                    || ($this->applyScope === self::APPLY_SCOPE_EXPENSIVE && $unit > $targetUnit)
                ) {
                    $target     = $index;
                    $targetUnit = $unit;
                }
            }
            return ['base' => (float) $targetUnit, 'target' => $target];
        }
        $base = 0.0;
        foreach ($eligible as $line) {
            $base += (float) ($line['price'] ?? 0.0) * (int) ($line['qty'] ?? 0);
        }
        return ['base' => $base, 'target' => null];
    }

    /**
     * Scope-aware discount for resolved cart lines.
     *
     * @param array<int, array{price: float, qty: int, product_id: int, category_ids: int[], variation_id?: int}> $lines
     */
    public function calculateDiscountForLines(array $lines, float $fxRate = 1.0): float
    {
        ['base' => $base] = $this->discountBase($lines);
        if ($base <= 0.0) {
            return 0.0;
        }
        return $this->calculateDiscount($base, $fxRate);
    }

    /**
     * Split the scope-aware discount across cart indexes for tax allocation.
     * Single-unit scopes assign the whole discount to the target line;
     * all_eligible splits proportionally across eligible lines.
     *
     * @param array<int, array{price: float, qty: int, product_id: int, category_ids: int[], variation_id?: int}> $lines
     * @return array<int, float> discount money per cart index
     */
    public function allocateDiscount(array $lines, float $fxRate = 1.0): array
    {
        $eligible = $this->eligibleLines($lines);
        if ($eligible === []) {
            return [];
        }
        $total = $this->calculateDiscountForLines($lines, $fxRate);
        if ($total <= 0.0) {
            return [];
        }
        ['target' => $target] = $this->discountBase($lines);
        if ($target !== null) {
            return [$target => $total];
        }
        $base       = 0.0;
        $lineTotals = [];
        foreach ($eligible as $index => $line) {
            $lineTotal          = (float) ($line['price'] ?? 0.0) * (int) ($line['qty'] ?? 0);
            $lineTotals[$index] = $lineTotal;
            $base              += $lineTotal;
        }
        if ($base <= 0.0) {
            return [];
        }
        $allocation = [];
        $assigned   = 0.0;
        $lastIndex  = array_key_last($lineTotals);
        foreach ($lineTotals as $index => $lineTotal) {
            if ($index === $lastIndex) {
                $allocation[$index] = Money::toFloat(Money::round2((string) ($total - $assigned)));
                continue;
            }
            $share              = $total * ($lineTotal / $base);
            $share              = Money::toFloat(Money::round2((string) $share));
            $allocation[$index] = $share;
            $assigned          += $share;
        }
        return $allocation;
    }

    /**
     * Check if this coupon is valid (enabled, not expired, usage not maxed).
     */
    public function isValid(?float $orderSubtotal = null): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->startsAt !== null && $this->startsAt > new \DateTimeImmutable()) {
            return false;
        }
        if ($this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable()) {
            return false;
        }
        if ($this->maxUses !== null && $this->usesCount >= $this->maxUses) {
            return false;
        }
        if ($this->minOrder !== null && $orderSubtotal !== null && $orderSubtotal < $this->minOrder) {
            return false;
        }
        return true;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $coupon = new self(
            $row['code'],
            $row['type'] ?? 'percent',
            (float) ($row['amount'] ?? 0),
        );

        $coupon->id                   = (int) $row['id'];
        $coupon->minOrder             = isset($row['min_order']) && $row['min_order']  !== null ? (float) $row['min_order'] : null;
        $coupon->maxUses              = isset($row['max_uses'])  && $row['max_uses']   !== null ? (int) $row['max_uses'] : null;
        $coupon->maxUsesPerEmail      = isset($row['max_uses_per_email']) ? (int) $row['max_uses_per_email'] : 0;
        $coupon->usesCount            = (int) ($row['uses_count'] ?? 0);
        $coupon->enabled              = (bool) ($row['enabled'] ?? true);
        $coupon->freeShipping         = !empty($row['free_shipping']);
        $coupon->onlyUsers            = !empty($row['only_users']);
        $coupon->allowedProductIds    = self::parseIds($row['allowed_product_ids'] ?? '');
        $coupon->blockedProductIds    = self::parseIds($row['blocked_product_ids'] ?? '');
        $coupon->allowedVariationIds  = self::parseIds($row['allowed_variation_ids'] ?? '');
        $coupon->blockedVariationIds  = self::parseIds($row['blocked_variation_ids'] ?? '');
        $coupon->allowedCategoryIds   = self::parseIds($row['allowed_category_ids'] ?? '');
        $coupon->blockedCategoryIds   = self::parseIds($row['blocked_category_ids'] ?? '');
        $coupon->allowedEmails        = self::parseEmails($row['allowed_emails'] ?? '');
        $coupon->targetType           = $row['target_type'] ?? 'product';
        $coupon->allowedMembershipIds = self::parseIds($row['allowed_membership_ids'] ?? '');
        $coupon->blockedMembershipIds = self::parseIds($row['blocked_membership_ids'] ?? '');
        $coupon->membershipDuration   = $row['membership_duration'] ?? 'once';
        $coupon->setApplyScope((string) ($row['apply_scope'] ?? self::APPLY_SCOPE_ALL));

        if (!empty($row['starts_at'])) {
            $coupon->startsAt = new \DateTimeImmutable($row['starts_at']);
        }
        if (!empty($row['expires_at'])) {
            $coupon->expiresAt = new \DateTimeImmutable($row['expires_at']);
        }
        if (!empty($row['created_at'])) {
            $coupon->createdAt = new \DateTimeImmutable($row['created_at']);
        }

        return $coupon;
    }

    /** @return int[] */
    private static function parseIds(?string $raw): array
    {
        if (!$raw) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /** @return string[] */
    private static function parseEmails(?string $raw): array
    {
        if (!$raw) {
            return [];
        }
        return array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', $raw))));
    }
}
