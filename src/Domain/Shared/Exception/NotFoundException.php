<?php

declare(strict_types=1);

namespace ValeApp\Domain\Shared\Exception;

/**
 * Thrown when a requested entity does not exist.
 */
class NotFoundException extends DomainException
{
    public static function forEntity(string $entity, int|string $id): static
    {
        return new static(sprintf('%s with id "%s" not found.', $entity, $id));
    }
}
