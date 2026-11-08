<?php

declare(strict_types=1);

namespace ValeApp\Application\Media;

use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Infrastructure\Media\MediaUploadService;

final class DeleteMediaUseCase
{
    public function __construct(
        private readonly MediaRepositoryInterface $media,
        private readonly MediaUploadService $uploadService,
    ) {
    }

    public function execute(int $id): void
    {
        $item = $this->media->findById($id);
        if ($item === null) {
            throw ValidationException::withMessages(['id' => 'Media not found.']);
        }

        $this->uploadService->deleteFile($item);
        $this->media->delete($id);
    }
}
