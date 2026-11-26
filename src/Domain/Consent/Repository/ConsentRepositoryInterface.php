<?php

declare(strict_types=1);

namespace ValeApp\Domain\Consent\Repository;

interface ConsentRepositoryInterface
{
    /**
     * Append one consent decision to the log.
     *
     * The consent id is a random value stored in the visitor's cookie; it links
     * the visitor's decisions without storing an IP address or user agent.
     *
     * @param list<string> $categories
     */
    public function record(
        string $consentId,
        string $action,
        array $categories,
        string $version,
        ?int $userId = null,
    ): void;

    /**
     * Delete log rows older than the given number of days.
     */
    public function pruneOlderThan(int $days): int;
}
