<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shop\Repository;

interface DownloadRepositoryInterface
{
    /**
     * Create or update a download grant for an order item.
     *
     * @param array{
     *   order_id: int,
     *   order_item_index: int,
     *   product_id: int,
     *   variation_id: int|null,
     *   product_name: string,
     *   token: string,
     *   source_type: string,
     *   source_file_id: int|null,
     *   source_url: string|null,
     *   download_limit: int,
     *   expires_at: string|null,
     * } $data
     */
    public function createGrant(array $data): int;

    /**
     * Find a grant by its token. Returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findGrantByToken(string $token): ?array;

    /**
     * Increment the download_count for a grant by 1.
     */
    public function incrementDownloadCount(int $grantId): void;

    /**
     * Revoke (or un-revoke) a grant.
     */
    public function setRevoked(int $grantId, bool $revoked): void;

    /**
     * Revoke (or un-revoke) all grants that belong to an order.
     */
    public function setRevokedByOrderId(int $orderId, bool $revoked): void;

    /**
     * Regenerate a grant's token and reset count. Returns the new token.
     */
    public function regenerateToken(int $grantId): string;

    /**
     * Update download count, limit and expiration for a grant.
     *
     * @param string|null $expiresAt Y-m-d format or null to clear.
     */
    public function updateDownloadMeta(int $grantId, int $downloadCount, int $downloadLimit, ?string $expiresAt = null): void;

    /**
     * All grants for a given order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findGrantsByOrderId(int $orderId): array;
}
