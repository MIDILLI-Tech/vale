<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

use DirectoryIterator;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * Plugin activation registry.
 *
 * Activation state is a JSON array of slugs in the `enabled_plugins` setting;
 * the code lives in content/plugins/<slug>/. Nothing here writes into core
 * files, so the list survives updates and the code is never overwritten.
 */
final class PluginManager
{
    public const SETTINGS_KEY   = 'enabled_plugins';
    public const GLOBAL_KEY     = 'plugins_enabled';
    public const FAILURES_KEY   = 'plugin_failures';
    public const LIFECYCLE_KEY  = 'plugin_lifecycle';
    public const NAV_HIDDEN_KEY = 'plugin_nav_hidden';
    public const SETTINGS_GROUP = 'plugins';

    private const MAX_MESSAGE_LENGTH = 500;

    private readonly PluginSecurityScanner $securityScanner;

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly string $pluginsDir,
        private readonly string $valeVersion,
        private readonly bool $operatorDisabled = false,
        ?PluginSecurityScanner $securityScanner = null,
    ) {
        $this->securityScanner = $securityScanner ?? new PluginSecurityScanner();
    }

    public function pluginsDir(): string
    {
        return $this->pluginsDir;
    }

    public function valeVersion(): string
    {
        return $this->valeVersion;
    }

    /**
     * Site owner kill switch: `.disabled` marker in content/plugins/ or the
     * VL_DISABLE_PLUGINS env var. Checked before any plugin code runs, so a
     * broken plugin can never lock the operator out of the admin panel.
     */
    public function isDisabledByOperator(): bool
    {
        return $this->operatorDisabled || is_file($this->pluginsDir . '/.disabled');
    }

    /**
     * Admin-panel master switch. Defaults to on when the setting has never
     * been written, so existing installs keep loading plugins after an update.
     * The operator kill switch above still wins over this setting.
     */
    public function isGloballyEnabled(): bool
    {
        return $this->settings->get(self::GLOBAL_KEY, '1') !== '0';
    }

    public function setGloballyEnabled(bool $enabled, bool $dryRun = false): void
    {
        if ($dryRun) {
            return;
        }

        $this->settings->set(self::GLOBAL_KEY, $enabled ? '1' : '0', self::SETTINGS_GROUP, 'boolean');
    }

    /**
     * @return list<string>
     */
    public function getEnabledSlugs(): array
    {
        $decoded = json_decode($this->settings->get(self::SETTINGS_KEY, '[]') ?? '[]', true);
        if (!is_array($decoded)) {
            return [];
        }

        $slugs = [];
        foreach ($decoded as $slug) {
            if (is_string($slug) && PluginManifest::isValidSlug($slug) && !in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * Plugin slugs whose declarative sidebar links are hidden.
     *
     * @return list<string>
     */
    public function getHiddenNavSlugs(): array
    {
        $decoded = json_decode($this->settings->get(self::NAV_HIDDEN_KEY, '[]') ?? '[]', true);
        if (!is_array($decoded)) {
            return [];
        }

        $slugs = [];
        foreach ($decoded as $slug) {
            if (is_string($slug) && PluginManifest::isValidSlug($slug) && !in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    public function setNavHidden(string $slug, bool $hidden, bool $dryRun = false): void
    {
        if (!PluginManifest::isValidSlug($slug)) {
            throw new InvalidPluginException(sprintf('Invalid plugin slug "%s".', $slug));
        }

        $slugs = $this->getHiddenNavSlugs();
        if ($hidden) {
            if (!in_array($slug, $slugs, true)) {
                $slugs[] = $slug;
            }
        } else {
            $slugs = array_values(array_filter(
                $slugs,
                static fn (string $candidate): bool => $candidate !== $slug,
            ));
        }

        if ($dryRun) {
            return;
        }

        $this->settings->set(self::NAV_HIDDEN_KEY, json_encode($slugs), self::SETTINGS_GROUP);
    }

    public function isEnabled(string $slug): bool
    {
        return in_array($slug, $this->getEnabledSlugs(), true);
    }

    public function enable(string $slug, bool $dryRun = false): void
    {
        $manifest = $this->find($slug);
        if ($manifest === null) {
            throw new InvalidPluginException(sprintf('Plugin "%s" is not installed.', $slug));
        }

        $reason = $manifest->incompatibilityReason($this->valeVersion);
        if ($reason !== null) {
            throw new InvalidPluginException($reason);
        }

        $dependency = $this->dependencyProblem($manifest);
        if ($dependency !== null) {
            throw new InvalidPluginException($dependency);
        }

        // Security inspection: check for undeclared capabilities before enabling
        $directory = $this->resolveDirectory($slug);
        if ($directory !== null) {
            $this->securityScanner->assertAllowed($directory, $manifest->capabilities);
        }

        $slugs = $this->getEnabledSlugs();
        if (in_array($slug, $slugs, true)) {
            return;
        }

        $slugs[] = $slug;

        if (!$dryRun) {
            $this->save($slugs);
        }
    }

    public function disable(string $slug, bool $dryRun = false): void
    {
        $slugs = array_values(array_filter(
            $this->getEnabledSlugs(),
            static fn (string $enabled): bool => $enabled !== $slug,
        ));

        if (!$dryRun && $slugs !== $this->getEnabledSlugs()) {
            $this->save($slugs);
        }
    }

    /**
     * @throws InvalidPluginException when the manifest is invalid.
     */
    public function find(string $slug): ?PluginManifest
    {
        $directory = $this->resolveDirectory($slug);
        if ($directory === null) {
            return null;
        }

        return PluginManifest::fromFile($directory . '/plugin.php', $slug);
    }

    /**
     * Why this plugin's `requires_plugin` entries are not satisfied, or null.
     * Enabling is refused on a non-null result; the admin list shows it inline.
     * Dependencies are never auto-enabled — the operator enables them first.
     */
    public function dependencyProblem(PluginManifest $manifest): ?string
    {
        foreach ($manifest->requiresPlugin as $slug => $constraint) {
            $directory = $this->resolveDirectory($slug);
            if ($directory === null) {
                return sprintf('Requires plugin "%s", which is not installed.', $slug);
            }

            try {
                $dependency = PluginManifest::fromFile($directory . '/plugin.php', $slug);
            } catch (\Throwable) {
                return sprintf('Requires plugin "%s", whose manifest is invalid.', $slug);
            }

            if (!$this->isEnabled($slug)) {
                return sprintf('Requires plugin "%s" to be enabled first.', $slug);
            }

            if (!PluginManifest::versionSatisfies($dependency->version, $constraint)) {
                return sprintf(
                    'Requires plugin "%s" %s (running %s).',
                    $slug,
                    (string) $constraint,
                    $dependency->version,
                );
            }
        }

        return null;
    }

    /**
     * Every installed plugin with its activation state; invalid manifests are
     * reported per row instead of aborting the list.
     *
     * @return list<array{slug:string,name:string,version:string,requires_vale:?string,updates:?string,settings:?string,admin:?array<string,mixed>,enabled:bool,error:?string,installed_at:?string,updated_at:?string}>
     */
    public function findAll(): array
    {
        if (!is_dir($this->pluginsDir)) {
            return [];
        }

        $enabled   = $this->getEnabledSlugs();
        $lifecycle = $this->lifecycles();
        $rows      = [];

        foreach (new DirectoryIterator($this->pluginsDir) as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $slug = $item->getFilename();
            if (!PluginManifest::isValidSlug($slug)) {
                continue;
            }

            $manifest = null;
            $error    = null;
            try {
                $manifest = PluginManifest::fromFile($item->getPathname() . '/plugin.php', $slug);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            $rows[] = [
                'slug'               => $slug,
                'name'               => $manifest?->name    ?? $slug,
                'version'            => $manifest?->version ?? '-',
                'description'        => $manifest?->description,
                'requires_vale'      => $manifest?->requiresVale,
                'requires_php'       => $manifest?->requiresPhp,
                'requires_ext'       => $manifest?->requiresExt ?? [],
                'license'            => $manifest?->license,
                'homepage'           => $manifest?->homepage,
                'settings'           => $manifest?->settings,
                'admin'              => $manifest?->admin,
                'updates'            => $manifest?->updates,
                'capabilities'       => $manifest?->capabilities   ?? [],
                'requires_plugin'    => $manifest?->requiresPlugin ?? [],
                'dependency_problem' => $manifest !== null ? $this->dependencyProblem($manifest) : null,
                'enabled'            => in_array($slug, $enabled, true),
                'error'              => $error,
                'installed_at'       => $lifecycle[$slug]['installed_at']
                    ?? $this->fileDate($item->getPathname() . '/plugin.php', 'ctime'),
                'updated_at'         => $lifecycle[$slug]['updated_at']
                    ?? $this->fileDate($item->getPathname() . '/plugin.php', 'mtime'),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return $rows;
    }

    /**
     * Delete an installed plugin's directory. The caller decides whether the
     * plugin's settings, data and migration bookkeeping are removed too.
     *
     * @throws InvalidPluginException when the plugin directory is missing.
     * @throws \RuntimeException when a file or directory cannot be deleted.
     */
    public function deleteFiles(string $slug): void
    {
        $directory = $this->resolveDirectory($slug);
        if ($directory === null) {
            throw new InvalidPluginException(sprintf('Plugin "%s" is not installed.', $slug));
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $path = $item->getPathname();

            if ($item->isLink() || $item->isFile()) {
                if (!unlink($path)) {
                    throw new \RuntimeException(sprintf('Could not delete plugin file "%s".', $path));
                }
                continue;
            }

            if ($item->isDir() && !rmdir($path)) {
                throw new \RuntimeException(sprintf('Could not delete plugin directory "%s".', $path));
            }
        }

        if (!rmdir($directory)) {
            throw new \RuntimeException(sprintf('Could not delete plugin directory "%s".', $directory));
        }

        $this->forgetLifecycle($slug);
    }

    /**
     * Resolve a slug to an existing directory inside the plugin root.
     * Rejects traversal, symlink escapes and unsafe characters.
     */
    public function resolveDirectory(string $slug): ?string
    {
        if (!PluginManifest::isValidSlug($slug)) {
            return null;
        }

        $root = realpath($this->pluginsDir);
        if ($root === false) {
            return null;
        }

        $real = realpath($root . DIRECTORY_SEPARATOR . $slug);
        if ($real === false || !is_dir($real)) {
            return null;
        }

        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * Remember a load failure. Identical consecutive messages are written once,
     * so a plugin that throws on every request does not hammer the settings
     * table (and the admin UI still shows the latest reason).
     */
    public function recordFailure(string $slug, string $message): void
    {
        $message = trim($message);
        if ($message === '') {
            return;
        }
        if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . '…';
        }

        $failures = $this->failures();
        $hash     = substr(sha1($message), 0, 12);

        if (($failures[$slug]['hash'] ?? null) === $hash) {
            return;
        }

        $failures[$slug] = ['message' => $message, 'hash' => $hash, 'at' => time()];

        try {
            $this->settings->set(
                self::FAILURES_KEY,
                json_encode($failures, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                self::SETTINGS_GROUP,
                'json',
            );
        } catch (\Throwable) {
            // The failure log must never break the request it is reporting on.
        }
    }

    /**
     * @return array<string, array{message:string,hash:string,at:int}>
     */
    public function failures(): array
    {
        $decoded = json_decode($this->settings->get(self::FAILURES_KEY, '{}') ?? '{}', true);
        if (!is_array($decoded)) {
            return [];
        }

        $failures = [];
        foreach ($decoded as $slug => $entry) {
            if (!is_string($slug) || !is_array($entry) || !is_string($entry['message'] ?? null)) {
                continue;
            }
            $failures[$slug] = [
                'message' => $entry['message'],
                'hash'    => (string) ($entry['hash'] ?? ''),
                'at'      => (int) ($entry['at'] ?? 0),
            ];
        }

        return $failures;
    }

    public function clearFailure(string $slug): void
    {
        $failures = $this->failures();
        if (!isset($failures[$slug])) {
            return;
        }

        unset($failures[$slug]);

        try {
            $this->settings->set(
                self::FAILURES_KEY,
                json_encode($failures, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                self::SETTINGS_GROUP,
                'json',
            );
        } catch (\Throwable) {
            // Non-fatal: the next successful load retries the cleanup.
        }
    }

    /**
     * @return array{installed_at:?string, updated_at:?string}
     */
    public function lifecycle(string $slug): array
    {
        $entry = $this->lifecycles()[$slug] ?? [];

        return [
            'installed_at' => $entry['installed_at'] ?? null,
            'updated_at'   => $entry['updated_at']   ?? null,
        ];
    }

    public function recordInstalled(string $slug, bool $dryRun = false): void
    {
        if ($dryRun) {
            return;
        }

        $all = $this->lifecycles();
        $now = date('Y-m-d H:i:s');

        $all[$slug] = ['installed_at' => $now, 'updated_at' => $now];
        $this->saveLifecycles($all);
    }

    public function recordUpdated(string $slug, ?string $installedAt = null, bool $dryRun = false): void
    {
        if ($dryRun) {
            return;
        }

        $all = $this->lifecycles();
        $now = date('Y-m-d H:i:s');

        $all[$slug] = [
            'installed_at' => $installedAt ?? $all[$slug]['installed_at'] ?? $now,
            'updated_at'   => $now,
        ];
        $this->saveLifecycles($all);
    }

    public function installedAt(string $slug): ?string
    {
        $recorded = $this->lifecycle($slug)['installed_at'];
        if ($recorded !== null) {
            return $recorded;
        }

        $directory = $this->resolveDirectory($slug);

        return $directory === null ? null : $this->fileDate($directory . '/plugin.php', 'ctime');
    }

    public function forgetLifecycle(string $slug): void
    {
        $all = $this->lifecycles();
        if (!isset($all[$slug])) {
            return;
        }

        unset($all[$slug]);

        try {
            $this->saveLifecycles($all);
        } catch (\Throwable) {
            // The files are already gone; a stale date must not break deletion.
        }
    }

    /**
     * @return array<string, array{installed_at?:string, updated_at?:string}>
     */
    private function lifecycles(): array
    {
        $decoded = json_decode($this->settings->get(self::LIFECYCLE_KEY, '{}') ?? '{}', true);
        if (!is_array($decoded)) {
            return [];
        }

        $lifecycles = [];
        foreach ($decoded as $slug => $entry) {
            if (!is_string($slug) || !PluginManifest::isValidSlug($slug) || !is_array($entry)) {
                continue;
            }

            $record = [];
            foreach (['installed_at', 'updated_at'] as $key) {
                if (is_string($entry[$key] ?? null) && $entry[$key] !== '') {
                    $record[$key] = $entry[$key];
                }
            }

            if ($record !== []) {
                $lifecycles[$slug] = $record;
            }
        }

        return $lifecycles;
    }

    /**
     * @param array<string, array{installed_at?:string, updated_at?:string}> $lifecycles
     */
    private function saveLifecycles(array $lifecycles): void
    {
        $this->settings->set(
            self::LIFECYCLE_KEY,
            json_encode($lifecycles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            self::SETTINGS_GROUP,
            'json',
        );
    }

    private function fileDate(string $file, string $kind): ?string
    {
        $timestamp = $kind === 'ctime' ? @filectime($file) : @filemtime($file);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param list<string> $slugs
     */
    private function save(array $slugs): void
    {
        $this->settings->set(
            self::SETTINGS_KEY,
            json_encode(array_values($slugs), JSON_UNESCAPED_SLASHES) ?: '[]',
            self::SETTINGS_GROUP,
            'json',
        );
    }
}
