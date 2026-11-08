<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

use Composer\Semver\Semver;

/**
 * One release entry from a plugin's updates.json feed.
 *
 * The feed is deliberately explicit: the download URL and package hash are
 * mandatory, and the package's own plugin.php is checked again after download.
 * Requirements mirror the manifest keys so a feed can warn before downloading:
 *
 *   {
 *     "version": "1.2.0",
 *     "download_url": "https://example.com/plugin-1.2.0.zip",
 *     "sha256": "64 lowercase hex characters",
 *     "requires_vale": "^0.77",
 *     "requires_php": ">=8.3",
 *     "requires_ext": ["curl"],
 *     "requires_plugin": { "acme-core": "^1.0" },
 *     "changelog": ["Fixed …"]
 *   }
 */
final class PluginRelease
{
    private const VERSION_PATTERN = '/^[0-9]+(?:\.[0-9]+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/';

    /**
     * @param list<string> $requiresExt
     * @param array<string, string|null> $requiresPlugin
     * @param list<string> $changelog
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $version,
        public readonly string $downloadUrl,
        public readonly string $sha256,
        public readonly ?string $requiresVale,
        public readonly ?string $requiresPhp,
        public readonly array $requiresExt,
        public readonly array $requiresPlugin,
        public readonly array $changelog,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidPluginException when a required feed field is missing or unsafe.
     */
    public static function fromArray(string $slug, array $data): self
    {
        if (!PluginManifest::isValidSlug($slug)) {
            throw new InvalidPluginException(sprintf('Invalid plugin slug "%s".', $slug));
        }

        $version = self::string($data['version'] ?? null);
        if ($version === null || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new InvalidPluginException('The update feed has no valid version.');
        }

        $downloadUrl = self::httpUrl($data['download_url'] ?? null);
        if ($downloadUrl === null) {
            throw new InvalidPluginException('The update feed has no valid download_url.');
        }

        $sha256 = strtolower(self::string($data['sha256'] ?? null) ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1) {
            throw new InvalidPluginException('The update feed has no valid SHA-256 hash.');
        }

        return new self(
            $slug,
            $version,
            $downloadUrl,
            $sha256,
            self::string($data['requires_vale'] ?? null),
            self::string($data['requires_php'] ?? null),
            self::extensions($data['requires_ext'] ?? null),
            self::pluginDependencies($slug, $data['requires_plugin'] ?? null),
            self::changelog($data['changelog'] ?? null),
        );
    }

    public function isNewerThan(string $currentVersion): bool
    {
        return version_compare($this->version, $currentVersion, '>');
    }

    /**
     * Human-readable reason this release cannot run on the current platform.
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
     * @return array<string, string|null>
     */
    private static function pluginDependencies(string $self, mixed $value): array
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

            if ($slug === '' || $slug === $self || !PluginManifest::isValidSlug($slug)) {
                continue;
            }

            $dependencies[$slug] = $constraint === '' ? null : $constraint;
        }

        return $dependencies;
    }

    /**
     * @return list<string>
     */
    private static function changelog(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $lines = [];
        foreach ($value as $line) {
            if (!is_string($line)) {
                continue;
            }

            $line = trim($line);
            if ($line !== '') {
                $lines[] = mb_substr($line, 0, 500);
            }
            if (count($lines) >= 20) {
                break;
            }
        }

        return $lines;
    }

    private static function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\Throwable) {
            return version_compare($version, ltrim($constraint, '=<>'), '>=');
        }
    }

    private static function httpUrl(mixed $value): ?string
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

    private static function string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
