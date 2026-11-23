<?php

declare(strict_types=1);

namespace ValeApp\Application\Pwa;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Theme\ColorValueValidator;
use ValeApp\Infrastructure\Theme\ThemeManager;

final class PwaSettings
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly ThemeManager $themes,
    ) {
    }

    public function isEnabled(): bool
    {
        return ($this->settings->get('pwa_enabled', '0') ?? '0') === '1' && $this->logo() !== '';
    }

    public function logo(): string
    {
        return trim((string) ($this->settings->get('site_logo', '') ?? ''));
    }

    public function logoUrl(): string
    {
        $logo = $this->logo();
        if (preg_match('~^(https?:)?//~i', $logo) === 1) {
            return $logo;
        }

        return absolute_url($logo);
    }

    public function logoMimeType(): string
    {
        $path = strtolower((string) parse_url($this->logo(), PHP_URL_PATH));

        return match (true) {
            str_ends_with($path, '.svg')  => 'image/svg+xml',
            str_ends_with($path, '.webp') => 'image/webp',
            str_ends_with($path, '.gif')  => 'image/gif',
            str_ends_with($path, '.jpg'),
            str_ends_with($path, '.jpeg') => 'image/jpeg',
            default                       => 'image/png',
        };
    }

    public function hasRasterLogo(): bool
    {
        return $this->logoMimeType() !== 'image/svg+xml';
    }

    public function appName(): string
    {
        $name = trim((string) ($this->settings->get('pwa_app_name', '') ?? ''));
        if ($name === '') {
            $name = trim((string) ($this->settings->get('site_name', '') ?? ''));
        }

        return $name !== '' ? $name : 'Vale';
    }

    public function shortName(): string
    {
        $short = trim((string) ($this->settings->get('pwa_short_name', '') ?? ''));

        return $short !== '' ? $short : $this->appName();
    }

    public function description(): string
    {
        return trim((string) ($this->settings->get('site_tagline', '') ?? ''));
    }

    public function themeColor(): string
    {
        return $this->color('pwa_theme_color', '--theme-primary', '#111827');
    }

    public function backgroundColor(): string
    {
        $color = $this->color('pwa_background_color', '--theme-bg', '');
        if ($color !== '') {
            return $color;
        }

        $surface = $this->themeColors()['--theme-surface'] ?? '';

        return ColorValueValidator::isValid($surface) ? $surface : '#f9fafb';
    }

    private function color(string $key, string $themeVariable, string $fallback): string
    {
        $useTheme = ($this->settings->get('pwa_use_theme_colors', '1') ?? '1') !== '0';
        $stored   = trim((string) ($this->settings->get($key, '') ?? ''));

        if (!$useTheme && ColorValueValidator::isValid($stored) && str_starts_with($stored, '#')) {
            return $stored;
        }

        $themeColor = $this->themeColors()[$themeVariable] ?? '';

        return ColorValueValidator::isValid($themeColor) && str_starts_with($themeColor, '#')
            ? $themeColor
            : $fallback;
    }

    /** @return array<string, string> */
    private function themeColors(): array
    {
        $theme  = (string) ($this->settings->get('active_theme', 'default') ?? 'default');
        $saved  = json_decode((string) ($this->settings->get('theme_' . $theme . '_colors', '') ?? ''), true);
        $colors = [];

        foreach ($this->themes->resolveColorVariables($theme, is_array($saved) ? $saved : []) as $name => $spec) {
            $colors[$name] = (string) ($spec['value'] ?? '');
        }

        return $colors;
    }
}
