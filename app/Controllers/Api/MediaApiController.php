<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Application\Media\DeleteMediaUseCase;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Media\MediaUploadService;
use ValeApp\Infrastructure\Media\RemoteImageImporter;

final class MediaApiController
{
    public function __construct(
        private readonly MediaRepositoryInterface $media,
        private readonly MediaUploadService $uploadService,
        private readonly RemoteImageImporter $imageImporter,
        private readonly DeleteMediaUseCase $deleteMedia,
    ) {
    }

    /** GET /api/v1/media */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 30)));

        $items   = $this->media->findAll(new Pagination($page, $perPage));
        $total   = $this->media->count();
        $siteUrl = __require_app_url();

        return $this->json($response, [
            'data' => array_map(fn ($m) => [
                'id'            => $m->getId(),
                'filename'      => $m->getFilename(),
                'original_name' => $m->getOriginalName(),
                'mime_type'     => $m->getMimeType(),
                'size'          => $m->getSize(),
                'url'           => $siteUrl . $m->getUrl(),
            ], $items),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    /** POST /api/v1/media — JSON {url}|{base64,name} or multipart files[] */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId      = (int) $request->getAttribute('api_user_id');
        $contentType = strtolower($request->getHeaderLine('Content-Type'));

        if (str_contains($contentType, 'multipart/form-data')) {
            $files = $request->getUploadedFiles()['files'] ?? [];
            if ($files === [] || !is_array($files)) {
                return $this->error($response, 422, 'No files uploaded. Send multipart field "files[]" or a JSON body with {url} / {base64, name}.');
            }
            try {
                $result = $this->uploadService->uploadBatch($files, $userId);
            } catch (\Throwable $e) {
                return $this->error($response, 422, $e->getMessage());
            }
            return $this->json($response->withStatus(201), [
                'message' => count($result['uploaded']) . ' file(s) uploaded',
                'data'    => array_map(fn ($m) => $this->serializeOne($m), $result['uploaded']),
                'errors'  => $result['errors'],
            ]);
        }

        $input = (array) $request->getParsedBody();

        if (!empty($input['url'])) {
            $id = $this->imageImporter->importFromInput((string) $input['url'], $userId);
            if ($id === null) {
                return $this->error($response, 422, 'Could not import image from the given URL.');
            }
        } elseif (!empty($input['base64'])) {
            $raw     = (string) $input['base64'];
            $payload = str_contains($raw, ',') ? substr($raw, strpos($raw, ',') + 1) : $raw;
            $decoded = base64_decode($payload, true);
            if ($decoded === false || $decoded === '') {
                return $this->error($response, 422, 'Invalid base64 payload.');
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'api_media_');
            file_put_contents($tmpFile, $decoded);
            try {
                $media = $this->uploadService->upload([
                    'tmp_name' => $tmpFile,
                    'name'     => (string) ($input['name'] ?? 'upload.bin'),
                    'type'     => mime_content_type($tmpFile) ?: 'application/octet-stream',
                    'size'     => strlen($decoded),
                    'error'    => UPLOAD_ERR_OK,
                ], $userId);
                $id = $media->getId();
            } catch (\Throwable $e) {
                return $this->error($response, 422, $e->getMessage());
            } finally {
                @unlink($tmpFile);
            }
        } else {
            return $this->error($response, 422, 'Provide "url" or "base64" in JSON body, or upload multipart files[].');
        }

        $media = $this->media->findById($id);

        return $this->json($response->withStatus(201), [
            'message' => 'Media imported',
            'data'    => $media !== null ? $this->serializeOne($media) : ['id' => $id],
        ]);
    }

    /** DELETE /api/v1/media/{id} */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        if ($this->media->findById($id) === null) {
            return $this->error($response, 404, 'Not Found');
        }

        $this->deleteMedia->execute($id);

        return $this->json($response, ['message' => 'Deleted']);
    }

    /** @return array<string, mixed> */
    private function serializeOne(object $m): array
    {
        $siteUrl = __require_app_url();
        return [
            'id'            => $m->getId(),
            'filename'      => $m->getFilename(),
            'original_name' => $m->getOriginalName(),
            'mime_type'     => $m->getMimeType(),
            'size'          => $m->getSize(),
            'url'           => $siteUrl . $m->getUrl(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        return $this->json($response->withStatus($status), ['error' => $message]);
    }
}
