<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Helpers;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ClientIpTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        parent::tearDown();
    }

    public function testIsTrustedProxyMatchingExactIp(): void
    {
        self::assertTrue(vl_is_trusted_proxy('10.0.0.1', '10.0.0.1'));
        self::assertFalse(vl_is_trusted_proxy('10.0.0.2', '10.0.0.1'));
    }

    public function testIsTrustedProxyMatchingWildcards(): void
    {
        self::assertTrue(vl_is_trusted_proxy('192.168.1.50', '*'));
        self::assertTrue(vl_is_trusted_proxy('10.0.0.5', '0.0.0.0/0'));
        self::assertTrue(vl_is_trusted_proxy('2001:db8::1', '::/0'));
    }

    public function testIsTrustedProxyMatchingIpv4Cidr(): void
    {
        $config = '127.0.0.1, 10.0.0.0/8, 172.16.0.0/12';

        self::assertTrue(vl_is_trusted_proxy('127.0.0.1', $config));
        self::assertTrue(vl_is_trusted_proxy('10.255.0.1', $config));
        self::assertTrue(vl_is_trusted_proxy('172.16.5.10', $config));

        self::assertFalse(vl_is_trusted_proxy('203.0.113.195', $config));
        self::assertFalse(vl_is_trusted_proxy('172.32.0.1', $config));
    }

    public function testIsTrustedProxyMatchingIpv6Cidr(): void
    {
        $config = '2001:db8::/32';

        self::assertTrue(vl_is_trusted_proxy('2001:db8::1', $config));
        self::assertTrue(vl_is_trusted_proxy('2001:db8:ffff:ffff::1', $config));
        self::assertFalse(vl_is_trusted_proxy('2001:db9::1', $config));
    }

    public function testClientIpIgnoresHeadersFromUntrustedConnection(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8, 127.0.0.1';

        $request = $this->createStubRequest('203.0.113.50', [
            'CF-Connecting-IP' => '1.1.1.1',
            'X-Forwarded-For'  => '2.2.2.2',
        ]);

        // Untrusted connection (203.0.113.50 is not in TRUSTED_PROXIES) must return REMOTE_ADDR
        self::assertSame('203.0.113.50', vl_client_ip($request));
    }

    public function testClientIpParsesHeadersFromTrustedConnection(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';

        $request1 = $this->createStubRequest('10.0.0.1', [
            'CF-Connecting-IP' => '198.51.100.10',
            'X-Forwarded-For'  => '198.51.100.20',
        ]);
        // Prefers CF-Connecting-IP
        self::assertSame('198.51.100.10', vl_client_ip($request1));

        $request2 = $this->createStubRequest('10.0.0.1', [
            'X-Forwarded-For' => '198.51.100.20, 10.0.0.1',
        ]);
        // Falls back to first client IP in X-Forwarded-For
        self::assertSame('198.51.100.20', vl_client_ip($request2));
    }

    public function testClientIpFallbackWhenNoHeadersPresent(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '*';

        $request = $this->createStubRequest('192.168.1.100', []);

        self::assertSame('192.168.1.100', vl_client_ip($request));
    }

    private function createStubRequest(string $remoteAddr, array $headers): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $remoteAddr]);

        $request->method('getHeaderLine')->willReturnCallback(function (string $name) use ($headers): string {
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return (string) $v;
                }
            }
            return '';
        });

        return $request;
    }
}
