<?php

declare(strict_types=1);

namespace ValeApp\Application\Update;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * UpdateService — checks for new Vale releases and applies them.
 *
 * Flow
 * ────
 * 1. check()         -> fetches the remote manifest JSON, stores result in settings.
 * 2. download()      -> streams the ZIP to storage/cache/update/.
 * 3. apply()         -> pre-checks -> maintenance -> signature verify -> backup
 *                      -> extract -> copy files -> migrate -> clear caches -> deactivate maintenance.
 * 4. rollback()      -> restores files + DB from a backup directory.
 *
 * The manifest JSON format (hosted at UPDATE_MANIFEST_URL):
 *
 *   {
 *     "version":           "1.1.0",
 *     "released_at":       "2026-05-15",
 *     "min_php":           "8.2",
 *     "min_extensions":    ["pdo", "mbstring", "gd", "zip", "json", "openssl", "sodium"],
 *     "download_url":      "https://…/vale-1.1.0.zip",
 *     "sha256":            "abcdef…",
 *     "signatures":        { "ed25519": "base64…" },
 *     "signature_message": "sha256:abcdef…",
 *     "requires_migration": false,
 *     "changelog":         ["Added X", "Fixed Y"]
 *   }
 *
 * `requires_migration` is informational: Vale always runs `phinx migrate` after
 * copying files, so the flag only drives the admin warning about schema
 * changes, never the migration itself.
 *     * Protected paths (never overwritten during update):
 *   .env  |  storage/  |  public/uploads/  |  vendor/  |  content/
 */
final class UpdateService
{
    /** Settings key: last known available version (or empty). */
    private const KEY_AVAILABLE = 'update_available_version';
    /** Settings key: full manifest JSON for the latest release. */
    private const KEY_MANIFEST  = 'update_latest_manifest';
    /** Settings key: last successful check (Unix timestamp) — shown as "Last Checked". */
    private const KEY_CHECKED   = 'update_last_checked';
    /** Settings key: last check attempt, success or failure (Unix timestamp) — throttles the 24 h auto check. */
    private const KEY_ATTEMPT   = 'update_last_check_attempt';
    /** Settings key: JSON {message, at} of the last failed check; empty when the last check succeeded. */
    private const KEY_ERROR     = 'update_last_check_error';

    /** Reason recorded by the last fetchManifest() failure. */
    private string $lastCheckError = '';

    /** Minimum seconds between automatic remote checks (24 h). */
    public const int AUTO_CHECK_INTERVAL = 86400;

    /**
     * Paths inside the installation root that are NEVER touched during an update.
     * Compared as prefix of the relative path (normalised to forward slashes).
     */
    private const PROTECTED = [
        '.env',
        'storage/',
        'public/uploads/',
        'vendor/',
        // User-owned plugin code (content/plugins, content/mu-plugins).
        // Updates must never delete or overwrite a site's own extensions.
        'content/',
    ];

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly string $rootDir,
        private readonly string $manifestUrl,
        private readonly ?SignatureVerifier $signatureVerifier = null,
        private readonly ?PreUpdateChecker $preChecker = null,
        private readonly ?BackupService $backupService = null,
        private readonly ?MaintenanceMode $maintenanceMode = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    // ── Version ────────────────────────────────────────────────────────────

    public function getCurrentVersion(): string
    {
        $versionFile = $this->rootDir . '/version.php';
        if (!file_exists($versionFile)) {
            return '0.0.0';
        }
        $v = require $versionFile;
        return is_string($v) ? $v : '0.0.0';
    }

    // ── Remote check ───────────────────────────────────────────────────────

    /**
     * Fetch the remote manifest and persist the result.
     * Returns the manifest array, or null on failure.
     *
     * @return array<string,mixed>|null
     */
    public function check(): ?array
    {
        $this->settings->set(self::KEY_ATTEMPT, (string) time());

        $manifest = $this->fetchManifest();

        if ($manifest === null) {
            // Remember why it failed: the panel shows it, and the "Last Checked"
            // timestamp must not advance on a failed attempt.
            $this->settings->set(self::KEY_ERROR, json_encode([
                'message' => $this->lastCheckError !== '' ? $this->lastCheckError : 'Unknown error.',
                'at'      => time(),
            ], JSON_THROW_ON_ERROR));

            return null;
        }

        $this->settings->set(self::KEY_ERROR, '');
        $this->settings->set(self::KEY_CHECKED, (string) time());
        $this->settings->set(self::KEY_MANIFEST, json_encode($manifest, JSON_THROW_ON_ERROR));

        $latest  = $manifest['version'] ?? '';
        $current = $this->getCurrentVersion();
        if ($latest !== '' && version_compare($latest, $current, '>')) {
            $this->settings->set(self::KEY_AVAILABLE, $latest);
        } else {
            $this->settings->set(self::KEY_AVAILABLE, '');
        }

        return $manifest;
    }

    /**
     * Return the cached manifest (last successful check), or null.
     *
     * @return array<string,mixed>|null
     */
    public function getCachedManifest(): ?array
    {
        $raw = $this->settings->get(self::KEY_MANIFEST, '');
        if ($raw === '' || $raw === null) {
            return null;
        }
        try {
            $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    public function getAvailableVersion(): string
    {
        return $this->settings->get(self::KEY_AVAILABLE, '') ?? '';
    }

    public function isUpdateAvailable(): bool
    {
        $v = $this->getAvailableVersion();
        return $v !== '' && version_compare($v, $this->getCurrentVersion(), '>');
    }

    public function getLastCheckedAt(): ?int
    {
        $ts = $this->settings->get(self::KEY_CHECKED, '');
        return ($ts !== '' && $ts !== null) ? (int) $ts : null;
    }

    /**
     * Details of the last failed check, or null when the last check succeeded.
     *
     * @return array{message: string, at: int}|null
     */
    public function getLastCheckError(): ?array
    {
        $raw = $this->settings->get(self::KEY_ERROR, '');
        if ($raw === '' || $raw === null) {
            return null;
        }

        try {
            $data = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || ($data['message'] ?? '') === '') {
            return null;
        }

        return ['message' => (string) $data['message'], 'at' => (int) ($data['at'] ?? 0)];
    }

    /**
     * Auto checks are throttled by the last *attempt*, so a failing update
     * server is not re-fetched on every cron run / page request.
     */
    public function isDueForAutoCheck(): bool
    {
        $lastAttempt = $this->settings->get(self::KEY_ATTEMPT, '');

        // Installs upgraded from an older version only have the success timestamp.
        if ($lastAttempt === '' || $lastAttempt === null) {
            $lastAttempt = $this->settings->get(self::KEY_CHECKED, '');
        }

        if ($lastAttempt === '' || $lastAttempt === null) {
            return true;
        }

        return (time() - (int) $lastAttempt) >= self::AUTO_CHECK_INTERVAL;
    }

    // ── Download ───────────────────────────────────────────────────────────

    /**
     * Download the release ZIP from $url and save it to the update staging dir.
     * Returns the absolute path to the downloaded file.
     *
     * @throws \RuntimeException on download failure or if ZipArchive is unavailable.
     */
    public function download(string $url, string $expectedSha256 = ''): string
    {
        set_time_limit(0);

        if (!extension_loaded('zip')) {
            throw new \RuntimeException('PHP zip extension is required for updates.');
        }

        if ($expectedSha256 === '') {
            throw new \RuntimeException('Manifest SHA-256 is missing. Update package cannot be trusted.');
        }

        if (!preg_match('/\A[a-f0-9]{64}\z/i', $expectedSha256)) {
            throw new \RuntimeException('Manifest SHA-256 is invalid. Expected 64 hexadecimal characters.');
        }

        $this->validateDownloadUrl($url);

        $dir = $this->rootDir . '/storage/cache/update';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create update staging dir: {$dir}");
        }

        $dest = $dir . '/release-' . uniqid() . '.zip';

        $ctx = stream_context_create([
            'http' => [
                'timeout'         => 120,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'user_agent'      => 'Vale/' . $this->getCurrentVersion(),
            ],
            'ssl'  => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $in = @fopen($url, 'rb', false, $ctx);
        if ($in === false) {
            throw new \RuntimeException("Failed to open download URL: {$url}");
        }

        $out = fopen($dest, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException("Cannot write to: {$dest}");
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        $actual = hash_file('sha256', $dest);
        if (strtolower((string) $actual) !== strtolower($expectedSha256)) {
            unlink($dest);
            throw new \RuntimeException(
                "SHA-256 mismatch. Expected: {$expectedSha256}, got: {$actual}"
            );
        }

        return $dest;
    }

    // ── Apply ──────────────────────────────────────────────────────────────

    /**
     * Extract $zipPath over the installation, skip protected paths, run migrations.
     *
     * @param  array<string,mixed>|null $manifest Manifest for this ZIP (used for pre-checks and backup labelling).
     * @throws \RuntimeException
     */
    public function apply(string $zipPath, ?array $manifest = null): UpdateResult
    {
        set_time_limit(0);

        if (!file_exists($zipPath)) {
            throw new \RuntimeException("Update ZIP not found: {$zipPath}");
        }

        $result  = new UpdateResult();
        $version = $manifest['version'] ?? 'unknown';

        // 1. Pre-update checks ─────────────────────────────────────────
        if ($this->preChecker !== null) {
            $warnings = $this->preChecker->runAll($manifest ?? [], $this->rootDir);
            if (!empty($warnings)) {
                $this->logger->warning('[Update] Pre-check warnings: ' . implode('; ', $warnings));
            }
        }

        // 2. Enter maintenance mode ────────────────────────────────────
        if ($this->maintenanceMode !== null) {
            $this->maintenanceMode->activate(null, 'Vale is being updated. Please check back in a moment.');
            $this->logger->info('[Update] Maintenance mode activated');
        }

        $backupDir = null;

        try {
            // 3. Verify signature ──────────────────────────────────────
            if ($this->signatureVerifier !== null && $manifest !== null) {
                $method                          = $this->signatureVerifier->verify($manifest);
                $result->verifiedSignatureMethod = $method;
                $this->logger->info('[Update] Signature verified via ' . strtoupper($method));
            }

            // 4. Create backup ─────────────────────────────────────────
            if ($this->backupService !== null) {
                $backupDir         = $this->backupService->createBackup($this->getCurrentVersion());
                $result->backupDir = $backupDir;
                $this->logger->info("[Update] Backup created: {$backupDir}");
            }

            // 5. Extract to temp dir ───────────────────────────────────
            $tmpDir = sys_get_temp_dir() . '/vl-update-' . uniqid();
            $zip    = new \ZipArchive();
            $opened = $zip->open($zipPath);
            if ($opened !== true) {
                throw new \RuntimeException("Cannot open ZIP (error code {$opened}): {$zipPath}");
            }
            $zip->extractTo($tmpDir);
            $zip->close();
            $result->extractedTo = $tmpDir;

            // 6. Find the actual root inside the ZIP ───────────────────
            $extractedRoot = $this->findExtractedRoot($tmpDir);

            // 7. Copy files ────────────────────────────────────────────
            $result->copiedFiles = $this->copyTree($extractedRoot, $this->rootDir);

            // 8. Run migrations ────────────────────────────────────────
            $result->migrationOutput = $this->runMigrations();

            // 9. Bump version / clear available flag ───────────────────
            $newVersion = $this->getCurrentVersion();
            $this->settings->set(self::KEY_AVAILABLE, '');
            $result->newVersion = $newVersion;

            // 10. Clear all caches ─────────────────────────────────────
            $result->clearedCacheFiles = $this->clearAllCaches();

            // 11. Cleanup ──────────────────────────────────────────────
            $this->rmdirRecursive($tmpDir);
            @unlink($zipPath);

            $this->logger->info("[Update] Successfully updated to v{$newVersion}");
        } catch (\Throwable $e) {
            $this->logger->error('[Update] Failed: ' . $e->getMessage());

            // Attempt automatic rollback if we have a backup
            if ($backupDir !== null && $this->backupService !== null) {
                try {
                    $this->logger->info('[Update] Attempting automatic rollback…');
                    $this->backupService->rollback($backupDir);
                    $this->logger->info('[Update] Rollback completed');
                    $result->rolledBack = true;
                } catch (\Throwable $rollbackEx) {
                    $this->logger->error('[Update] Rollback also failed: ' . $rollbackEx->getMessage());
                }
            }

            // Deactivate maintenance mode before re-throwing
            if ($this->maintenanceMode !== null) {
                $this->maintenanceMode->deactivate();
            }

            throw $e;
        }

        // 12. Deactivate maintenance mode ─────────────────────────────
        if ($this->maintenanceMode !== null) {
            $this->maintenanceMode->deactivate();
            $this->logger->info('[Update] Maintenance mode deactivated');
        }

        return $result;
    }

    /**
     * Roll back to a previously created backup.
     *
     * @param  string $backupDir Absolute path to backup directory.
     *                           If empty, the most recent backup is used.
     * @param  bool   $dryRun    When true, only report what would be restored without writing anything.
     * @throws \RuntimeException
     */
    public function rollback(string $backupDir = '', string $type = 'all', bool $dryRun = false): void
    {
        if ($this->backupService === null) {
            throw new \RuntimeException('BackupService is not configured. Cannot roll back.');
        }

        if ($backupDir === '') {
            $backups = $this->backupService->listBackups();
            if (empty($backups)) {
                throw new \RuntimeException('No backups found to roll back to.');
            }
            $backupDir = $backups[0]['dir'];
        }

        if ($dryRun) {
            $this->backupService->rollback($backupDir, $type, true);
            return;
        }

        if ($this->maintenanceMode !== null) {
            $this->maintenanceMode->activate(null, 'Vale is rolling back an update. Please check back shortly.');
        }

        try {
            $this->backupService->rollback($backupDir, $type);
            $this->clearAllCaches();
        } finally {
            if ($this->maintenanceMode !== null) {
                $this->maintenanceMode->deactivate();
            }
        }
    }

    // ── Migrations ─────────────────────────────────────────────────────────

    /**
     * Run pending Phinx migrations programmatically.
     * Returns the console output as a string.
     */
    public function runMigrations(): string
    {
        try {
            $app    = new \Phinx\Console\PhinxApplication();
            $app->setAutoExit(false);
            $input  = new \Symfony\Component\Console\Input\ArrayInput([
                'command'         => 'migrate',
                '--configuration' => $this->rootDir . '/phinx.php',
                '--environment'   => 'default',
            ]);
            $output = new \Symfony\Component\Console\Output\BufferedOutput();
            $app->run($input, $output);
            return $output->fetch();
        } catch (\Throwable $e) {
            return 'Migration error: ' . $e->getMessage();
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function fetchManifest(): ?array
    {
        $this->lastCheckError = '';

        if ($this->manifestUrl === '') {
            $this->lastCheckError = 'Update manifest URL is not configured.';

            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout'     => 10,
                'user_agent'  => 'Vale/' . $this->getCurrentVersion(),
            ],
            'ssl'  => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($this->manifestUrl, false, $ctx);
        if ($raw === false || $raw === '') {
            $this->lastCheckError = 'Could not reach the update server.';

            return null;
        }

        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->lastCheckError = 'Update manifest is not valid JSON: ' . $e->getMessage();

            return null;
        }

        if (!is_array($data) || !isset($data['version'])) {
            $this->lastCheckError = 'Update manifest is missing the "version" field.';

            return null;
        }

        return $data;
    }

    /**
     * After extracting a ZIP, the real files may be inside a single top-level
     * subdirectory (e.g. "vale-1.1.0/"). Detect and return that path.
     */
    private function findExtractedRoot(string $tmpDir): string
    {
        $entries = array_values(array_filter(
            scandir($tmpDir) ?: [],
            fn ($e) => $e !== '.' && $e !== '..'
        ));

        if (count($entries) === 1 && is_dir($tmpDir . '/' . $entries[0])) {
            return $tmpDir . '/' . $entries[0];
        }

        return $tmpDir;
    }

    /**
     * Recursively copy $src tree into $dst, skipping protected paths.
     * Returns count of copied files.
     */
    private function copyTree(string $src, string $dst): int
    {
        $count = 0;
        $src   = rtrim($src, '/\\');
        $dst   = rtrim($dst, '/\\');

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $file) {
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($src))), '/');

            if ($this->isProtected($rel)) {
                continue;
            }

            $target = $dst . '/' . $rel;

            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                copy($file->getPathname(), $target);
                $count++;
            }
        }

        return $count;
    }

    private function isProtected(string $rel): bool
    {
        foreach (self::PROTECTED as $prefix) {
            if ($rel === $prefix || str_starts_with($rel, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function clearAllCaches(): int
    {
        $dirs = [
            $this->rootDir . '/storage/cache/pages',
            $this->rootDir . '/storage/cache/data',
            $this->rootDir . '/storage/cache/views',
            $this->rootDir . '/storage/cache/container',
        ];
        $count = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    @unlink($f->getPathname());
                    $count++;
                }
            }
        }
        return $count;
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    /**
     * Only allow HTTPS URLs (and HTTP for localhost in dev) for downloads.
     * Prevents open redirect / SSRF via the download_url field in the manifest.
     */
    private function validateDownloadUrl(string $url): void
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = strtolower($parsed['host'] ?? '');

        $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $appEnv      = $_ENV['APP_ENV'] ?? 'production';

        if ($scheme === 'https') {
            return; // Always allowed
        }

        if ($scheme === 'http' && $isLocalhost && $appEnv !== 'production') {
            return; // Allow http://localhost in dev only
        }

        throw new \RuntimeException(
            "Insecure or disallowed download URL: {$url}. Only HTTPS is allowed."
        );
    }
}
