<?php

declare(strict_types=1);

namespace ValeApp\Application\Update;

/**
 * Runs pre-flight checks before applying an update.
 *
 * Hard failures throw a RuntimeException; soft failures are collected and
 * returned so callers can log / display them without aborting.
 */
final class PreUpdateChecker
{
    /**
     * Run all checks for a given manifest.
     *
     * @param  array<string,mixed> $manifest
     * @throws \RuntimeException on any hard failure.
     * @return array<string> List of non-fatal warnings.
     */
    public function runAll(array $manifest, string $rootDir): array
    {
        $warnings = [];

        // Load reference-data requirements dynamically
        $refFile = $rootDir . '/config/reference-data.php';
        if (!file_exists($refFile)) {
            throw new \RuntimeException('Reference data configuration file is missing.');
        }
        $refData      = require $refFile;
        $requirements = $refData['requirements'] ?? null;
        if ($requirements === null) {
            throw new \RuntimeException('System requirements are missing from reference data.');
        }

        // ── Hard checks (throw on failure) ─────────────────────────────
        $this->checkSodiumAvailable();
        $this->checkZipExtension();

        // Enforce the system default minimum PHP version or the manifest's min_php, whichever is higher.
        $minPhpFromConfig   = $requirements['min_php'] ?? '8.3.0';
        $minPhpFromManifest = $manifest['min_php']     ?? '8.0';
        $targetMinPhp       = version_compare($minPhpFromConfig, $minPhpFromManifest, '>') ? $minPhpFromConfig : $minPhpFromManifest;
        $this->checkPhpVersion($targetMinPhp);

        // Required extensions from centralized reference data
        $systemRequired                   = array_keys($requirements['required_extensions'] ?? []);
        [$manifestRequired, $extWarnings] = $this->normalizeManifestExtensions($manifest['min_extensions'] ?? []);
        $warnings                         = array_merge($warnings, $extWarnings);
        $allRequired                      = array_values(array_unique(array_merge($systemRequired, $manifestRequired)));

        if (!empty($allRequired)) {
            $missing = $this->getMissingExtensions($allRequired);
            if (!empty($missing)) {
                $manifestMissing = array_values(array_intersect($missing, $manifestRequired));
                $message         = 'This update requires PHP extensions that are not loaded: ' . implode(', ', $missing) . '.';
                if ($manifestMissing !== []) {
                    $message .= ' The update itself requires: ' . implode(', ', $manifestMissing) . '.';
                }
                $message .= ' Install and enable them, then run the update again.';
                throw new \RuntimeException($message);
            }
        }

        // Write-check critical directories
        $writeDirs = [
            $rootDir,
            $rootDir . '/public',
            $rootDir . '/storage',
            $rootDir . '/src',
            $rootDir . '/app',
            $rootDir . '/resources',
            $rootDir . '/config',
        ];
        $nonWritable = $this->getNonWritablePaths($writeDirs);
        if (!empty($nonWritable)) {
            throw new \RuntimeException(
                'The following paths are not writable. Fix permissions before updating: '
                . implode(', ', $nonWritable)
            );
        }

        // ── Soft checks (collect warnings) ────────────────────────────
        if (!$this->checkDiskSpace($rootDir, 104857600)) {
            $warnings[] = 'Less than 100 MB of free disk space available. The update may fail.';
        }

        return $warnings;
    }

    // ── Individual checks ─────────────────────────────────────────────────

    /**
     * @throws \RuntimeException if the sodium extension is not available.
     */
    public function checkSodiumAvailable(): void
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException(
                'The sodium PHP extension is required for signature verification but is not loaded.'
            );
        }
    }

    /**
     * @throws \RuntimeException if the zip extension is not available.
     */
    public function checkZipExtension(): void
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException(
                'The zip PHP extension is required to apply updates but is not loaded.'
            );
        }
    }

    /**
     * @throws \RuntimeException if the running PHP version is below $minVersion.
     */
    public function checkPhpVersion(string $minVersion): void
    {
        if (version_compare(PHP_VERSION, $minVersion, '<')) {
            throw new \RuntimeException(sprintf(
                'This update requires PHP %s or newer (running %s).',
                $minVersion,
                PHP_VERSION
            ));
        }
    }

    /**
     * Normalise the manifest's min_extensions field into a list of extension
     * names. Manifests are third-party input: accept a list of strings (or a
     * comma-separated string), drop anything that is not a plausible extension
     * name, and never throw — malformed entries are surfaced as warnings
     * instead of aborting the update.
     *
     * @param  mixed $raw
     * @return array{0: string[], 1: string[]}
     */
    public function normalizeManifestExtensions(mixed $raw): array
    {
        $warnings   = [];
        $extensions = [];

        if ($raw === null || $raw === '') {
            return [$extensions, $warnings];
        }

        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }

        if (!is_array($raw)) {
            $warnings[] = 'Manifest min_extensions must be a list of extension names; ignored.';
            return [$extensions, $warnings];
        }

        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $name = trim($entry);
            } elseif (is_scalar($entry)) {
                $name = trim((string) $entry);
            } else {
                $warnings[] = 'Ignored a non-string entry in manifest min_extensions.';
                continue;
            }

            if ($name === '') {
                continue;
            }

            // Extension names are lowercase identifiers; reject anything that
            // looks like a path / injection attempt.
            if (!preg_match('/^[a-zA-Z0-9_+-]{1,64}$/', $name)) {
                $warnings[] = "Ignored invalid extension name in manifest min_extensions: {$name}";
                continue;
            }

            $extensions[] = $name;
        }

        return [array_values(array_unique($extensions)), $warnings];
    }

    /**
     * Return any extensions from $required that are not loaded.
     *
     * @param  string[] $required
     * @return string[]
     */
    public function getMissingExtensions(array $required): array
    {
        return array_values(array_filter($required, fn ($ext) => !extension_loaded($ext)));
    }

    /**
     * Check whether at least $bytesNeeded free bytes are available in $path's
     * filesystem.
     */
    public function checkDiskSpace(string $path, int $bytesNeeded = 104857600): bool
    {
        $free = @disk_free_space($path);
        if ($free === false) {
            // Cannot determine — assume OK
            return true;
        }
        return $free >= $bytesNeeded;
    }

    /**
     * Return any paths that are not writable.
     *
     * @param  string[] $paths
     * @return string[]
     */
    public function getNonWritablePaths(array $paths): array
    {
        return array_values(array_filter($paths, fn ($p) => is_dir($p) && !is_writable($p)));
    }
}
