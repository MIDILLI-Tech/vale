<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\Entity\Webhook;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;

final class DbalWebhookRepository implements WebhookRepositoryInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function findById(int $id): ?Webhook
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'webhooks' . ' WHERE id = ?',
            [$id],
            [ParameterType::INTEGER]
        );
        return $row !== false ? Webhook::fromRow($row) : null;
    }

    /** @return Webhook[] */
    public function findAll(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'webhooks' . ' ORDER BY created_at DESC'
        );
        return array_map(fn (array $r) => Webhook::fromRow($r), $rows);
    }

    /** @return Webhook[] */
    public function findActive(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'webhooks' . ' WHERE is_active = TRUE ORDER BY id ASC'
        );
        return array_map(fn (array $r) => Webhook::fromRow($r), $rows);
    }

    public function save(Webhook $webhook): Webhook
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $data = [
            'name'                 => $webhook->getName(),
            'url'                  => $webhook->getUrl(),
            'method'               => $webhook->getMethod(),
            'body_template'        => $webhook->getBodyTemplate(),
            'secret'               => $webhook->getSecret(),
            'headers'              => $webhook->getHeaders() !== [] ? json_encode($webhook->getHeaders(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'events'               => json_encode($webhook->getEvents(), JSON_UNESCAPED_SLASHES),
            'is_active'            => $webhook->isActive() ? 1 : 0,
            'last_delivery_at'     => $webhook->getLastDeliveryAt()?->format('Y-m-d H:i:s'),
            'last_delivery_status' => $webhook->getLastDeliveryStatus(),
            'last_delivery_ms'     => $webhook->getLastDeliveryMs(),
            'updated_at'           => $now,
        ];

        if ($webhook->getId() === null) {
            $data['created_at'] = $now;
            $this->db->insert(VL_DB_PREFIX . 'webhooks', $data);
            $webhook->setId((int) $this->db->lastInsertId());
        } else {
            $this->db->update(VL_DB_PREFIX . 'webhooks', $data, ['id' => $webhook->getId()]);
        }

        return $webhook;
    }

    public function delete(int $id): void
    {
        $this->db->delete(VL_DB_PREFIX . 'webhooks', ['id' => $id]);
    }
}
