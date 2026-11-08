<?php

declare(strict_types=1);

namespace ValeApp\Domain\Newsletter\Repository;

use ValeApp\Domain\Newsletter\Entity\Campaign;
use ValeApp\Domain\Shared\ValueObject\Pagination;

interface CampaignRepositoryInterface
{
    public function findById(int $id): ?Campaign;

    /**
     * Search matches subject, B-subject and sender email. Campaign HTML
     * bodies are only included when $searchBody is set (admin opt-in).
     * @return Campaign[] */
    public function findAll(Pagination $pagination, ?string $search = null, bool $searchBody = false): array;

    /**
     * Search matches subject, B-subject and sender email. Campaign HTML
     * bodies are only included when $searchBody is set (admin opt-in). */
    public function count(?string $search = null, bool $searchBody = false): int;

    public function save(Campaign $campaign): Campaign;

    public function delete(int $id): void;

    /**
     * Log send to a recipient and return tracking token.
     */
    public function addRecipient(int $campaignId, int $subscriberId, string $variation = 'A'): string;

    /**
     * Remove all recipients for a campaign (used before resend).
     */
    public function clearRecipients(int $campaignId): void;

    public function recordOpen(string $trackingToken): void;

    /**
     * @return array[] Rows with subscriber + tracking fields for a campaign.
     */
    public function findRecipientsByCampaignId(int $campaignId, Pagination $pagination, ?string $search = null, ?string $filter = null): array;

    public function countRecipientsByCampaignId(int $campaignId, ?string $search = null, ?string $filter = null): int;

    public function recordClick(string $trackingToken): void;

    /**
     * @param int[] $subscriberIds
     * @return array<int, array{campaigns_count: int, opens_count: int, clicks_count: int}>
     */
    public function getSubscribersStats(array $subscriberIds): array;

    /** @return Campaign[] */
    public function findBySubscriberId(int $subscriberId, Pagination $pagination, ?string $filter = null): array;

    public function countBySubscriberId(int $subscriberId, ?string $filter = null): int;

    /**
     * Return campaigns with status='scheduled' and scheduled_at <= NOW().
     *
     * @return Campaign[]
     */
    public function findDueForSending(): array;

    /** @return Campaign[] */
    public function findActiveRecurring(): array;
}
