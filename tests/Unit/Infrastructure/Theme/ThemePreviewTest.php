<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Theme;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemePreview;

final class ThemePreviewTest extends TestCase
{
    /** @var \ArrayObject<string, mixed> */
    private \ArrayObject $store;

    private ThemePreview $preview;

    protected function setUp(): void
    {
        $this->store   = new \ArrayObject();
        $this->preview = new ThemePreview($this->sessionWith($this->store), 600);
    }

    /**
     * @param \ArrayObject<string, mixed> $store
     */
    private function sessionWith(\ArrayObject $store): SessionManager
    {
        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $store->offsetExists($key) ? $store[$key] : $default,
        );
        $session->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use ($store): void {
                $store[$key] = $value;
            },
        );
        $session->method('remove')->willReturnCallback(
            static function (string $key) use ($store): void {
                $store->offsetUnset($key);
            },
        );

        return $session;
    }

    public function testStartStoresSlugAndExpiry(): void
    {
        $this->preview->start('nova');

        self::assertSame('nova', $this->store['preview_theme']);
        self::assertGreaterThan(time(), (int) $this->store['preview_theme_expires_at']);
    }

    public function testGetReturnsActivePreviewAndRenewsWindow(): void
    {
        $this->store['preview_theme']            = 'nova';
        $this->store['preview_theme_expires_at'] = time() + 5;

        self::assertSame('nova', $this->preview->get());
        self::assertGreaterThan(time() + 5, (int) $this->store['preview_theme_expires_at']);
    }

    public function testExpiredPreviewIsCleared(): void
    {
        $this->store['preview_theme']            = 'nova';
        $this->store['preview_theme_expires_at'] = time() - 1;

        self::assertSame('', $this->preview->get());
        self::assertFalse($this->preview->isActive());
        self::assertArrayNotHasKey('preview_theme', $this->store);
        self::assertArrayNotHasKey('preview_theme_expires_at', $this->store);
    }

    public function testLegacyPreviewWithoutExpiryGetsAFreshWindow(): void
    {
        $this->store['preview_theme'] = 'dark';

        self::assertSame('dark', $this->preview->get());
        self::assertGreaterThan(time(), (int) $this->store['preview_theme_expires_at']);
    }

    public function testStopClearsPreview(): void
    {
        $this->preview->start('vivid');
        $this->preview->stop();

        self::assertSame('', $this->preview->get());
        self::assertNull($this->preview->expiresAt());
        self::assertSame([], $this->store->getArrayCopy());
    }
}
