<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App;

use PHPUnit\Framework\TestCase;
use RuntimeException;

use function __require_app_url;

final class AppUrlRequirementTest extends TestCase
{
    private ?string $previousAppUrl;

    protected function setUp(): void
    {
        $this->previousAppUrl = $_ENV['APP_URL'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->setAppUrl($this->previousAppUrl);
    }

    public function testMissingAppUrlIsRejected(): void
    {
        $this->setAppUrl(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_URL must be set to an absolute URL');

        __require_app_url();
    }

    public function testRelativeAppUrlIsRejected(): void
    {
        $this->setAppUrl('example.com');

        $this->expectException(RuntimeException::class);

        __require_app_url();
    }

    public function testTrailingSlashIsTrimmed(): void
    {
        $this->setAppUrl('https://example.com/vale/');

        self::assertSame('https://example.com/vale', __require_app_url());
    }

    public function testHttpUrlWithPortIsAccepted(): void
    {
        $this->setAppUrl('http://localhost:8080');

        self::assertSame('http://localhost:8080', __require_app_url());
    }

    private function setAppUrl(?string $appUrl): void
    {
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
        putenv('APP_URL');

        if ($appUrl === null) {
            return;
        }

        $_ENV['APP_URL']    = $appUrl;
        $_SERVER['APP_URL'] = $appUrl;
        putenv('APP_URL=' . $appUrl);
    }
}
