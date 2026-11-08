<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Payment;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Payment\HttpTransport;

final class HttpTransportTest extends TestCase
{
    public function testFormatStreamHeadersJoinsWrapperDataLines(): void
    {
        $ref  = new \ReflectionMethod(HttpTransport::class, 'formatStreamHeaders');
        $data = [
            'HTTP/2 200',
            'Server: cloudflare',
            'CF-Ray: abc123',
            42,
        ];

        /** @var string $result */
        $result = $ref->invoke(null, $data);

        $this->assertSame("HTTP/2 200\r\nServer: cloudflare\r\nCF-Ray: abc123\r\n42\r\n", $result);
    }

    public function testFormatStreamHeadersOnEmptyInput(): void
    {
        $ref = new \ReflectionMethod(HttpTransport::class, 'formatStreamHeaders');

        /** @var string $result */
        $result = $ref->invoke(null, []);

        $this->assertSame("\r\n", $result);
    }
}
