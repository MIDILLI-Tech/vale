<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Theme;

use ValeApp\Infrastructure\Session\SessionManager;

/**
 * Session-backed theme preview with a sliding expiry.
 *
 * A forgotten preview stops on its own instead of lingering for the whole
 * session lifetime; every read renews the window so active previewing is
 * never interrupted. Previews stored before this class existed (slug only)
 * are adopted and given a fresh window.
 */
final class ThemePreview
{
    private const SLUG_KEY    = 'preview_theme';
    private const EXPIRES_KEY = 'preview_theme_expires_at';

    /** Default preview lifetime in seconds. */
    public const TTL = 1800;

    public function __construct(
        private readonly SessionManager $session,
        private readonly int $ttl = self::TTL,
    ) {
    }

    public function start(string $slug): void
    {
        $this->session->set(self::SLUG_KEY, $slug);
        $this->session->set(self::EXPIRES_KEY, time() + max(60, $this->ttl));
    }

    public function stop(): void
    {
        $this->session->remove(self::SLUG_KEY);
        $this->session->remove(self::EXPIRES_KEY);
    }

    /**
     * Slug of the theme being previewed, or '' when there is no live preview.
     */
    public function get(): string
    {
        $slug = (string) $this->session->get(self::SLUG_KEY, '');
        if ($slug === '') {
            return '';
        }

        $expiresAt = (int) $this->session->get(self::EXPIRES_KEY, 0);
        if ($expiresAt > 0 && time() >= $expiresAt) {
            $this->stop();
            return '';
        }

        // Sliding window: reading an active preview extends it.
        $this->start($slug);

        return $slug;
    }

    public function isActive(): bool
    {
        return $this->get() !== '';
    }

    public function expiresAt(): ?int
    {
        if ($this->get() === '') {
            return null;
        }

        return (int) $this->session->get(self::EXPIRES_KEY, 0);
    }
}
