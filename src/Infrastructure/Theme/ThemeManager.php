<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Theme;

/**
 * ThemeManager — discovers, loads, and manages theme metadata.
 *
 * Responsibilities:
 *  - Reads the active theme name from settings (group=theme, key=active_theme).
 *  - Builds the Twig template path stack (child -> parent -> core views).
 *  - Exposes theme hooks: header_scripts, footer_scripts, header_widgets, footer_widgets, sidebar_widgets.
 *  - Child theme: When theme.json contains "parent": "name", the parent templates
 *    are included as a fallback layer in Twig.
 */
class ThemeManager
{
    /**
     * Themes that ship with Vale. Update packages replace their files, so the
     * admin template editor warns before a site owner edits one (themes/ is
     * intentionally not a protected path in UpdateService).
     */
    public const BUNDLED_THEMES = ['default', 'dark', 'nova', 'vivid'];

    /** @var array<string, string> Loaded theme metadata from theme.json */
    private array $meta = [];

    /** @var array<string, callable[]> Hook => list of callables registered for that hook */
    private array $hooks = [];

    public function __construct(
        private readonly string $themesPath,
        private readonly string $activeTheme,
    ) {
    }

    public function isBundledTheme(string $slug): bool
    {
        return in_array($slug, self::BUNDLED_THEMES, true);
    }

    /**
     * Build and return the Twig loader path stack.
     * Returns paths with the most-specific (child/active theme) first.
     *
     * @param string $coreViewsPath Path to resources/views (always last fallback)
     * @return list<string>
     */
    public function buildTemplatePaths(string $coreViewsPath): array
    {
        $paths = [$coreViewsPath];

        $parentMeta = $this->getThemeMeta();
        $parentName = $parentMeta['parent'] ?? null;

        // If this theme has a parent, add parent templates as middle layer
        if ($parentName !== null) {
            $parentTemplates = $this->themesPath . '/' . $parentName . '/templates';
            if (is_dir($parentTemplates)) {
                array_unshift($paths, $parentTemplates);
            }
        }

        // Active theme templates (highest priority — overrides parent + core)
        $activeTemplates = $this->themesPath . '/' . $this->activeTheme . '/templates';
        if (is_dir($activeTemplates) && count(array_filter(scandir($activeTemplates) ?: [], fn ($f) => $f !== '.' && $f !== '..' && $f !== '.gitkeep')) > 0) {
            array_unshift($paths, $activeTemplates);
        }

        return $paths;
    }

    /**
     * Return all available themes (directories containing a theme.json).
     *
     * @return array<string, array<string, mixed>>  theme-slug => meta array
     */
    public function discoverThemes(): array
    {
        $themes = [];
        if (!is_dir($this->themesPath)) {
            return $themes;
        }

        foreach (new \DirectoryIterator($this->themesPath) as $item) {
            if (!$item->isDir() || $item->isDot()) {
                continue;
            }

            $jsonFile = $item->getPathname() . '/theme.json';
            if (!file_exists($jsonFile)) {
                continue;
            }

            $meta = json_decode((string) file_get_contents($jsonFile), true);
            if (!is_array($meta)) {
                continue;
            }

            $meta['slug']                 = $item->getFilename();
            $meta['screenshot']           = $this->getThemeScreenshot($item->getFilename());
            $themes[$item->getFilename()] = $meta;
        }

        return $themes;
    }

    /**
     * Get screenshot URL for a theme if it exists.
     */
    private function getThemeScreenshot(string $slug): ?string
    {
        foreach (['jpg', 'webp', 'png'] as $ext) {
            $path = $this->themesPath . '/' . $slug . '/theme.' . $ext;
            if (file_exists($path)) {
                return site_url('/themes/' . $slug . '/theme.' . $ext);
            }
        }
        return null;
    }

    /**
     * Load and return metadata for the active theme.
     *
     * @return array<string, mixed>
     */
    public function getThemeMeta(): array
    {
        if (!empty($this->meta)) {
            return $this->meta;
        }

        $jsonFile = $this->themesPath . '/' . $this->activeTheme . '/theme.json';
        if (file_exists($jsonFile)) {
            $decoded    = json_decode((string) file_get_contents($jsonFile), true);
            $this->meta = is_array($decoded) ? $decoded : [];
        }

        $this->meta['slug'] = $this->activeTheme;

        return $this->meta;
    }

    /**
     * Return the active theme slug.
     */
    public function getActiveTheme(): string
    {
        return $this->activeTheme;
    }

    /**
     * Return the menus defined in the active theme.
     *
     * @return array<string, string> Key => Label (e.g. 'primary' => 'Primary Navigation')
     */
    public function getDefinedMenus(): array
    {
        $meta = $this->getThemeMeta();
        return $meta['menus'] ?? [];
    }

    /**
     * Get customizable color variables schema for a given theme slug.
     *
     * @return array<string, array{label: string, default: string}>
     */
    public function getColorVariablesSchema(string $slug): array
    {
        $jsonFile = $this->themesPath . '/' . $slug . '/theme.json';
        if (file_exists($jsonFile)) {
            $meta = json_decode((string) file_get_contents($jsonFile), true);
            if (is_array($meta) && isset($meta['color_variables']) && is_array($meta['color_variables'])) {
                return $meta['color_variables'];
            }
        }

        return [
            '--theme-primary'         => ['label' => 'Primary Color', 'default' => '#111827'],
            '--theme-primary-hover'   => ['label' => 'Primary Hover', 'default' => '#374151'],
            '--theme-secondary'       => ['label' => 'Secondary Color', 'default' => '#f3f4f6'],
            '--theme-secondary-hover' => ['label' => 'Secondary Hover', 'default' => '#e5e7eb'],
            '--theme-bg'              => ['label' => 'Background Color', 'default' => '#f9fafb'],
            '--theme-surface'         => ['label' => 'Card / Container Background', 'default' => '#ffffff'],
            '--theme-text'            => ['label' => 'Text Color', 'default' => '#111827'],
            '--theme-text-muted'      => ['label' => 'Muted Text Color', 'default' => '#4b5563'],
            '--theme-header-bg'       => ['label' => 'Header Background', 'default' => '#ffffff'],
            '--theme-footer-bg'       => ['label' => 'Footer Background', 'default' => '#ffffff'],
            '--theme-hero-bg'         => ['label' => 'Hero Gradient / Background', 'default' => '#111827'],
            '--theme-accent'          => ['label' => 'Accent / Highlight Color', 'default' => '#2563eb'],
            '--theme-border'          => ['label' => 'Border / Divider Color', 'default' => '#e5e7eb'],
        ];
    }

    /**
     * Merge a theme's color variable schema with saved overrides into a
     * normalized map for client-side color pickers.
     *
     * @param array<string, mixed> $savedColors Saved overrides keyed by CSS variable name
     * @return array<string, array{label: string, value: string}>
     */
    public function resolveColorVariables(string $slug, array $savedColors = []): array
    {
        $resolved = [];
        foreach ($this->getColorVariablesSchema($slug) as $varName => $spec) {
            if (!is_string($varName) || !str_starts_with($varName, '--')) {
                continue;
            }
            foreach ([$savedColors[$varName] ?? null, $spec['default'] ?? null] as $candidate) {
                if (is_string($candidate) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $candidate) === 1) {
                    $resolved[$varName] = [
                        'label' => (string) ($spec['label'] ?? $varName),
                        'value' => $candidate,
                    ];
                    break;
                }
            }
        }

        return $resolved;
    }

    /**
     * Table palette rendered by a theme (cell border colour and header
     * background). Declared in theme.json next to the theme's own table CSS so
     * the admin editor preview can mirror the front end exactly;
     * TableStyleContractTest fails when the two drift apart.
     *
     * @return array{border: string, header_background: string}
     */
    public function getTablePalette(string $slug): array
    {
        $palette = ['border' => '#d1d5db', 'header_background' => '#f3f4f6'];

        $jsonFile = $this->themesPath . '/' . $slug . '/theme.json';
        if (!file_exists($jsonFile)) {
            return $palette;
        }

        $meta   = json_decode((string) file_get_contents($jsonFile), true);
        $tables = is_array($meta) && isset($meta['tables']) && is_array($meta['tables'])
            ? $meta['tables']
            : [];

        foreach (array_keys($palette) as $key) {
            $value = $tables[$key] ?? null;
            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1) {
                $palette[$key] = $value;
            }
        }

        return $palette;
    }

    /**
     * Discover available page template files from the template path stack.
     * Scans page-templates/ subdirectories in: active theme -> parent theme -> core views.
     * Returns an associative array where each key is the template name and the value is
     * an array containing the human‑readable display name and an optional description extracted
     * from a description comment inside the template file.
     *
     * Description comment format (in the Twig file): `{# DESCRIPTION: Your description text here #}`
     * If the comment is not present, the description will be an empty string.
     *
     * @return array<string, array{display: string, description: string}>
     */
    public function discoverPageTemplates(): array
    {
        $coreViewsPath = dirname($this->themesPath) . '/resources/views';
        $paths         = $this->buildTemplatePaths($coreViewsPath);

        $templates = [];
        foreach ($paths as $path) {
            $tplDir = $path . '/page-templates';
            if (!is_dir($tplDir)) {
                continue;
            }
            foreach (new \DirectoryIterator($tplDir) as $item) {
                if ($item->isFile() && $item->getExtension() === 'twig') {
                    $name = $item->getBasename('.twig');
                    if (!isset($templates[$name])) {
                        $display = ucwords(str_replace(['-', '_'], ' ', $name));
                        // Read the file to extract a description comment
                        $description = '';
                        $content     = @file_get_contents($item->getPathname());
                        if ($content !== false) {
                            if (preg_match('/\{#\s*DESCRIPTION:\s*(.+?)\s*#\}/i', $content, $m)) {
                                $description = trim($m[1]);
                            }
                        }
                        $templates[$name] = ['display' => $display, 'description' => $description];
                    }
                }
            }
        }
        // Sort by template name for stable order
        ksort($templates);
        return $templates;
    }

    /**
     * Register a callable for a named hook.
     *
     * @param string   $hook     Hook name (e.g. 'header_scripts', 'footer_widgets')
     * @param callable $callback Called with no arguments, should return an HTML string
     * @param int      $priority Lower runs first (default 10)
     */
    public function addHook(string $hook, callable $callback, int $priority = 10): void
    {
        $this->hooks[$hook][] = ['fn' => $callback, 'priority' => $priority];
    }

    /**
     * Execute all callables registered for $hook and return concatenated output.
     */
    public function doHook(string $hook): string
    {
        if (empty($this->hooks[$hook])) {
            return '';
        }

        $callbacks = $this->hooks[$hook];
        usort($callbacks, fn ($a, $b) => $a['priority'] <=> $b['priority']);

        $output = '';
        foreach ($callbacks as $entry) {
            $output .= (string) ($entry['fn'])();
        }

        return $output;
    }
}
