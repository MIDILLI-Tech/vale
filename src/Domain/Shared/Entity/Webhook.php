<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Entity;

final class Webhook extends BaseEntity
{
    private string $name;
    private string $url;
    /** @var 'GET'|'POST'|'PUT'|'PATCH'|'DELETE' */
    private string $method        = 'POST';
    private ?string $bodyTemplate = null;
    private ?string $secret;
    /** @var array<string,string> Extra request headers, e.g. ['X-LUMI-TOKEN' => 'abc'] */
    private array $headers = [];
    /** @var string[] */
    private array $events                           = [];
    private bool $isActive                          = true;
    private ?\DateTimeImmutable $lastDeliveryAt     = null;
    private ?string $lastDeliveryStatus             = null; // ok|error
    private ?int $lastDeliveryMs                    = null;

    public function __construct(string $name, string $url, ?string $secret = null)
    {
        $this->name   = $name;
        $this->url    = $url;
        $this->secret = $secret ?: null;
    }

    public static function fromRow(array $row): self
    {
        $w = new self(
            $row['name'],
            $row['url'],
            $row['secret'] ?? null,
        );
        $w->setId((int) $row['id']);
        $w->method       = strtoupper((string) ($row['method'] ?? 'POST'));
        $w->bodyTemplate = $row['body_template'] !== '' ? ($row['body_template'] ?? null) : null;
        $w->events       = json_decode((string) ($row['events'] ?? '[]'), true) ?: [];
        $w->headers      = json_decode((string) ($row['headers'] ?? '{}'), true) ?: [];
        $w->isActive     = (bool) ($row['is_active'] ?? true);
        if (!empty($row['last_delivery_at'])) {
            $w->lastDeliveryAt = new \DateTimeImmutable($row['last_delivery_at']);
        }
        $w->lastDeliveryStatus = $row['last_delivery_status'] ?? null;
        $w->lastDeliveryMs     = isset($row['last_delivery_ms']) ? (int) $row['last_delivery_ms'] : null;
        if (!empty($row['created_at'])) {
            $w->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $w->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        return $w;
    }

    // ── Getters ──────────────────────────────────────────────────────────────

    public function getName(): string
    {
        return $this->name;
    }
    public function getUrl(): string
    {
        return $this->url;
    }
    public function getMethod(): string
    {
        return $this->method;
    }
    public function getBodyTemplate(): ?string
    {
        return $this->bodyTemplate;
    }
    public function getSecret(): ?string
    {
        return $this->secret;
    }
    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    /** @return string[] */
    public function getEvents(): array
    {
        return $this->events;
    }
    public function isActive(): bool
    {
        return $this->isActive;
    }
    public function getLastDeliveryAt(): ?\DateTimeImmutable
    {
        return $this->lastDeliveryAt;
    }
    public function getLastDeliveryStatus(): ?string
    {
        return $this->lastDeliveryStatus;
    }
    public function getLastDeliveryMs(): ?int
    {
        return $this->lastDeliveryMs;
    }

    // ── Setters ──────────────────────────────────────────────────────────────

    public function setName(string $name): void
    {
        $this->name = $name;
    }
    public function setUrl(string $url): void
    {
        $this->url  = $url;
    }
    /** @param 'GET'|'POST'|'PUT'|'PATCH'|'DELETE' $method */
    public function setMethod(string $method): void
    {
        $this->method = strtoupper($method);
    }
    public function setBodyTemplate(?string $t): void
    {
        $this->bodyTemplate = $t ?: null;
    }
    public function setSecret(?string $secret): void
    {
        $this->secret = $secret ?: null;
    }
    /** @param array<string,string> $headers */
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }
    /** @param string[] $events */
    public function setEvents(array $events): void
    {
        $this->events = array_values($events);
    }
    public function setActive(bool $active): void
    {
        $this->isActive = $active;
    }

    public function recordDelivery(string $status, int $ms): void
    {
        $this->lastDeliveryAt     = new \DateTimeImmutable();
        $this->lastDeliveryStatus = $status;
        $this->lastDeliveryMs     = $ms;
    }

    public function hasEvent(string $event): bool
    {
        return in_array($event, $this->events, true);
    }
}
