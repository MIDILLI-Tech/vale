<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Front\DownloadController;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shop\Repository\DownloadRepositoryInterface;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;

final class DownloadControllerTest extends TestCase
{
    /**
     * Public IP literal on purpose: PrivateNetworkGuard blocks unresolvable
     * hostnames, so a DNS-backed name would make these tests network-dependent.
     */
    private const PUBLIC_FILE_URL = 'https://93.184.216.34/file.zip';

    /** @return array<string, mixed> */
    private function grant(string $token, string $sourceUrl, bool $proxy): array
    {
        return [
            'id'                 => 1,
            'token'              => $token,
            'source_type'        => 'external',
            'source_url'         => $sourceUrl,
            'download_url_proxy' => $proxy,
            'download_limit'     => 0,
            'download_count'     => 0,
            'expires_at'         => null,
            'revoked'            => false,
        ];
    }

    private function serve(
        string $token,
        string $sourceUrl,
        bool $proxy = false,
        ?SafeRemoteFetcher $fetcher = null,
    ): ResponseInterface {
        $downloads = $this->createStub(DownloadRepositoryInterface::class);
        $downloads->method('findGrantByToken')->willReturn($this->grant($token, $sourceUrl, $proxy));

        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            static fn (ResponseInterface $response): ResponseInterface => $response,
        );

        $controller = new DownloadController(
            $downloads,
            $this->createStub(MediaRepositoryInterface::class),
            $twig,
            $fetcher,
        );

        return $controller->serve(
            $this->createStub(ServerRequestInterface::class),
            new Response(),
            ['token' => $token],
        );
    }

    private static function redirectingFetcher(string $location): SafeRemoteFetcher
    {
        return new SafeRemoteFetcher(static fn (string $url): array => [
            'status'   => 301,
            'location' => $location,
            'headers'  => [],
            'stream'   => null,
        ]);
    }

    public function testServeBlocksPrivateHostExternalUrlWithForbiddenStatus(): void
    {
        $response = $this->serve(str_repeat('a', 64), 'http://127.0.0.1/admin-secret');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testServeBlocksMetadataServiceExternalUrl(): void
    {
        $response = $this->serve(str_repeat('b', 64), 'http://169.254.169.254/latest/meta-data/');

        self::assertSame(403, $response->getStatusCode());
    }

    public function testServeWithoutProxyRedirectsToTheExternalUrl(): void
    {
        $response = $this->serve(str_repeat('d', 64), self::PUBLIC_FILE_URL);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(self::PUBLIC_FILE_URL, $response->getHeaderLine('Location'));
    }

    public function testServeProxyFollowsRedirectAndStreamsTheFile(): void
    {
        $token  = str_repeat('e', 64);
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, 'ZIPDATA');
        rewind($stream);

        $initial = self::PUBLIC_FILE_URL;

        $fetcher = new SafeRemoteFetcher(static function (string $url) use ($stream, $initial): array {
            if ($url === $initial) {
                return ['status' => 302, 'location' => 'real/file.zip', 'headers' => [], 'stream' => null];
            }

            return [
                'status'   => 200,
                'location' => null,
                'headers'  => ['HTTP/1.1 200 OK', 'Content-Type: application/zip', 'Content-Length: 7'],
                'stream'   => $stream,
            ];
        });

        $response = $this->serve($token, self::PUBLIC_FILE_URL, true, $fetcher);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('file.zip', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('ZIPDATA', (string) $response->getBody());
    }

    public function testServeProxyDownloadRedirectingToPrivateHostIsBlocked(): void
    {
        $fetcher = self::redirectingFetcher('http://127.0.0.1/internal-secret');

        $response = $this->serve(str_repeat('c', 64), self::PUBLIC_FILE_URL, true, $fetcher);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testServeProxyDownloadRedirectingToMetadataServiceIsBlocked(): void
    {
        $fetcher = self::redirectingFetcher('//169.254.169.254/latest/meta-data/');

        $response = $this->serve(str_repeat('f', 64), self::PUBLIC_FILE_URL, true, $fetcher);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testServeProxyDownloadAbortsOnRedirectLoop(): void
    {
        $fetcher = self::redirectingFetcher('/loop');

        $response = $this->serve(str_repeat('1', 64), self::PUBLIC_FILE_URL, true, $fetcher);

        self::assertSame(502, $response->getStatusCode());
    }

    public function testServeProxyDownloadReportsUpstreamErrorStatus(): void
    {
        $stream  = fopen('php://temp', 'rb+');
        $fetcher = new SafeRemoteFetcher(static fn (string $url): array => [
            'status'   => 404,
            'location' => null,
            'headers'  => ['HTTP/1.1 404 Not Found'],
            'stream'   => $stream,
        ]);

        $response = $this->serve(str_repeat('2', 64), self::PUBLIC_FILE_URL, true, $fetcher);

        self::assertSame(502, $response->getStatusCode());
        self::assertFalse(is_resource($stream));
    }
}
