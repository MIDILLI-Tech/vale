<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Http\RemoteFetchException;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;

final class SafeRemoteFetcherTest extends TestCase
{
    /**
     * Builds a fetcher whose opener replays the given hop responses (the last
     * one repeats) and records every URL it was asked for.
     *
     * Hostnames are IP literals on purpose: PrivateNetworkGuard blocks
     * unresolvable hostnames, so real DNS names would make these tests depend
     * on network access. 93.184.216.34 (example.com) is a public address.
     *
     * @param  list<array{status: int, location?: ?string, stream?: resource|null}> $hops
     * @param  list<string>                                                         $requested
     */
    private function fetcherFor(array $hops, array &$requested): SafeRemoteFetcher
    {
        $index = 0;

        return new SafeRemoteFetcher(static function (string $url) use ($hops, &$requested, &$index): array {
            $requested[] = $url;
            $hop         = $hops[$index] ?? end($hops);
            $index++;

            return [
                'status'   => $hop['status'],
                'location' => $hop['location'] ?? null,
                'headers'  => ['HTTP/1.1 ' . $hop['status']],
                'stream'   => $hop['stream'] ?? null,
            ];
        });
    }

    private function openExpectingFailure(SafeRemoteFetcher $fetcher, string $url): RemoteFetchException
    {
        try {
            $fetcher->open($url);
        } catch (RemoteFetchException $exception) {
            return $exception;
        }

        self::fail('Expected the fetch of ' . $url . ' to be refused.');
    }

    public function testFollowsRedirectChainAndReturnsFinalStream(): void
    {
        $requested = [];
        $stream    = fopen('php://temp', 'rb+');

        $fetcher = $this->fetcherFor([
            ['status' => 302, 'location' => 'next/step'],
            ['status' => 200, 'stream' => $stream],
        ], $requested);

        $result = $fetcher->open('https://93.184.216.34/a/b/file.zip');

        self::assertSame([
            'https://93.184.216.34/a/b/file.zip',
            'https://93.184.216.34/a/b/next/step',
        ], $requested);
        self::assertSame('https://93.184.216.34/a/b/next/step', $result['url']);
        self::assertSame(200, $result['status']);
        self::assertSame($stream, $result['stream']);
    }

    public function testRedirectToPrivateHostIsBlockedBeforeConnecting(): void
    {
        $requested = [];
        $fetcher   = $this->fetcherFor([
            ['status' => 301, 'location' => 'http://127.0.0.1/internal'],
        ], $requested);

        $exception = $this->openExpectingFailure($fetcher, 'https://93.184.216.34/file.zip');

        self::assertSame(RemoteFetchException::REASON_PRIVATE_HOST, $exception->getReason());
        self::assertSame(['https://93.184.216.34/file.zip'], $requested);
    }

    public function testRedirectToMetadataServiceIsBlocked(): void
    {
        $requested = [];
        $fetcher   = $this->fetcherFor([
            ['status' => 307, 'location' => '//169.254.169.254/latest/meta-data/'],
        ], $requested);

        $exception = $this->openExpectingFailure($fetcher, 'https://93.184.216.34/file.zip');

        self::assertSame(RemoteFetchException::REASON_PRIVATE_HOST, $exception->getReason());
        self::assertCount(1, $requested);
    }

    public function testRedirectLoopStopsAtMaxRedirects(): void
    {
        $requested = [];
        $fetcher   = $this->fetcherFor([
            ['status' => 302, 'location' => '/again'],
        ], $requested);

        $exception = $this->openExpectingFailure($fetcher, 'https://93.184.216.34/file.zip');

        self::assertSame(RemoteFetchException::REASON_TOO_MANY_REDIRECTS, $exception->getReason());
        self::assertCount(SafeRemoteFetcher::MAX_REDIRECTS + 1, $requested);
    }

    public function testNonHttpSchemeIsRefusedWithoutConnecting(): void
    {
        $requested = [];
        $fetcher   = $this->fetcherFor([['status' => 200]], $requested);

        $exception = $this->openExpectingFailure($fetcher, 'ftp://93.184.216.34/file.zip');

        self::assertSame(RemoteFetchException::REASON_INVALID_SCHEME, $exception->getReason());
        self::assertSame([], $requested);
    }

    public function testRedirectToUnsupportedSchemeIsRejected(): void
    {
        $requested = [];
        $fetcher   = $this->fetcherFor([
            ['status' => 302, 'location' => 'ftp://93.184.216.34/x'],
        ], $requested);

        $exception = $this->openExpectingFailure($fetcher, 'https://93.184.216.34/file.zip');

        self::assertSame(RemoteFetchException::REASON_INVALID_REDIRECT, $exception->getReason());
        self::assertCount(1, $requested);
    }

    public function testRedirectWithoutLocationHeaderIsRejected(): void
    {
        $requested = [];
        $fetcher   = $this->fetcherFor([['status' => 301]], $requested);

        $exception = $this->openExpectingFailure($fetcher, 'https://93.184.216.34/file.zip');

        self::assertSame(RemoteFetchException::REASON_INVALID_REDIRECT, $exception->getReason());
    }

    public function testUpstreamErrorStatusRaisesHttpErrorAndClosesTheStream(): void
    {
        $requested = [];
        $stream    = fopen('php://temp', 'rb+');
        $fetcher   = $this->fetcherFor([['status' => 404, 'stream' => $stream]], $requested);

        $exception = $this->openExpectingFailure($fetcher, 'https://93.184.216.34/missing.zip');

        self::assertSame(RemoteFetchException::REASON_HTTP_ERROR, $exception->getReason());
        self::assertSame(404, $exception->getUpstreamStatus());
        self::assertFalse(is_resource($stream));
    }

    public function testUnreachableHostRaisesConnectionFailed(): void
    {
        $requested = [];
        $fetcher   = $this->fetcherFor([['status' => 0]], $requested);

        $exception = $this->openExpectingFailure($fetcher, 'https://93.184.216.34/file.zip');

        self::assertSame(RemoteFetchException::REASON_CONNECTION_FAILED, $exception->getReason());
    }

    public function testPrivateEntryUrlIsRefusedWithoutExplicitWaiver(): void
    {
        $requested = [];
        $fetcher   = $this->fetcherFor([['status' => 200]], $requested);

        $exception = $this->openExpectingFailure($fetcher, 'http://10.0.0.5/photo.jpg');

        self::assertSame(RemoteFetchException::REASON_PRIVATE_HOST, $exception->getReason());
        self::assertSame([], $requested);
    }

    public function testPrivateHostsAreAllowedWhenExplicitlyWaivedForTrustedSources(): void
    {
        $requested = [];
        $stream    = fopen('php://temp', 'rb+');

        $fetcher = new SafeRemoteFetcher(static function (string $url) use (&$requested, $stream): array {
            $requested[] = $url;

            return $url === 'http://192.168.1.50/photo.jpg'
                ? ['status' => 302, 'location' => '/moved/photo.jpg', 'headers' => [], 'stream' => null]
                : ['status' => 200, 'location' => null, 'headers' => [], 'stream' => $stream];
        }, allowPrivateHosts: true);

        $result = $fetcher->open('http://192.168.1.50/photo.jpg');

        // The waiver covers the entry URL and every redirect hop.
        self::assertSame([
            'http://192.168.1.50/photo.jpg',
            'http://192.168.1.50/moved/photo.jpg',
        ], $requested);
        self::assertSame(200, $result['status']);
    }

    #[DataProvider('redirectLocationProvider')]
    public function testResolveRedirectUrl(string $baseUrl, string $location, ?string $expected): void
    {
        self::assertSame($expected, SafeRemoteFetcher::resolveRedirectUrl($baseUrl, $location));
    }

    /** @return array<string, array{0: string, 1: string, 2: ?string}> */
    public static function redirectLocationProvider(): array
    {
        $base = 'https://93.184.216.34/a/b/file.zip';

        return [
            'absolute https'             => [$base, 'https://cdn.example.org/x.zip', 'https://cdn.example.org/x.zip'],
            'absolute http'              => [$base, 'http://cdn.example.org/x.zip', 'http://cdn.example.org/x.zip'],
            'absolute any case'          => [$base, 'HTTPS://cdn.example.org/x.zip', 'HTTPS://cdn.example.org/x.zip'],
            'root relative'              => [$base, '/root.zip', 'https://93.184.216.34/root.zip'],
            'relative sibling'           => [$base, 'other.zip', 'https://93.184.216.34/a/b/other.zip'],
            'relative with query'        => [$base, 'other.zip?v=2', 'https://93.184.216.34/a/b/other.zip?v=2'],
            'protocol relative'          => [$base, '//cdn.example.org/x.zip', 'https://cdn.example.org/x.zip'],
            'query only'                 => [$base, '?v=2', 'https://93.184.216.34/a/b/file.zip?v=2'],
            'port preserved'             => ['https://93.184.216.34:8443/a/f.zip', '/x.zip', 'https://93.184.216.34:8443/x.zip'],
            'root base path'             => ['https://93.184.216.34/file.zip', 'other.zip', 'https://93.184.216.34/other.zip'],
            'no path in base'            => ['https://93.184.216.34', 'other.zip', 'https://93.184.216.34/other.zip'],
            'subdirectory base'          => ['https://93.184.216.34/a/', 'x.zip', 'https://93.184.216.34/a/x.zip'],
            'ftp scheme'                 => [$base, 'ftp://93.184.216.34/x', null],
            'protocol relative bad host' => [$base, '//169.254.169.254/x', 'https://169.254.169.254/x'],
            'empty location'             => [$base, '   ', null],
            'base without host'          => ['not a url', '/x.zip', null],
        ];
    }

    #[DataProvider('responseHeaderProvider')]
    public function testParseResponseHeaders(array $headers, int $expectedStatus, ?string $expectedLocation): void
    {
        self::assertSame([$expectedStatus, $expectedLocation], SafeRemoteFetcher::parseResponseHeaders($headers));
    }

    /** @return array<string, array{0: list<string>, 1: int, 2: ?string}> */
    public static function responseHeaderProvider(): array
    {
        return [
            'redirect with location' => [
                ['HTTP/1.1 301 Moved Permanently', 'Location: https://x.example/y', 'Content-Type: text/html'],
                301,
                'https://x.example/y',
            ],
            'ok without location' => [
                ['HTTP/1.1 200 OK', 'Content-Type: application/zip'],
                200,
                null,
            ],
            'proxy hops reset location' => [
                ['HTTP/1.1 301 Moved Permanently', 'Location: https://x.example/y', 'HTTP/1.1 200 OK'],
                200,
                null,
            ],
            'http/1.0 status line' => [
                ['HTTP/1.0 302 Found', 'Location: /relative'],
                302,
                '/relative',
            ],
            'no status line' => [
                ['Content-Type: application/zip'],
                0,
                null,
            ],
        ];
    }
}
