<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Event;

/**
 * Marker interface for domain events.
 */
interface DomainEventInterface
{
    public function getName(): string;

    public function getPayload(): array;

    public function getOccurredAt(): \DateTimeImmutable;
}
