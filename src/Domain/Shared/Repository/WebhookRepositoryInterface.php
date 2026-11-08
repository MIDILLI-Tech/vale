<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Repository;

use ValeApp\Domain\Shared\Entity\Webhook;

interface WebhookRepositoryInterface
{
    public function findById(int $id): ?Webhook;

    /** @return Webhook[] */
    public function findAll(): array;

    /** @return Webhook[] Only is_active = 1 rows */
    public function findActive(): array;

    public function save(Webhook $webhook): Webhook;

    public function delete(int $id): void;
}
