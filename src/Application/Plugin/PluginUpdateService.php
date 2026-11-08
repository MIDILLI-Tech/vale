<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;

/**
 * Checks and applies plugin updates.
 *
 * A plugin opts in by pointing its manifest at an updates.json feed:
 *
 *   'updates' => 'https://example.com/plugin/updates.json',
 *
 * Vale owns the risky part: it downloads the package, verifies its SHA-256,
 * checks the feed and the package manifest against the running Vale/PHP/plugin
 * versions, extracts without allowing path traversal, backs up the old folder
 * and restores it when installation or migration fails.
 */
final class PluginUpdateService
{
    public const STATUS_KEY     = 'plugin_update_status';
    public const SETTINGS_GROUP = 'plugins';

    /** Minimum seconds between automatic remote checks (24 h). */
    public const CHECK_INTERVAL_SECONDS = 86400;

    private const LAST_CHECK_KEY = 'plugin_update_last_check';

    private const MAX_MANIFEST_BYTES  = 1024 * 1024;
    private const MAX_ARCHIVE_BYTES   = 50   * 1024 * 1024;
    private const MAX_EXTRACTED_BYTES = 100  * 1024 * 1024;
    private const MAX_ARCHIVE_FILES   = 5000;

    private readonly PluginSecurityScanner $securityScanner;

    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PluginMigrator $migrator,
        private readonly SettingsRepositoryInterface $settings,
        private readonly SafeRemoteFetcher $fetcher,
        private readonly string $workDir,
        private readonly string $backupDir,
        ?PluginSecurityScanner $securityScanner = null,
    ) {
        $this->securityScanner = $securityScanner ?? new PluginSecurityScanner();
    }

    /**
     * Last check result per slug, without touching the network.
     *
     * @return array<string, array<string, mixed>>
     */
    public function status(): array
    {
        $decoded = json_decode($this->settings->get(self::STATUS_KEY, '{}') ?? '{}', true);
        if (!is_array($decoded)) {
            return [];
        }

        $statuses = [];
        foreach ($decoded as $slug => $entry) {
            if (is_string($slug) && PluginManifest::isValidSlug($slug) && is_array($entry)) {
                $statuses[$slug] = $entry;
            }
        }

        return $statuses;
    }

    /**
     * Automatic checks are throttled by the last attempt, so a feed that is
     * temporarily down is not fetched on every cron run.
     */
    public function isDueForCheck(): bool
    {
        $lastAttempt = $this->settings->get(self::LAST_CHECK_KEY, '');

        if ($lastAttempt === '' || $lastAttempt === null) {
            return true;
        }

        return (time() - (int) $lastAttempt) >= self::CHECK_INTERVAL_SECONDS;
    }

    /**
     * Check every installed plugin that declares an update feed.
     *
     * @return array<string, array<string, mixed>>
     */
    public function checkAll(): array
    {
        set_time_limit(0);

        $this->settings->set(
            self::LAST_CHECK_KEY,
            (string) time(),
            self::SETTINGS_GROUP,
            'integer',
        );

        $results = [];
        $seen    = [];

        foreach ($this->plugins->findAll() as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if (!PluginManifest::isValidSlug($slug)) {
                continue;
            }

            $seen[] = $slug;
            $url    = $row['updates'] ?? null;

            if (!is_string($url) || $url === '') {
                $this->forget($slug);
                continue;
            }

            try {
                $results[$slug] = $this->checkOne($slug);
            } catch (\Throwable $e) {
                $results[$slug] = $this->storeError($slug, $e);
            }
        }

        $statuses = $this->status();
        $changed  = false;
        foreach (array_keys($statuses) as $slug) {
            if (!in_array($slug, $seen, true)) {
                unset($statuses[$slug]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->saveStatuses($statuses);
        }

        return $results;
    }

    /**
     * Fetch one plugin's feed and cache the result. No package is downloaded.
     *
     * @return array<string, mixed>
     */
    public function checkOne(string $slug): array
    {
        $manifest = $this->plugins->find($slug);
        if ($manifest === null) {
            throw new InvalidPluginException(sprintf('Plugin "%s" is not installed.', $slug));
        }

        if ($manifest->updates === null) {
            throw new InvalidPluginException(sprintf('Plugin "%s" has no update source.', $slug));
        }

        $release = $this->fetchRelease($slug, $manifest->updates);
        $reason  = $this->compatibilityReason($release);

        $status = $this->statusFromRelease($release, $manifest->version, $reason);
        $this->saveStatus($slug, $status);

        return $status;
    }

    /**
     * Install a plugin from a local ZIP archive. The plugin is installed
     * disabled; an administrator must review and enable it first.
     *
     * @return array{slug:string,name:string,version:string,directory:string}
     */
    public function installFromArchive(string $zipPath, string $originalFilename, bool $dryRun = false): array
    {
        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new InvalidPluginException('The uploaded plugin package could not be read.');
        }

        $size = filesize($zipPath);
        if ($size === false || $size > self::MAX_ARCHIVE_BYTES) {
            throw new InvalidPluginException('The plugin package is too large.');
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to install plugins.');
        }

        $tempDir     = null;
        $destination = null;

        try {
            $tempDir     = $this->createTempDir();
            $packageRoot = $this->extractZip($zipPath, $tempDir);
            $slug        = $this->installSlug($packageRoot, $tempDir, $originalFilename);
            $manifest    = PluginManifest::fromFile($packageRoot . '/plugin.php', $slug);
            $destination = rtrim($this->plugins->pluginsDir(), '/\\') . '/' . $slug;

            // Security inspection: check for undeclared capabilities in the package
            $this->securityScanner->assertAllowed($packageRoot, $manifest->capabilities);

            if (is_dir($destination)) {
                throw new InvalidPluginException(sprintf(
                    'Plugin "%s" is already installed. Use its update feed to update it.',
                    $slug,
                ));
            }

            if ($dryRun) {
                return [
                    'slug'      => $slug,
                    'name'      => $manifest->name,
                    'version'   => $manifest->version,
                    'directory' => $destination,
                ];
            }

            $this->ensureDirectory($this->plugins->pluginsDir());

            try {
                if (!@rename($packageRoot, $destination)) {
                    $this->copyDirectory($packageRoot, $destination);
                    $this->removeDirectory($packageRoot);
                }
            } catch (\Throwable $e) {
                if (is_dir($destination)) {
                    $this->removeDirectory($destination);
                }

                throw new \RuntimeException(
                    'Could not install the plugin files: ' . $e->getMessage(),
                    0,
                    $e,
                );
            }

            $this->clearOpcodeCache();
            $this->plugins->recordInstalled($slug);

            return [
                'slug'      => $slug,
                'name'      => $manifest->name,
                'version'   => $manifest->version,
                'directory' => $destination,
            ];
        } finally {
            if ($tempDir !== null) {
                $this->removeDirectory($tempDir);
            }
        }
    }

    /**
     * Download, verify, install and migrate one update.
     *
     * A package that claims capabilities the installed version does not declare
     * is never applied without approval: without `$acknowledgedVersion` this
     * throws a PluginCapabilitiesChangedException and leaves the files untouched.
     *
     * @param string|null $acknowledgedVersion version whose new capabilities an operator approved
     *
     * @return array{slug:string,from:string,to:string,backup:string,migrations:int}
     *
     * @throws \Throwable when the update fails; the old files are restored first
     *                    whenever the failure happens after the backup.
     */
    public function update(string $slug, ?string $acknowledgedVersion = null): array
    {
        if (!PluginManifest::isValidSlug($slug)) {
            throw new InvalidPluginException(sprintf('Invalid plugin slug "%s".', $slug));
        }

        $manifest = $this->plugins->find($slug);
        if ($manifest === null) {
            throw new InvalidPluginException(sprintf('Plugin "%s" is not installed.', $slug));
        }
        if ($manifest->updates === null) {
            throw new InvalidPluginException(sprintf('Plugin "%s" has no update source.', $slug));
        }

        $release = $this->fetchRelease($slug, $manifest->updates);
        $reason  = $this->compatibilityReason($release);
        if ($reason !== null) {
            throw new InvalidPluginException($reason);
        }
        if (!$release->isNewerThan($manifest->version)) {
            throw new InvalidPluginException(sprintf('Plugin "%s" is already up to date.', $slug));
        }

        $currentDir  = $this->plugins->resolveDirectory($slug);
        $installedAt = $this->plugins->installedAt($slug);
        if ($currentDir === null) {
            throw new InvalidPluginException('Plugin directory is missing.');
        }

        $zipPath    = null;
        $tempDir    = null;
        $backupRoot = null;

        try {
            $zipPath = $this->download($release->downloadUrl, $release->sha256);
            $tempDir = $this->createTempDir();

            $releaseRoot = $this->extractZip($zipPath, $tempDir);
            $newManifest = PluginManifest::fromFile($releaseRoot . '/plugin.php', $slug);

            if (version_compare($newManifest->version, $release->version, '!=')) {
                throw new InvalidPluginException(sprintf(
                    'The package manifest reports v%s but updates.json reports v%s.',
                    $newManifest->version,
                    $release->version,
                ));
            }

            if (version_compare($newManifest->version, $manifest->version, '<=')) {
                throw new InvalidPluginException(sprintf(
                    'The package version v%s is not newer than the installed v%s.',
                    $newManifest->version,
                    $manifest->version,
                ));
            }

            $packageReason = $newManifest->incompatibilityReason($this->plugins->valeVersion());
            if ($packageReason !== null) {
                throw new InvalidPluginException($packageReason);
            }

            // Security inspection: check for undeclared capabilities in the updated package
            $this->securityScanner->assertAllowed($releaseRoot, $newManifest->capabilities);

            // The approval is tied to the exact version that was shown to the
            // operator, so a package that changes in between asks again.
            $addedCapabilities = array_values(array_diff($newManifest->capabilities, $manifest->capabilities));
            if ($addedCapabilities !== [] && $acknowledgedVersion !== $newManifest->version) {
                throw new PluginCapabilitiesChangedException($slug, $newManifest->version, $addedCapabilities);
            }

            $dependencyProblem = $this->plugins->dependencyProblem($newManifest);
            if ($dependencyProblem !== null) {
                throw new InvalidPluginException($dependencyProblem);
            }

            $backupRoot = $this->backup($slug, $currentDir, $manifest->version);
            $this->replaceDirectory($currentDir, $releaseRoot);

            $applied = 0;
            if ($this->plugins->isEnabled($slug)) {
                $applied = count($this->migrator->migrate($slug)['applied']);
            }
        } catch (PluginCapabilitiesChangedException $e) {
            // Not a failure: the update waits for an explicit approval, so it
            // must not land in the plugin's last-error field.
            throw $e;
        } catch (\Throwable $e) {
            if ($backupRoot !== null) {
                try {
                    $this->restoreDirectory($currentDir, $backupRoot . '/plugin');
                    $this->clearOpcodeCache();
                } catch (\Throwable $restoreError) {
                    $this->storeError($slug, $e);
                    throw new \RuntimeException(
                        sprintf(
                            'Plugin update failed: %s. Automatic rollback also failed: %s',
                            $e->getMessage(),
                            $restoreError->getMessage(),
                        ),
                        0,
                        $e,
                    );
                }
            }

            $this->storeError($slug, $e);
            throw $e;
        } finally {
            if ($tempDir !== null) {
                $this->removeDirectory($tempDir);
            }
            if ($zipPath !== null && is_file($zipPath)) {
                @unlink($zipPath);
            }
        }

        $this->clearOpcodeCache();

        $status                 = $this->statusFromRelease($release, $newManifest->version, null);
        $status['available']    = false;
        $status['installed_at'] = date('Y-m-d H:i:s');
        $this->saveStatus($slug, $status);
        $this->plugins->recordUpdated($slug, $installedAt);

        return [
            'slug'       => $slug,
            'from'       => $manifest->version,
            'to'         => $newManifest->version,
            'backup'     => (string) $backupRoot,
            'migrations' => $applied,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statusFromRelease(PluginRelease $release, string $currentVersion, ?string $error): array
    {
        return [
            'slug'            => $release->slug,
            'version'         => $release->version,
            'current_version' => $currentVersion,
            'download_url'    => $release->downloadUrl,
            'sha256'          => $release->sha256,
            'requires_vale'   => $release->requiresVale,
            'requires_php'    => $release->requiresPhp,
            'requires_ext'    => $release->requiresExt,
            'requires_plugin' => $release->requiresPlugin,
            'changelog'       => $release->changelog,
            'checked_at'      => date('Y-m-d H:i:s'),
            'available'       => $error === null && $release->isNewerThan($currentVersion),
            'error'           => $error,
        ];
    }

    private function compatibilityReason(PluginRelease $release): ?string
    {
        $reason = $release->incompatibilityReason($this->plugins->valeVersion());
        if ($reason !== null) {
            return $reason;
        }

        foreach ($release->requiresPlugin as $slug => $constraint) {
            try {
                $dependency = $this->plugins->find($slug);
            } catch (\Throwable) {
                return sprintf('Requires plugin "%s", whose manifest is invalid.', $slug);
            }

            if ($dependency === null) {
                return sprintf('Requires plugin "%s", which is not installed.', $slug);
            }
            if (!$this->plugins->isEnabled($slug)) {
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

    private function fetchRelease(string $slug, string $url): PluginRelease
    {
        $data = $this->fetchJson($url);

        return PluginRelease::fromArray($slug, $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $this->assertSecureUrl($url);

        $response = $this->fetcher->open($url, 'Vale Plugin Updater', 30);
        $stream   = $response['stream'];

        try {
            $body = stream_get_contents($stream, self::MAX_MANIFEST_BYTES + 1);
        } finally {
            fclose($stream);
        }

        if ($body === false || strlen($body) > self::MAX_MANIFEST_BYTES) {
            throw new InvalidPluginException('The update feed is too large.');
        }

        $this->assertSecureUrl($response['url']);

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidPluginException('The update feed is not valid JSON.', 0, $e);
        }

        if (!is_array($data)) {
            throw new InvalidPluginException('The update feed must be a JSON object.');
        }

        return $data;
    }

    /**
     * Download the ZIP to the staging directory and verify its hash.
     */
    private function download(string $url, string $expectedSha256): string
    {
        $this->assertSecureUrl($url);

        if (preg_match('/\A[a-f0-9]{64}\z/i', $expectedSha256) !== 1) {
            throw new InvalidPluginException('The update feed has no valid SHA-256 hash.');
        }

        $this->ensureDirectory($this->workDir);
        $path = rtrim($this->workDir, '/\\') . '/plugin-' . bin2hex(random_bytes(8)) . '.zip';

        $in  = null;
        $out = null;

        try {
            $response = $this->fetcher->open($url, 'Vale Plugin Updater', 120);
            $this->assertSecureUrl($response['url']);

            $in  = $response['stream'];
            $out = fopen($path, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Cannot write the downloaded plugin package.');
            }

            $total = 0;
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw new \RuntimeException('Could not read the downloaded plugin package.');
                }

                $total += strlen($chunk);
                if ($total > self::MAX_ARCHIVE_BYTES) {
                    throw new InvalidPluginException('The plugin package is too large.');
                }

                if (fwrite($out, $chunk) === false) {
                    throw new \RuntimeException('Could not write the downloaded plugin package.');
                }
            }

            fclose($in);
            $in = null;
            fclose($out);
            $out = null;

            $actual = hash_file('sha256', $path);
            if (!is_string($actual) || !hash_equals(strtolower($expectedSha256), strtolower($actual))) {
                throw new InvalidPluginException('The plugin package SHA-256 does not match updates.json.');
            }

            return $path;
        } catch (\Throwable $e) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($path);

            throw $e;
        }
    }

    /**
     * Extract a ZIP entry by entry. No extractTo(): paths are normalised and
     * rejected when they are absolute or contain a parent segment.
     */
    private function extractZip(string $zipPath, string $destination): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('The PHP zip extension is required to install plugin packages.');
        }

        $zip    = new \ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new \RuntimeException(sprintf('Cannot open the plugin package (ZIP error %s).', (string) $opened));
        }

        try {
            $files           = 0;
            $declaredBytes   = 0;
            $extractedBytes  = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (!is_string($name) || $name === '') {
                    throw new InvalidPluginException('The plugin package contains an invalid file name.');
                }

                $normalized = str_replace('\\', '/', $name);
                if (str_contains($normalized, "\0")
                    || str_starts_with($normalized, '/')
                    || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1
                ) {
                    throw new InvalidPluginException('The plugin package contains an unsafe path.');
                }

                $normalized = ltrim($normalized, '/');
                if ($normalized === '' || $normalized === '.') {
                    continue;
                }

                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    throw new InvalidPluginException('The plugin package contains an unreadable entry.');
                }

                if (($stat['size'] ?? 0) > 0 || !str_ends_with($normalized, '/')) {
                    $files++;
                    $declaredBytes += (int) ($stat['size'] ?? 0);
                    if ($files > self::MAX_ARCHIVE_FILES || $declaredBytes > self::MAX_EXTRACTED_BYTES) {
                        throw new InvalidPluginException('The plugin package is too large after extraction.');
                    }
                }

                $target = $destination . '/' . $normalized;

                if (str_ends_with($normalized, '/')) {
                    $this->ensureDirectory($target);
                    continue;
                }

                $opsys      = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && (($attributes >> 16) & 0xF000) === 0xA000
                ) {
                    throw new InvalidPluginException('The plugin package contains a symbolic link.');
                }

                $this->ensureDirectory(dirname($target));

                $in = $zip->getStream($name);
                if (!is_resource($in)) {
                    throw new InvalidPluginException('The plugin package contains an unreadable file.');
                }

                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($in);
                    throw new \RuntimeException('Cannot write an extracted plugin file.');
                }

                // Central-directory sizes are attacker-controlled: enforce the
                // extraction budget on the bytes actually produced as well.
                $remaining = self::MAX_EXTRACTED_BYTES - $extractedBytes;
                $copied    = stream_copy_to_stream($in, $out, $remaining + 1);
                fclose($in);
                fclose($out);

                if ($copied === false) {
                    throw new \RuntimeException('Could not extract a plugin file.');
                }
                if ($copied > $remaining) {
                    throw new InvalidPluginException('The plugin package is too large after extraction.');
                }

                $extractedBytes += $copied;
            }
        } finally {
            $zip->close();
        }

        return $this->findPackageRoot($destination);
    }

    /**
     * Derive the install slug from the package folder, or from the uploaded
     * file name when plugin.php sits at the ZIP root.
     */
    private function installSlug(string $packageRoot, string $extractionRoot, string $originalFilename): string
    {
        $source = $packageRoot === $extractionRoot
            ? pathinfo(basename($originalFilename), PATHINFO_FILENAME)
            : basename($packageRoot);

        $slug = strtolower($source);
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-_');

        if (!PluginManifest::isValidSlug($slug)) {
            throw new InvalidPluginException(
                'The plugin package folder name must be a valid slug (lowercase letters, numbers, hyphens or underscores).',
            );
        }

        return $slug;
    }

    private function findPackageRoot(string $destination): string
    {
        if (is_file($destination . '/plugin.php')) {
            return $destination;
        }

        $directories = array_values(array_filter(
            scandir($destination) ?: [],
            static fn (string $name): bool => $name !== '.' && $name !== '..'
                                                            && is_dir($destination . '/' . $name),
        ));

        if (count($directories) === 1 && is_file($destination . '/' . $directories[0] . '/plugin.php')) {
            return $destination . '/' . $directories[0];
        }

        throw new InvalidPluginException(
            'The package must contain a regular plugin manifest (plugin.php) at its root or inside one top-level folder. '
            . 'Must-use (mu) plugins are single files and cannot be installed from a ZIP package.',
        );
    }

    /**
     * Keep the old plugin as storage/backups/plugins/plugin-<slug>-<stamp>/plugin.
     *
     * @return string backup root
     */
    private function backup(string $slug, string $currentDir, string $version): string
    {
        $this->ensureDirectory($this->backupDir);

        $root = rtrim($this->backupDir, '/\\') . '/plugin-' . $slug . '-' . date('YmdHis') . '-'
            . bin2hex(random_bytes(2));

        try {
            $this->ensureDirectory($root);
            $this->copyDirectory($currentDir, $root . '/plugin');
            file_put_contents($root . '/backup.json', json_encode([
                'slug'    => $slug,
                'version' => $version,
                'date'    => date('Y-m-d H:i:s'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $this->removeDirectory($root);
            throw new \RuntimeException('Could not back up the current plugin files: ' . $e->getMessage(), 0, $e);
        }

        return $root;
    }

    private function replaceDirectory(string $currentDir, string $releaseRoot): void
    {
        $this->removeDirectory($currentDir);

        if (!@rename($releaseRoot, $currentDir)) {
            $this->copyDirectory($releaseRoot, $currentDir);
            $this->removeDirectory($releaseRoot);
        }
    }

    private function restoreDirectory(string $currentDir, string $backupPluginDir): void
    {
        $this->removeDirectory($currentDir);
        $this->copyDirectory($backupPluginDir, $currentDir);
    }

    private function storeError(string $slug, \Throwable $e): array
    {
        $status = [
            'slug'            => $slug,
            'version'         => null,
            'current_version' => $this->currentVersion($slug),
            'checked_at'      => date('Y-m-d H:i:s'),
            'available'       => false,
            'error'           => $e->getMessage(),
        ];

        $this->saveStatus($slug, $status);

        return $status;
    }

    private function currentVersion(string $slug): ?string
    {
        try {
            return $this->plugins->find($slug)?->version;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $status
     */
    private function saveStatus(string $slug, array $status): void
    {
        $statuses         = $this->status();
        $statuses[$slug]  = $status;
        $this->saveStatuses($statuses);
    }

    /**
     * @param array<string, array<string, mixed>> $statuses
     */
    private function saveStatuses(array $statuses): void
    {
        $this->settings->set(
            self::STATUS_KEY,
            json_encode($statuses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            self::SETTINGS_GROUP,
            'json',
        );
    }

    /**
     * Drop a plugin's cached update result (used when deleting plugin data).
     */
    public function forget(string $slug): void
    {
        $statuses = $this->status();
        if (!isset($statuses[$slug])) {
            return;
        }

        unset($statuses[$slug]);
        $this->saveStatuses($statuses);
    }

    private function createTempDir(): string
    {
        $this->ensureDirectory($this->workDir);

        $dir = rtrim($this->workDir, '/\\') . '/extract-' . bin2hex(random_bytes(8));
        $this->ensureDirectory($dir);

        return $dir;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory: %s', $dir));
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException(sprintf('Source directory does not exist: %s', $source));
        }

        $source      = rtrim($source, '/\\');
        $destination = rtrim($destination, '/\\');

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            $relative = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source))), '/');
            $target   = $destination . '/' . $relative;

            if ($item->isDir()) {
                $this->ensureDirectory($target);
                continue;
            }

            $this->ensureDirectory(dirname($target));
            if (!copy($item->getPathname(), $target)) {
                throw new \RuntimeException(sprintf('Cannot copy %s', $relative));
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function clearOpcodeCache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Updates arrive from operator-configured plugin sources. HTTPS is required;
     * HTTP is allowed only for a local development host.
     */
    private function assertSecureUrl(string $url): void
    {
        $parts  = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($scheme === 'https') {
            return;
        }

        $appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production'));
        if ($scheme === 'http'
            && $appEnv !== 'production'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        ) {
            return;
        }

        throw new InvalidPluginException('Plugin update URLs must use HTTPS.');
    }
}
