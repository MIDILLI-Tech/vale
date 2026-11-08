<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Media;

use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;

/**
 * In-memory stand-in for DbalMediaRepository so media upload services can be
 * exercised without a database.
 */
final class ArrayMediaRepository implements MediaRepositoryInterface
{
    /** @var array<int, Media> */
    private array $rows = [];

    private int $nextId = 1;

    public function findById(int $id): ?Media
    {
        return $this->rows[$id] ?? null;
    }

    public function findByPath(string $path): ?Media
    {
        foreach ($this->rows as $row) {
            if ($row->getPath() === $path) {
                return $row;
            }
        }
        return null;
    }

    public function findByContentHash(string $hash): ?Media
    {
        if ($hash === '') {
            return null;
        }
        foreach ($this->rows as $row) {
            if ($row->getContentHash() === $hash) {
                return $row;
            }
        }
        return null;
    }

    public function findByOriginalName(string $originalName): array
    {
        $matches = array_values(array_filter(
            $this->rows,
            static fn (Media $row): bool => $row->getOriginalName() === $originalName,
        ));
        usort($matches, static fn (Media $a, Media $b): int => $b->getId() <=> $a->getId());
        return $matches;
    }

    public function findAll(Pagination $pagination, array $filters = []): array
    {
        return array_values($this->rows);
    }

    public function count(array $filters = []): int
    {
        return count($this->rows);
    }

    public function save(Media $media): int
    {
        if ($media->isNew()) {
            $media->setId($this->nextId++);
        }
        $id              = $media->getId();
        $this->rows[$id] = $media;
        return $id;
    }

    public function delete(int $id): void
    {
        unset($this->rows[$id]);
    }

    public function getTotalSizeByUser(int $userId): int
    {
        $total = 0;
        foreach ($this->rows as $row) {
            if ($row->getUserId() === $userId) {
                $total += $row->getSize();
            }
        }
        return $total;
    }

    public function findMissingFiles(): array
    {
        return [];
    }

    public function findUnused(): array
    {
        return [];
    }

    public function findReferencedIds(): array
    {
        return [];
    }

    public function seed(Media $media): Media
    {
        $id              = $this->nextId++;
        $media->setId($id);
        $this->rows[$id] = $media;
        return $media;
    }

    /** @return Media[] */
    public function all(): array
    {
        return array_values($this->rows);
    }

    /** @return string[] */
    public function allPaths(): array
    {
        return array_map(static fn (Media $row): string => $row->getPath(), $this->rows);
    }
}
