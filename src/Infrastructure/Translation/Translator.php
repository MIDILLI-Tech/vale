<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Translation;

use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * JSON-based i18n translator.
 *
 * Translation files live in resources/lang/{locale}.json as flat key-value maps.
 * Supports parameter interpolation: "Hello :name" -> "Hello World".
 */
class Translator
{
    private static ?self $instance = null;

    private string $locale;

    private string $defaultLocale;

    /** @var string[] */
    private array $supported;

    /** @var array<string, array<string, string>> Loaded translations per locale */
    private array $loaded = [];

    private string $langDir;

    /**
     * @param string   $langDir       Path to resources/lang/
     * @param string   $locale        Default locale
     * @param string[] $supported     Supported locale codes
     * @param string   $defaultLocale Fallback locale when key is missing (defaults to $locale)
     */
    public function __construct(string $langDir, string $locale, array $supported, string $defaultLocale = 'en')
    {
        $this->langDir       = $langDir;
        $this->supported     = $supported;
        $this->locale        = in_array($locale, $supported, true) ? $locale : 'en';
        $this->defaultLocale = $defaultLocale;
        self::$instance      = $this;
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * Translate a key, with optional parameter replacement.
     *
     * @param string               $key    Translation key (e.g. "auth.login_title")
     * @param array<string, string> $params Replacements (e.g. [':name' => 'World'])
     * @param string|null          $locale Override locale for this call
     */
    public function get(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale ?? $this->locale;

        $translations = $this->load($locale);
        $text         = $translations[$key] ?? null;

        // Fallback to default locale if key missing or value is empty
        if (($text === null || $text === '') && $locale !== $this->defaultLocale) {
            $translations = $this->load($this->defaultLocale);
            $text         = $translations[$key] ?? null;
        }

        // Key itself as last resort
        if ($text === null || $text === '') {
            $text = $key;
        }

        // Parameter interpolation
        if ($params !== []) {
            $text = strtr($text, $params);
        }

        return $text;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        if (in_array($locale, $this->supported, true)) {
            $this->locale = $locale;
        }
    }

    /**
     * Locales the site actively serves: the default locale plus the content
     * languages configured under Settings → Content → Multi-Language.
     *
     * A locale file on disk (resources/lang/*.json) is not enough — a language
     * only becomes active once an admin adds it to Content Languages, so
     * `/tr/...` URLs, hreflang links and localized feeds only exist for
     * configured languages.
     *
     * @return string[]
     */
    public function getSupportedLocales(): array
    {
        $settings = DbalSettingsRepository::getInstance();

        if ($settings === null) {
            return $this->supported;
        }

        $defaultLocale = (string) ($settings->get('default_locale', 'en') ?? 'en');

        if ($settings->get('multi_language_content', '0') !== '1') {
            return [$defaultLocale];
        }

        $raw    = (string) ($settings->get('content_languages', '') ?? '');
        $codes  = array_values(array_filter(array_map(
            static fn (string $code): string => trim($code),
            explode(',', $raw),
        )));

        if ($codes === []) {
            return [$defaultLocale];
        }

        if (!in_array($defaultLocale, $codes, true)) {
            array_unshift($codes, $defaultLocale);
        }

        return array_values(array_unique($codes));
    }

    /**
     * Get supported locales with their display names.
     * @return array<string, string> Map of code => Name
     */
    public function getAvailableLocales(): array
    {
        $codes   = $this->getSupportedLocales();
        $locales = [];
        foreach ($codes as $code) {
            $locales[$code] = extension_loaded('intl')
                ? \Locale::getDisplayLanguage($code, $code)
                : strtoupper($code);
        }
        return $locales;
    }

    /**
     * Locale codes that have a UI translation file, regardless of the content
     * languages configured for the site. Used for interface language choices
     * (admin UI, user profile), which are independent from content languages.
     *
     * @return string[]
     */
    public function getUiLocales(): array
    {
        return array_keys($this->getAllAvailableLocales());
    }

    /**
     * Get all locales from JSON files without filtering by content_languages.
     * Used in the settings panel so admins can add languages back.
     * @return array<string, string> Map of code => Name
     */
    public function getAllAvailableLocales(): array
    {
        $files   = glob($this->langDir . '/*.json');
        $locales = [];
        foreach ($files as $file) {
            $code = basename($file, '.json');
            if ($code !== '' && preg_match('/^[a-z]{2}(-[a-z0-9]+)?$/i', $code)) {
                $locales[$code] = extension_loaded('intl')
                    ? \Locale::getDisplayLanguage($code, $code)
                    : strtoupper($code);
            }
        }
        ksort($locales);
        return $locales;
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * Load a locale's translation file (cached in memory).
     *
     * @return array<string, string>
     */
    private function load(string $locale): array
    {
        if (isset($this->loaded[$locale])) {
            return $this->loaded[$locale];
        }

        $file = $this->langDir . '/' . basename($locale) . '.json';

        if (!is_file($file)) {
            $this->loaded[$locale] = [];
            return [];
        }

        $json = file_get_contents($file);
        $data = json_decode($json, true);

        $this->loaded[$locale] = is_array($data) ? $data : [];

        return $this->loaded[$locale];
    }
}
