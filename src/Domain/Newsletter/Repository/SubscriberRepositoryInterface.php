<?php

declare(strict_types=1);

namespace ValeApp\Domain\Newsletter\Repository;

use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Shared\ValueObject\Pagination;

interface SubscriberRepositoryInterface
{
    public function findById(int $id): ?Subscriber;

    public function findByEmail(string $email): ?Subscriber;

    public function findByToken(string $token): ?Subscriber;

    public function findByUserId(int $userId): ?Subscriber;

    /** @return Subscriber[] */
    public function findAll(Pagination $pagination, array $filters = []): array;

    public function count(array $filters = []): int;

    /** @return Subscriber[] Active subscribers, optionally filtered by tag, source form, order count, and rates */
    public function findActive(?string $tag = null, ?int $sourceFormId = null, ?int $minOrders = null, ?int $maxOrders = null, ?int $minOpenRate = null, ?int $maxOpenRate = null, ?int $minClickRate = null, ?int $maxClickRate = null): array;

    /** Count of active subscribers matching the same filters as findActive() (audience estimate for unsent campaigns) */
    public function countActive(?string $tag = null, ?int $sourceFormId = null, ?int $minOrders = null, ?int $maxOrders = null, ?int $minOpenRate = null, ?int $maxOpenRate = null, ?int $minClickRate = null, ?int $maxClickRate = null): int;

    public function save(Subscriber $subscriber): Subscriber;

    public function delete(int $id): void;

    /** @return string[] All unique tag names across all subscribers */
    public function getAllTags(): array;
}
