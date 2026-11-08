<?php

declare(strict_types=1);

namespace ValeApp\Application\Media;

use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Infrastructure\Media\MediaUploadService;

final class UploadMediaUseCase
{
    public function __construct(
        private readonly MediaUploadService $uploadService,
    ) {
    }

    /**
     * @param array{tmp_name: string, name: string, type: string, size: int, error: int} $file
     */
    public function execute(array $file, int $userId): Media
    {
        return $this->uploadService->upload($file, $userId);
    }

    /**
     * @param array[] $files
     * @return array{uploaded: Media[], errors: array{file: string, error: string}[]}
     */
    public function executeBatch(array $files, int $userId, ?string $conflictMode = null): array
    {
        return $this->uploadService->uploadBatch($files, $userId, $conflictMode);
    }
}
