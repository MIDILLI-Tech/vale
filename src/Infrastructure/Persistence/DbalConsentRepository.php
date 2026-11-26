<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use ValeApp\Domain\Consent\Repository\ConsentRepositoryInterface;

final class DbalConsentRepository implements ConsentRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {
    }

    public function record(
        string $consentId,
        string $action,
        array $categories,
        string $version,
        ?int $userId = null,
    ): void {
        $this->db->insert(VL_DB_PREFIX . 'consent_log', [
            'consent_id'     => $consentId,
            'action'         => $action,
            'categories'     => json_encode(array_values($categories), JSON_UNESCAPED_SLASHES) ?: '[]',
            'policy_version' => $version,
            'user_id'        => $userId,
        ]);
    }

    public function pruneOlderThan(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d H:i:s');

        return (int) $this->db->executeStatement(
            'DELETE FROM ' . VL_DB_PREFIX . 'consent_log WHERE created_at < ?',
            [$cutoff],
        );
    }
}
