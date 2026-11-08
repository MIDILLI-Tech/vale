<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function __require_app_key;

final class AppKeyRequirementTest extends TestCase
{
    private ?string $previousAppKey;

    protected function setUp(): void
    {
        $this->previousAppKey = $_ENV['APP_KEY'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->setAppKey($this->previousAppKey);
    }

    public function testMissingAppKeyIsRejected(): void
    {
        $this->setAppKey(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY must be 64 or 32 hexadecimal characters');

        __require_app_key();
    }

    public function testNonHexadecimalAppKeyIsRejected(): void
    {
        $this->setAppKey(str_repeat('z', 64));

        $this->expectException(RuntimeException::class);

        __require_app_key();
    }

    public function testWrongLengthAppKeyIsRejected(): void
    {
        $this->setAppKey(str_repeat('a', 40));

        $this->expectException(RuntimeException::class);

        __require_app_key();
    }

    public function test64CharacterHexAppKeyIsAccepted(): void
    {
        $key = str_repeat('ab', 32);
        $this->setAppKey($key);

        self::assertSame($key, __require_app_key());
    }

    public function test32CharacterHexAppKeyIsAccepted(): void
    {
        $key = str_repeat('ab', 16);
        $this->setAppKey($key);

        self::assertSame($key, __require_app_key());
    }

    public function testWhitespaceIsTrimmed(): void
    {
        $key = str_repeat('ab', 32);
        $this->setAppKey('  ' . $key . '  ');

        self::assertSame($key, __require_app_key());
    }

    private function setAppKey(?string $appKey): void
    {
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
        putenv('APP_KEY');

        if ($appKey === null) {
            return;
        }

        $_ENV['APP_KEY']    = $appKey;
        $_SERVER['APP_KEY'] = $appKey;
        putenv('APP_KEY=' . $appKey);
    }
}
