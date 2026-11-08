<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;

final class DbalDownloadRepository implements DownloadRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function createGrant(array $data): int
    {
        $this->db->insert(VL_DB_PREFIX . 'order_downloads', [
            'order_id'           => $data['order_id'],
            'order_item_index'   => $data['order_item_index'],
            'product_id'         => $data['product_id'],
            'variation_id'       => $data['variation_id'] ?? null,
            'product_name'       => $data['product_name'],
            'token'              => self::hashToken((string) $data['token']),
            'source_type'        => $data['source_type'],
            'source_file_id'     => $data['source_file_id'] ?? null,
            'source_url'         => $data['source_url']     ?? null,
            'download_url_proxy' => !empty($data['download_url_proxy']) ? 1 : 0,
            'download_limit'     => $data['download_limit'],
            'download_count'     => 0,
            'expires_at'         => $data['expires_at'] ?? null,
            'revoked'            => 0,
            'created_at'         => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findGrantByToken(string $token): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'order_downloads' . ' WHERE token = ?',
            [$token],
            [ParameterType::STRING],
        );
        return $row !== false ? $row : null;
    }

    public function incrementDownloadCount(int $grantId): void
    {
        $this->db->executeStatement(
            'UPDATE ' . VL_DB_PREFIX . 'order_downloads' . ' SET download_count = download_count + 1 WHERE id = ?',
            [$grantId],
            [ParameterType::INTEGER],
        );
    }

    public function setRevoked(int $grantId, bool $revoked): void
    {
        $this->db->update(VL_DB_PREFIX . 'order_downloads', ['revoked' => $revoked ? 1 : 0], ['id' => $grantId]);
    }

    public function setRevokedByOrderId(int $orderId, bool $revoked): void
    {
        $this->db->update(VL_DB_PREFIX . 'order_downloads', ['revoked' => $revoked ? 1 : 0], ['order_id' => $orderId]);
    }

    public function regenerateToken(int $grantId): string
    {
        // Return the plaintext token to the caller (shown once); only the
        // hash is persisted.
        $token = bin2hex(random_bytes(32));
        $this->db->update(VL_DB_PREFIX . 'order_downloads', ['token' => self::hashToken($token), 'download_count' => 0], ['id' => $grantId]);
        return $token;
    }

    public function updateDownloadMeta(int $grantId, int $downloadCount, int $downloadLimit, ?string $expiresAt = null): void
    {
        $this->db->update(VL_DB_PREFIX . 'order_downloads', [
            'download_count' => $downloadCount,
            'download_limit' => $downloadLimit,
            'expires_at'     => ($expiresAt !== null && $expiresAt !== '') ? $expiresAt : null,
        ], ['id' => $grantId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function findGrantsByOrderId(int $orderId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'order_downloads' . ' WHERE order_id = ? ORDER BY id ASC',
            [$orderId],
            [ParameterType::INTEGER],
        );
    }
}
