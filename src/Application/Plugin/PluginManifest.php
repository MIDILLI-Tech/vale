<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

use Closure;
use Composer\Semver\Semver;

/**
 * Validated plugin manifest — the `plugin.php` file inside a plugin directory.
 *
 * The file is a plain PHP include that returns an array (never eval'd):
 *
 *   return [
 *       'name'          => 'My Plugin',
 *       'version'       => '1.0.0',
 *       'author'        => 'Acme',
 *       'description'   => 'One line shown in the plugin list', // optional
 *       'license'       => 'MIT',                           // optional
 *       'homepage'      => 'https://example.com/plugin',    // optional, http(s) only
 *       'requires_plugin' => ['acme-core' => '^1.0', 'acme-utils'], // optional
 *       'requires_vale' => '^0.77',
 *       'requires_php'  => '>=8.3',
 *       'requires_ext'  => ['curl', 'gd'],                 // optional PHP extensions
 *       'priority'      => 10,
 *       'namespace'     => 'ValeApp\\Plugins\\MyPlugin', // optional
 *       'settings'      => '/admin/my-plugin/settings',    // optional link on /admin/plugins
 *       'updates'       => 'https://example.com/plugin/updates.json', // optional update feed
 *       'admin'         => [                              // optional sidebar entry
 *           'label'      => 'My Plugin',                  // required, passed through __()
 *           'href'       => '/admin/my-plugin',           // required, must be /admin*
 *           'group'      => 'group_content',              // optional group id
 *           'permission' => 'settings.view',              // optional RBAC slug
 *           'icon'       => 'M11 4a2 2 0 114 0v1…',       // optional SVG path
 *       ],
 *       'register'      => static function (App $app, ContainerInterface $c): void {
 *           // routes, DI overrides, hooks, migrations…
 *       },
 *   ];
 *
 * The slug is always the directory name — it cannot be chosen by the manifest,
 * so an activation entry can never point outside content/plugins/.
 */
final class PluginManifest
{
    /** Lowercase, filesystem-safe, no path separators or dots. */
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /** Sidebar links never require an icon; fall back to a puzzle piece. */
    private const DEFAULT_ADMIN_ICON = 'M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z';

    /**
     * @param Closure(\Slim\App, \Psr\Container\ContainerInterface): void $register
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $author,
        public readonly ?string $requiresVale,
        public readonly ?string $requiresPhp,
        public readonly int $priority,
        public readonly string $namespace,
        public readonly string $directory,
        public readonly Closure $register,
        /** @var array{label:string, href:string, group:?string, permission:?string, icon:string}|null */
        public readonly ?array $admin = null,
        public readonly ?string $description = null,
        /** @var list<string> */
        public readonly array $requiresExt = [],
        public readonly ?string $license = null,
        public readonly ?string $homepage = null,
        /** @var array<string, string|null> slug => version constraint */
        public readonly array $requiresPlugin = [],
        /** Admin settings screen linked from the plugin list. */
        public readonly ?string $settings = null,
        /** Optional URL of this plugin's updates.json feed. */
        public readonly ?string $updates = null,
        /** @var list<string> */
        public readonly array $capabilities = [],
    ) {
    }

    public static function fromFile(string $file, string $slug): self
    {
        if (!self::isValidSlug($slug)) {
            throw new InvalidPluginException(sprintf('Invalid plugin slug "%s".', $slug));
        }

        if (!is_file($file)) {
            throw new InvalidPluginException(sprintf('Plugin manifest "%s" is missing.', $file));
        }

        try {
            $data = (static fn (string $path) => include $path)($file);
        } catch (\Throwable $e) {
            throw new InvalidPluginException(
                sprintf('Plugin manifest could not be loaded: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        if (!is_array($data)) {
            throw new InvalidPluginException('Plugin manifest must return an array.');
        }

        $register = $data['register'] ?? null;
        if (!is_callable($register)) {
            throw new InvalidPluginException('Plugin manifest requires a callable "register" entry.');
        }

        $requiresPlugin = self::pluginDependencies($data['requires_plugin'] ?? null);
        unset($requiresPlugin[$slug]);

        $name = self::string($data['name'] ?? null) ?? $slug;

        return new self(
            $slug,
            $name,
            self::string($data['version'] ?? null) ?? '0.0.0',
            self::string($data['author'] ?? null),
            self::string($data['requires_vale'] ?? null),
            self::string($data['requires_php'] ?? null),
            max(0, (int) ($data['priority'] ?? 10)),
            self::string($data['namespace'] ?? null) ?? self::namespaceFor($slug),
            dirname($file),
            Closure::fromCallable($register),
            self::adminMenu($data['admin'] ?? null),
            self::string($data['description'] ?? null),
            self::extensions($data['requires_ext'] ?? null),
            self::string($data['license'] ?? null),
            self::url($data['homepage'] ?? null),
            $requiresPlugin,
            self::settingsHref($data['settings'] ?? null),
            self::updateUrl($data['updates'] ?? null),
            self::capabilities($data['capabilities'] ?? null),
        );
    }

    /**
     * Normalize the optional declarative `admin` block into a sidebar link.
     *
     * Tolerant on purpose: a malformed block disables the auto menu entry but
     * never stops the plugin from loading. `href` is restricted to admin paths
     * so a manifest can never point the sidebar at an external site.
     *
     * @return array{label:string, href:string, group:?string, permission:?string, icon:string}|null
     */
    private static function adminMenu(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $label = self::string($value['label'] ?? null);
        $href  = self::string($value['href'] ?? null);

        if ($label === null || $href === null) {
            return null;
        }

        $href = '/' . ltrim($href, '/');
        if ($href !== '/admin' && !str_starts_with($href, '/admin/')) {
            return null;
        }

        $icon = self::string($value['icon'] ?? null);
        if ($icon === null || preg_match('/^[A-Za-z0-9\s.,+\-]+$/', $icon) !== 1) {
            $icon = self::DEFAULT_ADMIN_ICON;
        }

        return [
            'label'      => $label,
            'href'       => $href,
            'group'      => self::string($value['group'] ?? null),
            'permission' => self::string($value['permission'] ?? null),
            'icon'       => $icon,
        ];
    }

    /**
     * Optional settings screen shown as a link on /admin/plugins. Restricted to
     * admin paths, exactly like the declarative sidebar entry.
     */
    private static function settingsHref(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['href'] ?? null;
        }

        $href = self::string($value);
        if ($href === null) {
            return null;
        }

        $href = '/' . ltrim($href, '/');
        if ($href !== '/admin' && !str_starts_with($href, '/admin/')) {
            return null;
        }

        return $href;
    }

    /**
     * Optional update feed. A string or `['url' => '…']` is accepted; the URL
     * is restricted to http(s) exactly like `homepage`.
     */
    private static function updateUrl(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['url'] ?? null;
        }

        return self::url($value);
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    public static function namespaceFor(string $slug): string
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));

        return 'ValeApp\\Plugins\\' . $studly;
    }

    /**
     * Human-readable reason the plugin cannot run here, or null when compatible.
     */
    public function incompatibilityReason(string $valeVersion, ?string $phpVersion = null): ?string
    {
        if ($this->requiresVale !== null && !self::satisfies($valeVersion, $this->requiresVale)) {
            return sprintf('Requires Vale %s (running %s).', $this->requiresVale, $valeVersion);
        }

        if ($this->requiresPhp !== null) {
            $phpVersion ??= PHP_VERSION;
            if (!self::satisfies($phpVersion, $this->requiresPhp)) {
                return sprintf('Requires PHP %s (running %s).', $this->requiresPhp, $phpVersion);
            }
        }

        if ($this->requiresExt !== []) {
            $missing = array_values(array_diff(
                $this->requiresExt,
                array_map('strtolower', get_loaded_extensions()),
            ));
            if ($missing !== []) {
                return sprintf('Requires PHP extension(s): %s.', implode(', ', $missing));
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function capabilities(mixed $value): array
    {
        $names = is_string($value) ? preg_split('/[\s,]+/', $value) : $value;
        if (!is_array($names)) {
            return [];
        }

        $capabilities = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }

            $name    = strtolower(trim($name));
            $isKnown = in_array($name, PluginSecurityScanner::ALL_CAPABILITIES, true);
            if ($name !== '' && $isKnown && !in_array($name, $capabilities, true)) {
                $capabilities[] = $name;
            }
        }

        return $capabilities;
    }

    /**
     * Normalize `requires_ext` to a lowercase, unique list. A comma/space
     * separated string is accepted too; invalid names are ignored rather than
     * failing the whole manifest.
     *
     * @return list<string>
     */
    private static function extensions(mixed $value): array
    {
        $names = is_string($value) ? preg_split('/[\s,]+/', $value) : $value;
        if (!is_array($names)) {
            return [];
        }

        $extensions = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }

            $name = strtolower(trim($name));
            if ($name !== '' && preg_match('/^[a-z0-9_]+$/', $name) === 1 && !in_array($name, $extensions, true)) {
                $extensions[] = $name;
            }
        }

        return $extensions;
    }

    /**
     * Normalize `requires_plugin` to a `slug => constraint` map. Accepts a map
     * (`['acme-core' => '^1.0']`), a list (`['acme-core']`) or a single slug.
     * Invalid/duplicate entries and the plugin's own slug are dropped.
     *
     * @return array<string, string|null>
     */
    private static function pluginDependencies(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $dependencies = [];
        foreach ($value as $slug => $constraint) {
            if (is_int($slug)) {
                $slug       = is_string($constraint) ? trim($constraint) : '';
                $constraint = null;
            } else {
                $slug       = trim((string) $slug);
                $constraint = is_string($constraint) ? trim($constraint) : null;
            }

            if ($slug === '' || !self::isValidSlug($slug)) {
                continue;
            }

            $dependencies[$slug] = $constraint === '' ? null : $constraint;
        }

        return $dependencies;
    }

    /**
     * Does a plugin version satisfy a constraint? A null/empty constraint means
     * "any version".
     */
    public static function versionSatisfies(string $version, ?string $constraint): bool
    {
        return $constraint === null || $constraint === '' || self::satisfies($version, $constraint);
    }

    /**
     * Only absolute http(s) URLs are accepted — a manifest must never turn into
     * a `javascript:` link (or a relative one that resolves into the admin).
     */
    private static function url(mixed $value): ?string
    {
        $url = self::string($value);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($parts['host'] ?? '') === ''
        ) {
            return null;
        }

        return $url;
    }

    private static function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\Throwable) {
            // Plain bounds such as "1.2.3" that Composer's parser rejects.
            return version_compare($version, ltrim($constraint, '=<>'), '>=');
        }
    }

    private static function string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
