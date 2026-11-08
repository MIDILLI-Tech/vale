<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Entity;

/**
 * Base entity with common fields.
 * All domain entities extend this.
 */
abstract class BaseEntity
{
    protected ?int $id                       = null;
    protected ?\DateTimeImmutable $createdAt = null;
    protected ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isNew(): bool
    {
        return $this->id === null;
    }
}
