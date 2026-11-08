<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;
use Slim\Views\Twig;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Infrastructure\Http\RemoteFetchException;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;
use ValeApp\Infrastructure\Security\PrivateNetworkGuard;

final class DownloadController
{
    private readonly SafeRemoteFetcher $fetcher;

    public function __construct(
        private readonly DownloadRepositoryInterface $downloads,
        private readonly MediaRepositoryInterface $media,
        private readonly Twig $twig,
        ?SafeRemoteFetcher $fetcher = null,
    ) {
        $this->fetcher = $fetcher ?? new SafeRemoteFetcher();
    }

    public function serve(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');

        // Token must be exactly 64 hex characters
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return $this->error($response, 404, 'Download link not found.');
        }

        $grant = $this->downloads->findGrantByToken($token);
        if ($grant === null) {
            return $this->error($response, 404, 'Download link not found.');
        }

        if ((bool) $grant['revoked']) {
            return $this->error($response, 410, 'This download link has been revoked.');
        }

        if ($grant['expires_at'] !== null) {
            $expiresAt = new \DateTimeImmutable($grant['expires_at']);
            if ($expiresAt < new \DateTimeImmutable()) {
                return $this->error($response, 410, 'This download link has expired.');
            }
        }

        $limit = (int) $grant['download_limit'];
        $count = (int) $grant['download_count'];
        if ($limit > 0 && $count >= $limit) {
            return $this->error($response, 410, 'Download limit reached for this link.');
        }

        // Increment before serving (prevents abuse on concurrent requests)
        $this->downloads->incrementDownloadCount((int) $grant['id']);

        $sourceType = (string) $grant['source_type'];

        if ($sourceType === 'external') {
            $url = (string) ($grant['source_url'] ?? '');
            if ($url === '' || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                return $this->error($response, 500, 'Invalid download configuration.');
            }

            if (PrivateNetworkGuard::isPrivateHost($url)) {
                return $this->error($response, 403, 'Forbidden download URL.');
            }

            $useProxy = (bool) ($grant['download_url_proxy'] ?? false);
            if (!$useProxy) {
                return $response->withHeader('Location', $url)->withStatus(302);
            }

            // Proxy streaming. SafeRemoteFetcher re-validates every redirect hop
            // with PrivateNetworkGuard before connecting, so a redirect can never
            // smuggle the request to a private/internal address.
            try {
                $fetch = $this->fetcher->open($url);
            } catch (RemoteFetchException $exception) {
                return match ($exception->getReason()) {
                    RemoteFetchException::REASON_PRIVATE_HOST
                        => $this->error($response, 403, 'Forbidden download URL.'),
                    RemoteFetchException::REASON_TOO_MANY_REDIRECTS,
                    RemoteFetchException::REASON_HTTP_ERROR
                        => $this->error($response, 502, 'Remote server did not return a downloadable file.'),
                    default
                    => $this->error($response, 500, $exception->getMessage()),
                };
            }

            $streamHandle = $fetch['stream'];
            $headers      = $fetch['headers'];
            $currentUrl   = $fetch['url'];

            $contentType   = 'application/octet-stream';
            $contentLength = null;

            foreach ($headers as $h) {
                if (preg_match('/^Content-Type:\s*(.+)$/i', $h, $m)) {
                    $contentType = $m[1];
                }
                if (preg_match('/^Content-Length:\s*(\d+)$/i', $h, $m)) {
                    $contentLength = $m[1];
                }
            }

            $filename    = basename((string) parse_url($currentUrl, PHP_URL_PATH)) ?: 'download';
            $disposition = $this->buildContentDisposition($filename);

            $response = $response
                ->withBody(new Stream($streamHandle))
                ->withHeader('Content-Type', $contentType)
                ->withHeader('Content-Disposition', $disposition)
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'no-store');

            if ($contentLength !== null) {
                $response = $response->withHeader('Content-Length', (string) $contentLength);
            }

            return $response;
        }

        if ($sourceType === 'internal') {
            $fileId = (int) ($grant['source_file_id'] ?? 0);
            if ($fileId <= 0) {
                return $this->error($response, 500, 'File not configured.');
            }

            $mediaItem = $this->media->findById($fileId);
            if ($mediaItem === null) {
                return $this->error($response, 404, 'File not found.');
            }

            $uploadDir = realpath(dirname(__DIR__, 3) . '/storage/uploads');
            if ($uploadDir === false) {
                return $this->error($response, 500, 'Storage not accessible.');
            }

            $filePath = realpath($uploadDir . '/' . ltrim($mediaItem->getPath(), '/'));

            // Security: prevent path traversal — file must be inside upload dir
            if ($filePath === false || !str_starts_with($filePath, $uploadDir . DIRECTORY_SEPARATOR)) {
                return $this->error($response, 404, 'File not found.');
            }

            if (!is_file($filePath)) {
                return $this->error($response, 404, 'File not found on disk.');
            }

            $mimeType     = $mediaItem->getMimeType() ?: 'application/octet-stream';
            $originalName = $mediaItem->getOriginalName() ?: basename($filePath);
            $streamHandle = fopen($filePath, 'rb');
            if ($streamHandle === false) {
                return $this->error($response, 500, 'File is not readable.');
            }

            $safeDisposition = $this->buildContentDisposition((string) $originalName);
            $size            = filesize($filePath);

            $response = $response
                ->withBody(new Stream($streamHandle))
                ->withHeader('Content-Type', $mimeType)
                ->withHeader('Content-Disposition', $safeDisposition)
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'no-store');

            if ($size !== false) {
                $response = $response->withHeader('Content-Length', (string) $size);
            }

            return $response;
        }

        return $this->error($response, 500, 'Invalid download configuration.');
    }

    private function error(Response $response, int $code, string $message): Response
    {
        try {
            return $this->twig->render($response->withStatus($code), 'shop/download-error.twig', [
                'message' => $message,
                'code'    => $code,
            ]);
        } catch (\Throwable) {
            $response->getBody()->write('<p>' . htmlspecialchars($message) . '</p>');
            return $response->withStatus($code)->withHeader('Content-Type', 'text/html');
        }
    }

    private function buildContentDisposition(string $filename): string
    {
        $filename = str_replace(["\r", "\n"], '', $filename);
        $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'download.bin';
        $encoded  = rawurlencode($filename);

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $fallback, $encoded);
    }
}
