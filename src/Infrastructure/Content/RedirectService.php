<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Content;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

class RedirectService
{
    public function __construct(
        private readonly Connection $db,
        private readonly DbalSettingsRepository $settings
    ) {
    }

    /**
     * Record a new redirect from an old path to a new path.
     *
     * Manual rows (curated content aliases) are never stolen by automatic
     * redirects: an automatic call whose old path is already a manual alias
     * is a no-op so slug history can never delete editorial aliases.
     */
    public function recordRedirect(string $oldPath, string $newPath, bool $isPrefix = false, bool $isManual = false): void
    {
        if ($oldPath === $newPath || $oldPath === '' || $newPath === '') {
            return;
        }

        // Manual aliases are explicit editorial intent, not slug-change
        // history — they are recorded even when automatic redirects are off.
        if (!$isManual && !$this->isAutoRedirectEnabled()) {
            return;
        }

        $p       = VL_DB_PREFIX;
        $typeBit = $isPrefix ? 1 : 0;

        if (!$isManual) {
            $existing = $this->findTargetForOldPath($oldPath);
            if ($existing !== null && (bool) ($existing['is_manual'] ?? false)) {
                return;
            }
        }

        // Step 1: Remove existing redirect to this old_path if any
        $this->db->delete($p . 'redirects', ['old_path' => $oldPath]);

        // Step 2 (anti-loop): If a redirect currently starts at newPath (of the same kind),
        // remove it before inserting. Otherwise /blog -> /yazilar + /yazilar -> /blog would
        // create a 2-cycle. The user-facing intent of the new redirect supersedes the old.
        $this->db->delete($p . 'redirects', [
            'old_path'  => $newPath,
            'is_prefix' => $typeBit,
        ]);

        // Step 3: Chain flattening - if any existing redirect (of the same kind) points TO
        // the OLD path, update it to point to the NEW path.
        //   A -> B, then B -> C becomes A -> C (and B -> C stays as a separate redirect)
        // Applied to both prefix and non-prefix rows so cycles cannot form.
        $this->db->update(
            $p . 'redirects',
            ['new_path' => $newPath],
            ['new_path' => $oldPath, 'is_prefix' => $typeBit]
        );

        // Step 4: Insert the new redirect
        $this->db->insert($p . 'redirects', [
            'old_path'   => $oldPath,
            'new_path'   => $newPath,
            'is_prefix'  => $isPrefix ? 1 : 0,
            'is_manual'  => $isManual ? 1 : 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Record a prefix-based redirect (e.g., when a whole section like /blog changes to /news).
     */
    public function recordPrefixRedirect(string $oldPrefix, string $newPrefix): void
    {
        $oldPrefix = '/' . ltrim($oldPrefix, '/');
        $newPrefix = '/' . ltrim($newPrefix, '/');

        if ($oldPrefix === $newPrefix || $oldPrefix === '/' || $newPrefix === '/') {
            return;
        }

        $this->recordRedirect($oldPrefix, $newPrefix, true);
    }

    public function countAll(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . VL_DB_PREFIX . 'redirects');
    }

    public function clearAll(): int
    {
        return $this->db->executeStatement('DELETE FROM ' . VL_DB_PREFIX . 'redirects');
    }

    /**
     * Find a redirect for a given path.
     *
     * Manual aliases (curated by editors) always resolve, even when the
     * automatic-redirect setting is disabled — they are explicit intent,
     * not slug-change history.
     */
    public function findRedirect(string $path): ?string
    {
        $p = VL_DB_PREFIX;

        // 0. Manual alias first (no setting gate)
        $manual = $this->db->fetchAssociative(
            "SELECT new_path FROM {$p}redirects WHERE old_path = ? AND is_prefix = FALSE AND is_manual = TRUE LIMIT 1",
            [$path],
            [ParameterType::STRING]
        );

        if ($manual !== false) {
            return $this->applyPrefixChain((string) $manual['new_path']);
        }

        if (!$this->isAutoRedirectEnabled()) {
            return null;
        }

        // Load prefix redirects once (used for both exact chaining and direct prefix match)
        $prefixRows = $this->db->fetchAllAssociative(
            "SELECT old_path, new_path FROM {$p}redirects WHERE is_prefix = TRUE ORDER BY LENGTH(old_path) DESC"
        );

        // 1. Exact match first
        $row = $this->db->fetchAssociative(
            "SELECT new_path FROM {$p}redirects WHERE old_path = ? AND is_prefix = FALSE LIMIT 1",
            [$path],
            [ParameterType::STRING]
        );

        if ($row) {
            return $this->applyPrefixChain((string) $row['new_path']);
        }

        // 2. Prefix match (for changing /blog/* to /news/*)
        // We look for the longest matching prefix
        foreach ($prefixRows as $row) {
            $oldPrefix = (string) $row['old_path'];
            $newPrefix = (string) $row['new_path'];
            if ($path === $oldPrefix) {
                return $newPrefix;
            }
            if (str_starts_with($path, $oldPrefix . '/')) {
                $suffix        = substr($path, strlen($oldPrefix));
                $convertedPath = $newPrefix . $suffix;
                // Avoid redirect loops: check if the converted path itself has an
                // exact match or further prefix redirect to follow.
                $chained = $this->findRedirect($convertedPath);
                return $chained ?? $convertedPath;
            }
        }

        return null;
    }

    /**
     * Raw lookup bypassing the auto-redirect setting gate (admin validation).
     *
     * @return array{new_path: string, is_prefix: mixed, is_manual: mixed}|null
     */
    public function findTargetForOldPath(string $oldPath): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT new_path, is_prefix, is_manual FROM ' . VL_DB_PREFIX . 'redirects WHERE old_path = ? LIMIT 1',
            [$oldPath],
            [ParameterType::STRING]
        );

        return $row === false ? null : $row;
    }

    /**
     * Old paths of manual aliases pointing at the given canonical path.
     *
     * @return string[]
     */
    public function findManualAliases(string $canonicalPath): array
    {
        $rows = $this->db->fetchFirstColumn(
            'SELECT old_path FROM ' . VL_DB_PREFIX . 'redirects WHERE new_path = ? AND is_prefix = FALSE AND is_manual = TRUE ORDER BY old_path ASC',
            [$canonicalPath],
            [ParameterType::STRING]
        );

        return array_map('strval', $rows);
    }

    public function deleteManualAlias(string $oldPath): void
    {
        $this->db->delete(VL_DB_PREFIX . 'redirects', ['old_path' => $oldPath, 'is_manual' => 1]);
    }

    /**
     * Delete all manual aliases targeting a canonical path (content deletion).
     */
    public function deleteManualByTarget(string $canonicalPath): int
    {
        return $this->db->delete(VL_DB_PREFIX . 'redirects', ['new_path' => $canonicalPath, 'is_manual' => 1]);
    }

    /**
     * Repoint manual aliases after a canonical-path change (slug/parent move).
     */
    public function repointManualAliases(string $oldCanonical, string $newCanonical): void
    {
        if ($oldCanonical === $newCanonical || $oldCanonical === '' || $newCanonical === '') {
            return;
        }

        $this->db->update(
            VL_DB_PREFIX . 'redirects',
            ['new_path' => $newCanonical],
            ['new_path' => $oldCanonical, 'is_manual' => 1]
        );
    }

    public const MAX_ALIASES = 10;

    /**
     * Build a canonical public path from a section base path and a relative
     * item path. Both parts are stored locale-agnostic (no /tr, /en prefix).
     */
    public function contentCanonical(string $basePath, string $relativePath): string
    {
        return rtrim($basePath, '/') . '/' . ltrim($relativePath, '/');
    }

    /**
     * Alias leaf slugs (last URL segment) for a canonical path.
     *
     * @return string[]
     */
    public function aliasLeafs(string $canonical, string $basePath): array
    {
        $base = rtrim($basePath, '/');

        $leafs = [];
        foreach ($this->findManualAliases($canonical) as $oldPath) {
            $relative = $oldPath;
            if ($base !== '' && str_starts_with($relative, $base . '/')) {
                $relative = substr($relative, strlen($base . '/'));
            }
            $relative = ltrim($relative, '/');
            $leaf     = str_contains($relative, '/') ? substr($relative, (int) strrpos($relative, '/') + 1) : $relative;
            if ($leaf !== '') {
                $leafs[] = $leaf;
            }
        }

        return $leafs;
    }

    /**
     * Parse raw alias form input (comma-separated string or list) into
     * unique trimmed values. Format validation happens in the caller.
     *
     * @param string|string[]|null $input
     * @return string[]
     */
    public function parseAliasInput(mixed $input): array
    {
        $parts = is_array($input) ? $input : explode(',', (string) $input);

        $leafs = [];
        foreach ($parts as $part) {
            $leaf = trim((string) $part);
            if ($leaf === '' || in_array($leaf, $leafs, true)) {
                continue;
            }
            $leafs[] = $leaf;
        }

        if (count($leafs) > self::MAX_ALIASES) {
            throw ValidationException::withMessages(
                ['aliases' => sprintf('Too many aliases (max %d).', self::MAX_ALIASES)]
            );
        }

        return $leafs;
    }

    /**
     * Chain an exact-match target through prefix redirects: if the target
     * still uses an old prefix (e.g. /yazilar/slug but prefix changed to
     * /blog), apply the prefix redirect to keep the target valid.
     */
    private function applyPrefixChain(string $newPath): string
    {
        $p = VL_DB_PREFIX;

        $prefixRows = $this->db->fetchAllAssociative(
            "SELECT old_path, new_path FROM {$p}redirects WHERE is_prefix = TRUE ORDER BY LENGTH(old_path) DESC"
        );

        foreach ($prefixRows as $pr) {
            $oldPrefix = (string) $pr['old_path'];
            $newPrefix = (string) $pr['new_path'];
            if ($newPath === $oldPrefix) {
                return $newPrefix;
            }
            if (str_starts_with($newPath, $oldPrefix . '/')) {
                return $newPrefix . substr($newPath, strlen($oldPrefix));
            }
        }

        return $newPath;
    }

    private function isAutoRedirectEnabled(): bool
    {
        return $this->settings->get('auto_redirect_enabled', '1') === '1';
    }
}
