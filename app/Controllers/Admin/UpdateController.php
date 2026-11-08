<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Views\Twig;
use ValeApp\Application\System\SystemRequirementHelper;
use ValeApp\Application\Update\BackupService;
use ValeApp\Application\Update\UpdateService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Admin — Updates
 *
 * One page, two client-side tabs: "Updates" and "Backups". Both panels are
 * rendered together — switching is instant — and the active tab is chosen by
 * ?tab=backups (deep-linkable, survives reload).
 *
 * Routes:
 *   GET  /admin/update                — updates page (?tab=backups opens Backups)
 *   GET  /admin/update/backups        — route alias for the Backups tab
 *   POST /admin/update/check          — trigger remote manifest fetch (AJAX or form)
 *   POST /admin/update/apply          — download + apply the pending release
 *   POST /admin/update/backup         — create a new backup now
 *   POST /admin/update/rollback       — restore a backup
 *   POST /admin/update/backup/delete  — delete a backup
 */
final class UpdateController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly UpdateService $updater,
        private readonly SessionManager $session,
        private readonly ?BackupService $backupService = null,
        private readonly ?DbalSettingsRepository $settings = null,
    ) {
    }

    // ── Index (Updates + Backups tabs) ─────────────────────────────────────

    public function index(Request $request, Response $response): Response
    {
        return $this->renderPage($response, $this->resolveTab($request));
    }

    /** ?tab=backup / ?tab=backups opens the Backups tab; anything else opens Updates. */
    private function resolveTab(Request $request): string
    {
        $tab = strtolower(trim((string) ($request->getQueryParams()['tab'] ?? '')));

        return in_array($tab, ['backup', 'backups'], true) ? 'backups' : 'updates';
    }

    private function renderPage(Response $response, string $activeTab): Response
    {
        $manifest             = $this->updater->getCachedManifest();
        $lastChecked          = $this->updater->getLastCheckedAt();
        $signatureRequired    = $this->isSignatureRequired();
        $manifestHasSignature = $this->manifestHasSignature($manifest);
        $manifestHasSha256    = $this->manifestHasSha256($manifest);

        // System requirements check from config/reference-data.php
        $referenceData   = require dirname(__DIR__, 3) . '/config/reference-data.php';
        $requirements    = $referenceData['requirements']                   ?? [];
        $minPhp          = $requirements['min_php']                         ?? '8.3.0';
        $requiredExts    = $requirements['required_extensions']             ?? [];
        $recommendedExts = $requirements['recommended_extensions']          ?? [];

        $missingRequiredPhp  = version_compare(PHP_VERSION, $minPhp, '<') ? $minPhp : null;
        $missingRequiredExts = [];
        foreach ($requiredExts as $ext => $desc) {
            if (!extension_loaded($ext)) {
                $missingRequiredExts[$ext] = $desc;
            }
        }
        $missingRecommendedExts = SystemRequirementHelper::missingRecommended(
            $recommendedExts,
            ($this->settings?->get('shop_enabled', '1') ?? '1') === '1',
        );

        return $this->twig->render($response, 'admin/update/index.twig', [
            'current_version'          => $this->updater->getCurrentVersion(),
            'available_version'        => $this->updater->getAvailableVersion(),
            'update_available'         => $this->updater->isUpdateAvailable(),
            'manifest'                 => $manifest,
            'last_checked_at'          => $lastChecked,
            'check_error'              => $this->updater->getLastCheckError(),
            'manifest_url'             => $_ENV['UPDATE_MANIFEST_URL'] ?? '',
            'is_baked'                 => is_file(dirname(__DIR__, 3) . '/storage/.baked'),
            'active_tab'               => $activeTab,
            'backups'                  => $this->backupService?->listBackups()    ?? [],
            'zip_supported'            => $this->backupService?->isZipSupported() ?? false,
            'signature_required'       => $signatureRequired,
            'manifest_has_signature'   => $manifestHasSignature,
            'manifest_has_sha256'      => $manifestHasSha256,
            'missing_required_php'     => $missingRequiredPhp,
            'missing_required_exts'    => $missingRequiredExts,
            'missing_recommended_exts' => $missingRecommendedExts,
        ]);
    }

    // ── Check now (POST) ───────────────────────────────────────────────────

    public function checkNow(Request $request, Response $response): Response
    {
        $manifest = $this->updater->check();

        if (
            $request->getHeaderLine('Accept') === 'application/json'
            || str_contains($request->getHeaderLine('Content-Type'), 'json')
        ) {
            $payload = json_encode([
                'ok'                => $manifest !== null,
                'current_version'   => $this->updater->getCurrentVersion(),
                'available_version' => $this->updater->getAvailableVersion(),
                'update_available'  => $this->updater->isUpdateAvailable(),
                'manifest'          => $manifest,
            ], JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json');
        }

        if ($manifest === null) {
            $this->session->flash('error', 'Could not reach the update server. Check UPDATE_MANIFEST_URL in .env.');
        } elseif ($this->updater->isUpdateAvailable()) {
            $this->session->flash('success', 'Update available: v' . $this->updater->getAvailableVersion());
        } else {
            $this->session->flash('success', 'You are running the latest version.');
        }

        return $response
            ->withHeader('Location', '/admin/update')
            ->withStatus(302);
    }

    // ── Apply (POST) ───────────────────────────────────────────────────────

    public function apply(Request $request, Response $response): Response
    {
        $manifest = $this->updater->getCachedManifest();

        if ($manifest === null || !$this->updater->isUpdateAvailable()) {
            $this->session->flash('error', 'No update is available. Run "Check now" first.');
            return $response->withHeader('Location', '/admin/update')->withStatus(302);
        }

        $downloadUrl    = $manifest['download_url'] ?? '';
        $expectedSha256 = $manifest['sha256']       ?? '';

        if ($downloadUrl === '') {
            $this->session->flash('error', 'Manifest does not contain a download_url.');
            return $response->withHeader('Location', '/admin/update')->withStatus(302);
        }

        if ($this->isSignatureRequired() && !$this->manifestHasSignature($manifest)) {
            $this->session->flash('error', $this->translator->get('Update manifest has no signature. Installation is blocked because signature verification is required.'));
            return $response->withHeader('Location', '/admin/update')->withStatus(302);
        }

        if (!$this->manifestHasSha256($manifest)) {
            $this->session->flash('error', 'Update manifest SHA-256 is missing or invalid. Installation is blocked.');
            return $response->withHeader('Location', '/admin/update')->withStatus(302);
        }

        try {
            // Download
            $zipPath = $this->updater->download($downloadUrl, $expectedSha256);

            // Apply (copies files, runs migrations, clears caches)
            $result = $this->updater->apply($zipPath, $manifest);

            $msg = $this->translator->get(
                'Successfully updated to v:version. :files files replaced, :cache cache files cleared.',
                [
                    ':version' => $result->newVersion,
                    ':files'   => (string) $result->copiedFiles,
                    ':cache'   => (string) $result->clearedCacheFiles,
                ]
            );
            if ($result->backupDir !== '') {
                $msg .= ' Backup saved at: ' . basename($result->backupDir) . '.';
            }
            if ($result->verifiedSignatureMethod !== '' && $result->verifiedSignatureMethod !== 'none') {
                $msg .= ' Signature verified via: ' . strtoupper($result->verifiedSignatureMethod) . '.';
            }
            $this->session->flash('success', $msg);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Update failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $response->withHeader('Location', '/admin/update')->withStatus(302);
    }

    // ── Backups tab (GET) ──────────────────────────────────────────────────

    /** Route alias for the Backups tab — renders the same page with that tab active. */
    public function backups(Request $request, Response $response): Response
    {
        return $this->renderPage($response, 'backups');
    }

    // ── Create backup now (POST) ───────────────────────────────────────────

    public function createBackupNow(Request $request, Response $response): Response
    {
        $isAjax = $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($this->backupService === null) {
            if ($isAjax) {
                return $this->jsonResponse($response, ['ok' => false, 'error' => 'BackupService is not configured.']);
            }
            $this->session->flash('error', 'BackupService is not configured.');
            return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
        }

        try {
            $body      = (array) ($request->getParsedBody() ?? []);
            $quick     = !empty($body['quick']);
            $backupDir = $this->backupService->createBackup($this->updater->getCurrentVersion(), $quick);
            $this->session->flash('success', 'Backup created: ' . basename($backupDir));
            if ($isAjax) {
                return $this->jsonResponse($response, ['ok' => true, 'dir' => basename($backupDir), 'quick' => $quick]);
            }
        } catch (\Throwable $e) {
            if ($isAjax) {
                return $this->jsonResponse($response, ['ok' => false, 'error' => $e->getMessage()]);
            }
            $this->session->flash('error', 'Backup failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
    }

    private function jsonResponse(Response $response, array $data): Response
    {
        $response->getBody()->write((string) json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── Rollback (POST) ────────────────────────────────────────────────────

    public function rollback(Request $request, Response $response): Response
    {
        $body        = (array) ($request->getParsedBody() ?? []);
        $backupDir   = trim((string) ($body['backup_dir'] ?? ''));
        $restoreType = in_array($body['restore_type'] ?? '', ['files', 'database'], true)
            ? $body['restore_type']
            : 'all';

        if ($backupDir !== '' && !$this->isAllowedBackupDir($backupDir)) {
            $this->session->flash('error', 'Invalid backup directory.');
            return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
        }

        try {
            $this->updater->rollback($backupDir, $restoreType);
            $this->session->flash('success', 'Rollback completed successfully.');
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Rollback failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
    }

    public function downloadBackup(Request $request, Response $response): Response
    {
        $backupDir = $request->getQueryParams()['backup_dir'] ?? '';

        if ($backupDir === '' || !$this->isAllowedBackupDir($backupDir)) {
            $this->session->flash('error', 'Invalid backup directory.');
            return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
        }

        if ($this->backupService === null || !is_dir($backupDir)) {
            $this->session->flash('error', 'Backup not found.');
            return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
        }

        try {
            $zipPath  = $this->backupService->zipBackup($backupDir);
            $filename = basename($zipPath);

            $stream    = fopen($zipPath, 'rb');
            $psrStream = (new StreamFactory())->createStreamFromResource($stream);

            return $response
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withBody($psrStream);
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Download failed: ' . $e->getMessage());
            return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
        }
    }

    // ── Delete backup (POST) ───────────────────────────────────────────────

    public function deleteBackup(Request $request, Response $response): Response
    {
        $body       = (array) ($request->getParsedBody() ?? []);
        $backupDir  = trim((string) ($body['backup_dir'] ?? ''));
        $deleteType = in_array($body['delete_type'] ?? '', ['files', 'database'], true)
            ? $body['delete_type']
            : 'all';

        if ($backupDir === '' || !$this->isAllowedBackupDir($backupDir)) {
            $this->session->flash('error', 'Invalid backup directory.');
            return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
        }

        if ($this->backupService === null || !is_dir($backupDir)) {
            $this->session->flash('error', 'Backup not found.');
            return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
        }

        try {
            if ($deleteType === 'all') {
                $this->deleteDirectoryRecursive($backupDir);
            } else {
                $metaFile = $backupDir . '/backup.json';
                $meta     = file_exists($metaFile) ? json_decode(file_get_contents($metaFile) ?: '{}', true) ?? [] : [];

                if ($deleteType === 'files') {
                    $filesDir = $backupDir . '/files';
                    if (is_dir($filesDir)) {
                        $this->deleteDirectoryRecursive($filesDir);
                    }
                    $meta['file_count'] = 0;
                } elseif ($deleteType === 'database') {
                    $sqlPath = $backupDir . '/database.sql';
                    if (file_exists($sqlPath)) {
                        unlink($sqlPath);
                    }
                    $meta['db_size'] = 0;
                }

                $filesDir2   = $backupDir . '/files';
                $sqlAbsent   = !file_exists($backupDir . '/database.sql');
                $filesAbsent = !is_dir($filesDir2)
                    || !(new \FilesystemIterator($filesDir2, \FilesystemIterator::SKIP_DOTS))->valid();
                if ($sqlAbsent && $filesAbsent) {
                    $this->deleteDirectoryRecursive($backupDir);
                } else {
                    file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
                }
            }
            $this->session->flash('success', 'Backup deleted: ' . basename($backupDir));
        } catch (\Throwable $e) {
            $this->session->flash('error', 'Delete failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $response->withHeader('Location', '/admin/update?tab=backups')->withStatus(302);
    }

    private function deleteDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    private function isAllowedBackupDir(string $backupDir): bool
    {
        $base = realpath(dirname(__DIR__, 3) . '/storage/backups');
        $real = realpath($backupDir);

        if ($base === false || $real === false || !is_dir($real)) {
            return false;
        }

        $base = rtrim(str_replace('\\', '/', $base), '/');
        $real = rtrim(str_replace('\\', '/', $real), '/');

        return $real === $base || str_starts_with($real, $base . '/');
    }

    private function isSignatureRequired(): bool
    {
        $raw = strtolower(trim((string) ($_ENV['UPDATE_REQUIRE_SIGNATURE'] ?? 'true')));
        return !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }

    /** @param array<string,mixed>|null $manifest */
    private function manifestHasSignature(?array $manifest): bool
    {
        if ($manifest === null) {
            return false;
        }

        $signatures = $manifest['signatures'] ?? null;
        if (!is_array($signatures) || empty($signatures)) {
            return false;
        }

        return !empty($signatures['ed25519']) || !empty($signatures['gpg']);
    }

    /** @param array<string,mixed>|null $manifest */
    private function manifestHasSha256(?array $manifest): bool
    {
        if ($manifest === null) {
            return false;
        }

        $sha = trim((string) ($manifest['sha256'] ?? ''));
        return $sha !== '' && preg_match('/\A[a-f0-9]{64}\z/i', $sha) === 1;
    }
}
