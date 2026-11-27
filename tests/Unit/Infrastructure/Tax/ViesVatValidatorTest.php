<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Tax;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use ValeApp\Domain\Shop\ValueObject\VatNumber;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;
use ValeApp\Infrastructure\Tax\ViesVatValidator;

final class ViesVatValidatorTest extends TestCase
{
    public function testValidResponseIsAccepted(): void
    {
        $validator = $this->makeValidator(static function (): array {
            return self::streamResponse(['isValid' => true, 'name' => 'ACME SAS']);
        });

        $result = $validator->validate($this->vat('FRAB123456789'));

        self::assertTrue($result->isValid());
        self::assertSame('ACME SAS', $result->traderName());
    }

    public function testInvalidResponseIsReported(): void
    {
        $validator = $this->makeValidator(static function (): array {
            return self::streamResponse(['isValid' => false]);
        });

        self::assertTrue($validator->validate($this->vat('DE123456789'))->isInvalid());
    }

    public function testTransportFailureReturnsUnavailable(): void
    {
        $validator = $this->makeValidator(static function (): array {
            throw new \RuntimeException('timeout');
        });

        self::assertTrue($validator->validate($this->vat('DE123456789'))->isUnavailable());
    }

    public function testMalformedResponseReturnsUnavailable(): void
    {
        $validator = $this->makeValidator(static function (): array {
            return self::streamResponse(['unexpected' => true]);
        });

        self::assertTrue($validator->validate($this->vat('DE123456789'))->isUnavailable());
    }

    public function testNonEuNumbersAreRejectedWithoutAnHttpCall(): void
    {
        $called    = false;
        $validator = $this->makeValidator(static function () use (&$called): array {
            $called = true;
            return self::streamResponse(['isValid' => true]);
        });

        self::assertTrue($validator->validate($this->vat('GB123456789'))->isInvalid());
        self::assertFalse($called);
    }

    public function testConfiguredEndpointIsUsed(): void
    {
        $requestedUrl = null;
        $cache        = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willReturn(true);

        $validator = new ViesVatValidator(
            new SafeRemoteFetcher(static function (string $url) use (&$requestedUrl): array {
                $requestedUrl = $url;
                return self::streamResponse(['isValid' => true]);
            }),
            $cache,
            'https://ec.europa.eu/custom-vies/%s/%s',
        );

        self::assertTrue($validator->validate($this->vat('DE123456789'))->isValid());
        self::assertSame('https://ec.europa.eu/custom-vies/DE/123456789', $requestedUrl);
    }

    public function testCachedResultSkipsTheHttpCall(): void
    {
        $called  = false;
        $cache   = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(['status' => 'valid', 'trader_name' => 'Cached GmbH']);

        $validator = new ViesVatValidator(
            new SafeRemoteFetcher(static function () use (&$called): array {
                $called = true;
                return self::streamResponse(['isValid' => true]);
            }),
            $cache,
        );

        $result = $validator->validate($this->vat('DE123456789'));

        self::assertTrue($result->isValid());
        self::assertSame('Cached GmbH', $result->traderName());
        self::assertFalse($called);
    }

    /**
     * @param callable(): array{status: int, location: ?string, headers: list<string>, stream: resource} $opener
     */
    private function makeValidator(callable $opener): ViesVatValidator
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willReturn(true);

        return new ViesVatValidator(new SafeRemoteFetcher($opener), $cache);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{status: int, location: ?string, headers: list<string>, stream: resource}
     */
    private static function streamResponse(array $data): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, json_encode($data, JSON_THROW_ON_ERROR));
        rewind($stream);

        return ['status' => 200, 'location' => null, 'headers' => [], 'stream' => $stream];
    }

    private function vat(string $number): VatNumber
    {
        $vat = VatNumber::fromString($number);
        self::assertNotNull($vat);

        return $vat;
    }
}
