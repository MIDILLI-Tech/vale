<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;

final class DbalCouponRepository implements CouponRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findById(int $id): ?Coupon
    {
        $row = $this->db->fetchAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'coupons' . ' WHERE id = ?', [$id], [ParameterType::INTEGER]);
        return $row === false ? null : Coupon::fromRow($row);
    }

    public function findByCode(string $code): ?Coupon
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'coupons' . ' WHERE code = ?',
            [strtoupper(trim($code))],
            [ParameterType::STRING],
        );
        return $row === false ? null : Coupon::fromRow($row);
    }

    /** @return Coupon[] */
    public function findAll(): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM ' . VL_DB_PREFIX . 'coupons' . ' ORDER BY created_at DESC');
        return array_map(fn ($row) => Coupon::fromRow($row), $rows);
    }

    public function save(Coupon $coupon): Coupon
    {
        $data = [
            'code'                   => $coupon->getCode(),
            'type'                   => $coupon->getType(),
            'amount'                 => $coupon->getAmount(),
            'min_order'              => $coupon->getMinOrder(),
            'max_uses'               => $coupon->getMaxUses(),
            'max_uses_per_email'     => $coupon->getMaxUsesPerEmail(),
            'uses_count'             => $coupon->getUsesCount(),
            'expires_at'             => $coupon->getExpiresAt()?->format('Y-m-d H:i:s'),
            'starts_at'              => $coupon->getStartsAt()?->format('Y-m-d H:i:s'),
            'enabled'                => $coupon->isEnabled() ? 1 : 0,
            'free_shipping'          => $coupon->hasFreeShipping() ? 1 : 0,
            'only_users'             => $coupon->isOnlyUsers() ? 1 : 0,
            'allowed_product_ids'    => $coupon->getAllowedProductIds() ? implode(',', $coupon->getAllowedProductIds()) : null,
            'blocked_product_ids'    => $coupon->getBlockedProductIds() ? implode(',', $coupon->getBlockedProductIds()) : null,
            'allowed_variation_ids'  => $coupon->getAllowedVariationIds() ? implode(',', $coupon->getAllowedVariationIds()) : null,
            'blocked_variation_ids'  => $coupon->getBlockedVariationIds() ? implode(',', $coupon->getBlockedVariationIds()) : null,
            'allowed_category_ids'   => $coupon->getAllowedCategoryIds() ? implode(',', $coupon->getAllowedCategoryIds()) : null,
            'blocked_category_ids'   => $coupon->getBlockedCategoryIds() ? implode(',', $coupon->getBlockedCategoryIds()) : null,
            'allowed_emails'         => $coupon->getAllowedEmails() ? implode(',', $coupon->getAllowedEmails()) : null,
            'target_type'            => $coupon->getTargetType(),
            'allowed_membership_ids' => $coupon->getAllowedMembershipIds() ? implode(',', $coupon->getAllowedMembershipIds()) : null,
            'blocked_membership_ids' => $coupon->getBlockedMembershipIds() ? implode(',', $coupon->getBlockedMembershipIds()) : null,
            'membership_duration'    => $coupon->getMembershipDuration(),
            'apply_scope'            => $coupon->getApplyScope(),
        ];

        if ($coupon->getId() === null) {
            $data['created_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->db->insert(VL_DB_PREFIX . 'coupons', $data);
            $coupon->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'coupons', $data, ['id' => $coupon->getId()]);
        }

        return $coupon;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'coupons', ['id' => $id]);
    }

    public function incrementUses(int $id): void
    {
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'coupons' . ' SET uses_count = uses_count + 1 WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );
    }

    public function incrementUsesIfAvailable(int $id): bool
    {
        $affected = $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'coupons'
            . ' SET uses_count = uses_count + 1'
            . ' WHERE id = ? AND (max_uses IS NULL OR uses_count < max_uses)',
            [$id],
            [ParameterType::INTEGER],
        );
        return $affected > 0;
    }

    public function decrementUses(int $id): void
    {
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'coupons'
            . ' SET uses_count = CASE WHEN uses_count > 0 THEN uses_count - 1 ELSE 0 END'
            . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER],
        );
    }

    public function countUsesByEmail(string $code, string $email): int
    {
        $targetEmail = strtolower(trim($email));
        $couponCode  = strtoupper(trim($code));
        if ($targetEmail === '' || $couponCode === '') {
            return 0;
        }

        $rows   = $this->db->fetchAllAssociative(
            'SELECT user_id, billing FROM ' . VL_DB_PREFIX . 'orders WHERE coupon_code = ?',
            [$couponCode],
        );
        if ($rows === []) {
            return 0;
        }

        $userIds = [];
        foreach ($rows as $row) {
            if (!empty($row['user_id'])) {
                $userIds[(int) $row['user_id']] = true;
            }
        }

        $userEmails = [];
        if ($userIds !== []) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $userRows     = $this->db->fetchAllAssociative(
                'SELECT id, email FROM ' . VL_DB_PREFIX . 'users'
                . ' WHERE id IN (' . $placeholders . ')',
                array_map('intval', array_keys($userIds)),
            );
            foreach ($userRows as $userRow) {
                $userEmails[(int) $userRow['id']] = strtolower(trim((string) $userRow['email']));
            }
        }

        $count = 0;
        foreach ($rows as $row) {
            $billingEmail = '';
            if (!empty($row['billing'])) {
                $rawBilling   = is_array($row['billing'])
                    ? $row['billing']
                    : json_decode((string) $row['billing'], true);
                $billingEmail = strtolower(trim((string) ($rawBilling['email'] ?? '')));
            }
            if ($billingEmail === $targetEmail) {
                $count++;
                continue;
            }
            $userEmail = $userEmails[(int) $row['user_id']] ?? null;
            if ($userEmail !== null && $userEmail === $targetEmail) {
                $count++;
            }
        }
        return $count;
    }
}
