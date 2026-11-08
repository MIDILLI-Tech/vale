<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Event;

/**
 * Generic domain event implementation.
 */
final class DomainEvent implements DomainEventInterface
{
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        private readonly string $name,
        private readonly array $payload = [],
    ) {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
