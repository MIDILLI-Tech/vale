<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;
use ValeApp\Application\Media\DeleteMediaUseCase;
use ValeApp\Application\Media\UploadMediaUseCase;
use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Media\MediaUploadService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class MediaController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly MediaRepositoryInterface $media,
        private readonly UploadMediaUseCase $uploadMedia,
        private readonly MediaUploadService $mediaUploadService,
        private readonly DeleteMediaUseCase $deleteMedia,
        private readonly DbalSettingsRepository $settings,
        private readonly SessionManager $session,
        private readonly AdminSearchCache $searchCache,
    ) {
    }

    private const MEDIA_SEARCH_NAMESPACE = 'media_search';
    private const MEDIA_SEARCH_TTL       = 120;

    public function index(Request $request, Response $response): Response
    {
        $params         = $request->getQueryParams();
        $filters        = [];
        $isUnusedFilter = !empty($params['unused']);

        // Batch ceiling exposed to the upload modal: the effective limit is the
        // lower of the app's max_total_size and PHP's post_max_size (past it PHP
        // discards the whole multipart body).
        $uploadLimits    = $this->mediaUploadService->getEffectiveLimits();
        $maxBatchSize    = $this->getMaxBatchBytes();
        $maxBatchSizeMb  = (string) ($uploadLimits['max_total_size_mb'] ?? '');

        if (!empty($params['type'])) {
            $filters['mime_type'] = $params['type'];
        }

        if ($isUnusedFilter) {
            $unusedIds = array_keys($this->media->findUnused());
            if ($unusedIds !== []) {
                $filters['ids'] = $unusedIds;
            } else {
                return $this->twig->render($response, 'admin/media/index.twig', [
                    'media'             => [],
                    'total'             => 0,
                    'filters'           => $params,
                    'client_side'       => true,
                    'page'              => 1,
                    'per_page'          => 24,
                    'base_url'          => admin_url('/media') . '?unused=1',
                    'is_unused_filter'  => true,
                    'max_batch_size'    => $maxBatchSize,
                    'max_batch_size_mb' => $maxBatchSizeMb,
                ]);
            }
        }

        $baseCount  = $this->media->count($filters);
        $clientSide = $baseCount <= client_side_search_fetch_limit();

        if (!empty($params['search'])) {
            $filters['search'] = $params['search'];
        }

        if ($clientSide) {
            $pagination = new Pagination(1, 100000);
            $total      = !empty($filters['search'])
                ? $this->media->count($filters)
                : $baseCount;
            $items = $this->media->findAll($pagination, $filters);
        } else {
            $page       = max(1, (int) ($params['page'] ?? 1));
            $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 24)));
            $pagination = new Pagination($page, $perPage, $params['sort'] ?? null, $params['dir'] ?? 'desc');
            $searchKey  = $this->mediaSearchKeyParts($filters, $pagination);
            $cached     = $searchKey !== null
                ? $this->searchCache->get(self::MEDIA_SEARCH_NAMESPACE, $searchKey)
                : null;
            if ($cached !== null) {
                $total = $cached['total'];
                $items = array_map(
                    static fn (array $row): Media => Media::fromRow($row),
                    $cached['rows'],
                );
            } else {
                $total = $this->media->count($filters);
                $items = $this->media->findAll($pagination, $filters);
                if ($searchKey !== null) {
                    $this->searchCache->set(
                        self::MEDIA_SEARCH_NAMESPACE,
                        $searchKey,
                        $total,
                        array_map($this->mediaToRow(...), $items),
                        self::MEDIA_SEARCH_TTL,
                    );
                }
            }
        }

        $carry   = array_diff_key($params, array_flip(['page', 'sort', 'dir', 'search', 'per_page']));
        $baseUrl = admin_url('/media') . ($carry ? '?' . http_build_query($carry) : '');

        return $this->twig->render($response, 'admin/media/index.twig', [
            'media'                 => $items,
            'total'                 => $total,
            'filters'               => $params,
            'client_side'           => $clientSide,
            'page'                  => $clientSide ? 1 : $pagination->page,
            'per_page'              => $clientSide ? 24 : $pagination->perPage,
            'base_url'              => $baseUrl,
            'is_unused_filter'      => $isUnusedFilter,
            'use_original_filename' => $this->settings->get('media_use_original_filename', '0') === '1',
            'conflict_mode'         => $this->settings->get('media_upload_conflict_mode', 'suffix'),
            'max_file_size_mb'      => $this->getMaxFileSizeMb(),
            'max_file_count'        => $this->getMaxFileCount(),
            'max_batch_size'        => $maxBatchSize,
            'max_batch_size_mb'     => $maxBatchSizeMb,
        ]);
    }

    /** Effective total-batch ceiling in bytes (app cap vs post_max_size). */
    private function getMaxBatchBytes(): int
    {
        return (int) ($this->mediaUploadService->getEffectiveLimits()['max_total_size'] ?? 0);
    }

    public function create(Request $request, Response $response): Response
    {
        return $response->withHeader('Location', admin_url('/media'))->withStatus(302);
    }

    public function store(Request $request, Response $response): Response
    {
        $uploadedFiles = $this->extractUploadedFiles($request);
        $userId        = (int) $this->session->get('user_id');

        if ($uploadedFiles === []) {
            $this->session->set('flash_error', $this->translator->get('No file uploaded.'));
            return $response->withHeader('Location', admin_url('/media/create'))->withStatus(302);
        }

        $fileArrays = [];
        $psrErrors  = [];
        foreach ($uploadedFiles as $uf) {
            $name = htmlspecialchars($uf->getClientFilename() ?? 'unknown', ENT_QUOTES, 'UTF-8');
            if ($uf->getError() !== UPLOAD_ERR_OK) {
                $psrErrors[] = $name . ' (error code ' . $uf->getError() . ')';
                continue;
            }
            $tmpPath = tempnam(sys_get_temp_dir(), 'vl_');
            $uf->moveTo($tmpPath);
            $fileArrays[] = [
                'tmp_name' => $tmpPath,
                'name'     => $uf->getClientFilename(),
                'type'     => $uf->getClientMediaType(),
                'size'     => $uf->getSize(),
                'error'    => UPLOAD_ERR_OK,
            ];
        }

        if ($fileArrays === [] && $psrErrors === []) {
            $this->session->set('flash_error', $this->translator->get('Upload failed.'));
            return $response->withHeader('Location', admin_url('/media/create'))->withStatus(302);
        }

        $uploadedCount    = 0;
        $skippedCount     = 0;
        $overwrittenCount = 0;
        $errorMessages    = $psrErrors;

        if ($fileArrays !== []) {
            try {
                $conflictMode     = $request->getParsedBody()['conflict_mode'] ?? null;
                $result           = $this->uploadMedia->executeBatch($fileArrays, $userId, $conflictMode);
                $uploadedCount    = count($result['uploaded']);
                $skippedCount     = count($result['skipped'] ?? []);
                $overwrittenCount = count($result['overwritten'] ?? []);
                foreach ($result['errors'] as $e) {
                    $errorMessages[] = htmlspecialchars($e['file'], ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($e['error'], ENT_QUOTES, 'UTF-8');
                }
            } catch (\Throwable $e) {
                $errorMessages[] = $e->getMessage();
            } finally {
                foreach ($fileArrays as $fa) {
                    @unlink($fa['tmp_name']);
                }
            }
        }

        $parts = [];
        if ($uploadedCount > 0 || $overwrittenCount > 0) {
            $this->searchCache->bump(self::MEDIA_SEARCH_NAMESPACE);
        }
        if ($uploadedCount > 0) {
            $parts[] = $this->translator->get(':count file(s) uploaded successfully.', [':count' => (string) $uploadedCount]);
        }
        if ($skippedCount > 0) {
            $parts[] = $this->translator->get(':count file(s) skipped.', [':count' => (string) $skippedCount]);
        }
        if ($overwrittenCount > 0) {
            $parts[] = $this->translator->get(':count file(s) overwritten.', [':count' => (string) $overwrittenCount]);
        }
        if ($errorMessages !== []) {
            $parts[] = $this->translator->get(':count file(s) failed.', [':count' => (string) count($errorMessages)]);
        }

        if ($parts !== []) {
            $msg = implode(' ', $parts);
            if ($uploadedCount > 0 || $overwrittenCount > 0) {
                $this->session->set('flash_success', $msg);
            } else {
                $this->session->set('flash_error', $msg);
            }
        } else {
            $this->session->set('flash_error', $this->translator->get('Upload failed.'));
        }

        return $response->withHeader('Location', admin_url('/media'))->withStatus(302);
    }

    /** POST /admin/bulk/media */
    public function bulkAction(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $rawIds = $body['ids'] ?? '[]';
        if (is_string($rawIds) && str_contains($rawIds, ',')) {
            $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        } else {
            $ids = array_filter(array_map('intval', is_array($rawIds) ? $rawIds : (array) json_decode((string) $rawIds, true)));
        }
        $action = $body['action'] ?? '';

        if ($ids === []) {
            $this->session->set('flash_error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/media'))->withStatus(302);
        }

        $count = 0;
        foreach ($ids as $id) {
            if ($action === 'delete') {
                try {
                    $this->deleteMedia->execute((int) $id);
                    $count++;
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        $this->session->set('flash_success', $this->translator->get(':count file(s) deleted.', [':count' => (string) $count]));
        if ($count > 0) {
            $this->searchCache->bump(self::MEDIA_SEARCH_NAMESPACE);
        }
        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '' && str_contains($referer, admin_url('/media/unused'))) {
            $redirect = admin_url('/media/unused');
        } else {
            $redirect = admin_url('/media');
        }
        return $response->withHeader('Location', $redirect)->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $item = $this->media->findById((int) $args['id']);
        if ($item === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/media/edit.twig', [
            'media'                  => $item,
            'media_fields'           => $this->buildMediaFormValues($item),
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'settings'               => [
                'default_locale'         => $this->settings->get('default_locale', 'en'),
                'content_languages'      => $this->settings->get('content_languages', $this->settings->get('default_locale', 'en')),
                'multi_language_content' => $this->settings->get('multi_language_content', '0'),
            ],
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $item = $this->media->findById((int) $args['id']);
        if ($item === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $item->setTitle($body['title'] ?? null);
        $this->applyMediaI18nFromRequest($item, $body);
        $this->media->save($item);
        $this->searchCache->bump(self::MEDIA_SEARCH_NAMESPACE);

        $this->session->set('flash_success', $this->translator->get('Media updated.'));
        return $response->withHeader('Location', admin_url('/media/' . $args['id'] . '/edit'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        try {
            $this->deleteMedia->execute((int) $args['id']);
            $this->searchCache->bump(self::MEDIA_SEARCH_NAMESPACE);
            $this->session->set('flash_success', $this->translator->get('Media deleted.'));
        } catch (ValidationException $e) {
            $this->session->set('flash_error', $this->translator->get('Media not found.'));
        }

        return $response->withHeader('Location', admin_url('/media'))->withStatus(302);
    }

    /**
     * Extract uploaded files from request, supporting both:
     * - multi: files[] (array of UploadedFile)
     * - single: file (single UploadedFile, backward compat)
     *
     * @return UploadedFileInterface[]
     */
    private function extractUploadedFiles(Request $request): array
    {
        $all = $request->getUploadedFiles();

        if (!empty($all['files']) && is_array($all['files'])) {
            return array_values(array_filter($all['files'], fn ($f) => $f !== null && $f->getError() !== UPLOAD_ERR_NO_FILE));
        }

        if (!empty($all['file']) && $all['file']->getError() !== UPLOAD_ERR_NO_FILE) {
            return [$all['file']];
        }

        return [];
    }

    /** POST /admin/media/upload-json-multi — AJAX multi-file upload, returns JSON */
    public function uploadJsonMulti(Request $request, Response $response): Response
    {
        $uploadedFiles = $this->extractUploadedFiles($request);
        $userId        = (int) $this->session->get('user_id');

        if ($uploadedFiles === []) {
            $payload = json_encode(['uploaded' => [], 'errors' => [['file' => '*', 'error' => $this->noFilesReceivedMessage($request)]]], JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $fileArrays = [];
        foreach ($uploadedFiles as $uf) {
            if ($uf->getError() !== UPLOAD_ERR_OK) {
                $fileArrays[] = [
                    'tmp_name' => '',
                    'name'     => htmlspecialchars($uf->getClientFilename() ?? 'unknown', ENT_QUOTES, 'UTF-8'),
                    'type'     => '',
                    'size'     => 0,
                    'error'    => $uf->getError(),
                ];
                continue;
            }
            $tmpPath = tempnam(sys_get_temp_dir(), 'vl_');
            $uf->moveTo($tmpPath);
            $fileArrays[] = [
                'tmp_name' => $tmpPath,
                'name'     => $uf->getClientFilename(),
                'type'     => $uf->getClientMediaType(),
                'size'     => $uf->getSize(),
                'error'    => $uf->getError(),
            ];
        }

        $result = ['uploaded' => [], 'skipped' => [], 'overwritten' => [], 'errors' => []];

        try {
            $conflictMode = $request->getParsedBody()['conflict_mode'] ?? null;
            $result       = $this->uploadMedia->executeBatch($fileArrays, $userId, $conflictMode);
            if ($result['uploaded'] !== [] || ($result['overwritten'] ?? []) !== []) {
                $this->searchCache->bump(self::MEDIA_SEARCH_NAMESPACE);
            }

            // Convert Media entities to plain arrays for JSON
            $baseUrl            = site_url('/uploads');
            $result['uploaded'] = array_map(fn ($m) => [
                'id'         => $m->getId(),
                'name'       => $m->getOriginalName(),
                'url'        => $m->getUrl($baseUrl),
                'thumbnail'  => $m->getThumbnailUrl('medium', $baseUrl),
                'thumbnails' => $this->thumbnailsPayload($m, $baseUrl),
                'size'       => $m->getSize(),
                'mime'       => $m->getMimeType(),
            ], $result['uploaded']);
        } catch (\Throwable $e) {
            $result['errors'][] = ['file' => '*', 'error' => $e->getMessage()];
        } finally {
            foreach ($fileArrays as $fa) {
                @unlink($fa['tmp_name']);
            }
        }

        $payload = json_encode($result, JSON_THROW_ON_ERROR);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** POST /admin/media/upload-json — AJAX upload, returns JSON with URL */
    public function uploadJson(Request $request, Response $response): Response
    {
        $files  = $request->getUploadedFiles();
        $userId = (int) $this->session->get('user_id');

        $file      = $files['file'] ?? null;
        $fileError = $file?->getError();

        if ($file === null || $fileError === UPLOAD_ERR_NO_FILE) {
            $payload = json_encode(['error' => $this->noFilesReceivedMessage($request)], JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        if ($fileError !== UPLOAD_ERR_OK) {
            $message = $fileError === UPLOAD_ERR_INI_SIZE
                ? 'File exceeds the server upload limit (upload_max_filesize = ' . $this->formatBytes(MediaUploadService::parsePhpLimit(ini_get('upload_max_filesize'))) . ').'
                : 'Upload failed (error code ' . $fileError . ').';
            $payload = json_encode(['error' => $message], JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $uploaded = $files['file'];
        $tmpPath  = tempnam(sys_get_temp_dir(), 'vl_');
        $uploaded->moveTo($tmpPath);

        $fileArray = [
            'tmp_name' => $tmpPath,
            'name'     => $uploaded->getClientFilename(),
            'type'     => $uploaded->getClientMediaType(),
            'size'     => $uploaded->getSize(),
            'error'    => UPLOAD_ERR_OK,
        ];

        try {
            $media   = $this->uploadMedia->execute($fileArray, $userId);
            $this->searchCache->bump('media_search');
            $baseUrl = site_url('/uploads');
            $payload = json_encode([
                'url'        => $media->getUrl($baseUrl),
                'thumbnails' => $this->thumbnailsPayload($media, $baseUrl),
            ], JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        } catch (ValidationException $e) {
            @unlink($tmpPath);
            $msgs = [];
            foreach ($e->getErrors() as $fieldErrors) {
                array_push($msgs, ...$fieldErrors);
            }
            $payload = json_encode(['error' => implode(' ', $msgs)], JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
        }
    }

    /** GET /admin/media/browse-json — JSON list of images for the editor media picker */
    public function browseJson(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $filters = [];
        if (!empty($params['type'])) {
            $filters['mime_type'] = $params['type'];
        }
        if (!empty($params['q'])) {
            $filters['search'] = $params['q'];
        }
        if (!empty($params['ids'])) {
            $ids = array_values(array_filter(array_map(
                'intval',
                explode(',', (string) $params['ids'])
            ), static fn (int $id): bool => $id > 0));
            if ($ids !== []) {
                // Batch resolve (gallery/featured init) — cap the batch size.
                $filters['ids'] = array_slice(array_values(array_unique($ids)), 0, 200);
            }
        }

        $defaultLocale = strtolower(trim((string) ($this->settings->get('default_locale', 'en') ?? 'en')));
        $locale        = strtolower(trim((string) ($params['lang'] ?? $defaultLocale)));
        $fallback      = $this->settings->get('i18n_display_originals_fallback', '1') === '1';

        $perPage = 40;
        $page    = max(1, (int) ($params['page'] ?? 1));
        $total   = $this->media->count($filters);
        $items   = $this->media->findAll(new Pagination($page, $perPage), $filters);
        $list    = [];

        $baseUrl = site_url('/uploads');
        foreach ($items as $item) {
            $list[] = [
                'id'          => $item->getId(),
                'name'        => $item->getOriginalName(),
                'url'         => $item->getUrl($baseUrl),
                'thumbnail'   => $item->getThumbnailUrl('medium', $baseUrl),
                'thumbnails'  => $this->thumbnailsPayload($item, $baseUrl),
                'size'        => $item->getSize(),
                'mime'        => $item->getMimeType(),
                'alt_text'    => $item->getLocalizedAltText($locale, $defaultLocale, $fallback)     ?? $item->getAltText(),
                'description' => $item->getLocalizedDescription($locale, $defaultLocale, $fallback) ?? $item->getDescription(),
            ];
        }

        $payload = json_encode([
            'items'       => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ], JSON_THROW_ON_ERROR);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function getMaxFileSizeMb(): string
    {
        // Stored in megabytes (admin form label); 0 = use PHP upload_max_filesize.
        $mb = (int) $this->settings->get('media_max_file_size', '0');
        if ($mb <= 0) {
            $mb = (int) round(MediaUploadService::parsePhpLimit(ini_get('upload_max_filesize')) / 1048576);
        }
        return (string) $mb;
    }

    private function getMaxFileCount(): int
    {
        $count = (int) $this->settings->get('media_max_file_count', '0');
        if ($count <= 0) {
            $count = (int) ini_get('max_file_uploads');
        }
        return $count;
    }

    /**
     * Build an actionable error for the common "no files reached the server"
     * case. When the request exceeds PHP's post_max_size the whole body is
     * silently discarded — files and fields alike — so the endpoint only sees
     * an empty upload and the message must point at the real cause.
     */
    private function noFilesReceivedMessage(Request $request): string
    {
        $postMax    = MediaUploadService::parsePhpLimit(ini_get('post_max_size'));
        $uploadMax  = MediaUploadService::parsePhpLimit(ini_get('upload_max_filesize'));
        $maxUploads = (int) ini_get('max_file_uploads');
        $contentLen = (int) $request->getHeaderLine('Content-Length');

        if ($postMax > 0 && $contentLen > $postMax) {
            return sprintf(
                'No files were received: the request (~%s) exceeds the server\'s post_max_size limit (%s). '
                . 'Upload fewer or smaller files.',
                $this->formatBytes($contentLen),
                $this->formatBytes($postMax),
            );
        }

        if ($postMax > 0 && $uploadMax > 0) {
            return sprintf(
                'No files were received by the server. PHP discards the whole request when it exceeds '
                . 'post_max_size (%s) — limits here are upload_max_filesize (%s) per file and '
                . 'max_file_uploads (%d) files per request — so try fewer or smaller files.',
                $this->formatBytes($postMax),
                $this->formatBytes($uploadMax),
                $maxUploads,
            );
        }

        return 'No files were received by the server. Please try again.';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return (string) round($bytes / 1024) . ' KB';
        }
        return $bytes . ' B';
    }

    /** @return array<string, string> */
    private function buildMediaFormValues(Media $item): array
    {
        $values = [
            'alt_text'    => $item->getAltText()     ?? '',
            'description' => $item->getDescription() ?? '',
        ];

        foreach ($item->getAltTextI18n() as $code => $text) {
            $values['alt_text_' . $code] = $text;
        }

        foreach ($item->getDescriptionI18n() as $code => $text) {
            $values['description_' . $code] = $text;
        }

        return $values;
    }

    /** @param array<string, mixed> $body */
    private function applyMediaI18nFromRequest(Media $item, array $body): void
    {
        $defaultLang = strtolower(trim((string) ($this->settings->get('default_locale', 'en') ?? 'en')));
        $item->setAltText($this->nullableTrimmedString($body['alt_text'] ?? null));
        $item->setDescription($this->nullableTrimmedString($body['description'] ?? null));

        if ($this->settings->get('multi_language_content', '0') !== '1') {
            $item->setAltTextI18n([]);
            $item->setDescriptionI18n([]);
            return;
        }

        $altMap  = [];
        $descMap = [];
        foreach ($this->getContentLanguageCodes() as $code) {
            if ($code === $defaultLang) {
                continue;
            }

            $altVal = $this->nullableTrimmedString($body['alt_text_' . $code] ?? null);
            if ($altVal !== null) {
                $altMap[$code] = $altVal;
            }

            $descVal = $this->nullableTrimmedString($body['description_' . $code] ?? null);
            if ($descVal !== null) {
                $descMap[$code] = $descVal;
            }
        }

        $item->setAltTextI18n($altMap);
        $item->setDescriptionI18n($descMap);
    }

    /** @return string[] */
    private function getContentLanguageCodes(): array
    {
        $default = strtolower(trim((string) ($this->settings->get('default_locale', 'en') ?? 'en')));
        $raw     = (string) ($this->settings->get('content_languages', $default) ?? $default);
        $codes   = array_values(array_filter(array_map(
            static fn (string $code): string => strtolower(trim($code)),
            explode(',', $raw),
        )));

        return $codes !== [] ? $codes : [$default];
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Cache key parts for a server-side search listing, or null when the
     * request should bypass the search cache (no search term, or an explicit
     * id list such as the unused-files filter whose key would be unbounded).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>|null
     */
    private function mediaSearchKeyParts(array $filters, Pagination $pagination): ?array
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search === '' || !empty($filters['ids'])) {
            return null;
        }
        return [
            'search' => mb_strtolower($search, 'UTF-8'),
            'type'   => (string) ($filters['mime_type'] ?? ''),
            'page'   => $pagination->page,
            'per'    => $pagination->perPage,
            'sort'   => (string) ($pagination->sortBy ?? ''),
            'dir'    => strtolower((string) $pagination->sortDir),
        ];
    }

    /**
     * Entity → scalar-only DB-row shape for JSON-safe caching.
     * Mirrors Media::fromRow() so hits re-hydrate losslessly.
     *
     * @return array<string, mixed>
     */
    private function mediaToRow(Media $media): array
    {
        return [
            'id'               => $media->getId(),
            'user_id'          => $media->getUserId(),
            'filename'         => $media->getFilename(),
            'original_name'    => $media->getOriginalName(),
            'mime_type'        => $media->getMimeType(),
            'size'             => $media->getSize(),
            'path'             => $media->getPath(),
            'disk'             => $media->getDisk(),
            'alt_text'         => $media->getAltText(),
            'title'            => $media->getTitle(),
            'description'      => $media->getDescription(),
            'alt_text_i18n'    => $this->encodeI18n($media->getAltTextI18n()),
            'description_i18n' => $this->encodeI18n($media->getDescriptionI18n()),
            'thumbnails'       => json_encode($media->getThumbnails()),
            'content_hash'     => $media->getContentHash(),
            'created_at'       => $media->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updated_at'       => $media->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string, string> $map */
    private function encodeI18n(array $map): ?string
    {
        return $map === [] ? null : json_encode($map, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Full URL map of every stored thumbnail rendition
     * (small/medium/large plus webp and webp_small/webp_medium/webp_large).
     *
     * @return array<string, string>
     */
    private function thumbnailsPayload(Media $media, string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');
        $out  = [];
        foreach ($media->getThumbnails() as $sizeName => $relPath) {
            if (!is_string($relPath) || $relPath === '') {
                continue;
            }
            $out[$sizeName] = $base . '/' . ltrim($relPath, '/');
        }
        return $out;
    }
}
