<?php

declare(strict_types=1);

namespace ValeApp\Application\Plugin;

use Doctrine\DBAL\Connection;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * Runs and reverts plugin-owned migrations from content/plugins/<slug>/migrations/.
 *
 * Contract — one file per migration, named YYYYMMDDHHMMSS_snake_name.php:
 *
 *   return [
 *       'up'   => static function (Connection $db): void { ... },
 *       'down' => static function (Connection $db): void { ... }, // enables rollback
 *   ];
 *
 * A file may also return the up callable directly (no rollback then). Applied
 * file names are stored per plugin in the `plugin_migrations` setting, so each
 * migration runs once and the record survives updates (content/ is never replaced).
 */
final class PluginMigrator
{
    public const SETTINGS_KEY   = 'plugin_migrations';
    public const RUNS_KEY       = 'plugin_migration_runs';
    public const SETTINGS_GROUP = 'plugins';

    private const MAX_MESSAGE_LENGTH = 500;

    /** Ordered, filesystem-safe migration file names. */
    private const FILE_PATTERN = '/^[0-9]{14}_[a-z0-9_]+\.php$/';

    public function __construct(
        private readonly PluginManager $plugins,
        private readonly Connection $db,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * Apply every pending migration of one plugin.
     *
     * @return array{plugin:string, pending:list<string>, applied:list<string>}
     * @throws InvalidPluginException when the plugin, its directory or a file name is invalid.
     * @throws \RuntimeException when a migration fails; earlier ones stay recorded.
     */
    public function migrate(string $slug, bool $dryRun = false): array
    {
        $directory = $this->resolvePlugin($slug);

        $pending = $this->pendingForDirectory($slug, $directory);
        if ($dryRun || $pending === []) {
            return ['plugin' => $slug, 'pending' => $pending, 'applied' => []];
        }

        $applied = [];
        try {
            foreach ($pending as $file) {
                $this->run($directory . '/migrations/' . $file);
                $this->record($slug, $file);
                $applied[] = $file;
            }
        } catch (\Throwable $e) {
            $this->recordRun($slug, 'error', 'apply', count($applied), $e->getMessage());
            throw $e;
        }

        $this->recordRun($slug, 'ok', 'apply', count($applied));

        return ['plugin' => $slug, 'pending' => [], 'applied' => $applied];
    }

    /**
     * Revert applied migrations, newest first.
     *
     * @return array{plugin:string, reverted:list<string>, remaining:list<string>}
     * @throws InvalidPluginException when a migration has no `down` callable.
     * @throws \RuntimeException when the record points at a missing file or `down` fails.
     */
    public function rollback(string $slug, bool $dryRun = false, bool $all = false): array
    {
        $directory = $this->resolvePlugin($slug);

        $applied = $this->applied($slug);
        $targets = $all ? array_reverse($applied) : array_slice(array_reverse($applied), 0, 1);

        if ($dryRun || $targets === []) {
            return [
                'plugin'    => $slug,
                'reverted'  => $targets,
                'remaining' => array_values(array_diff($applied, $targets)),
            ];
        }

        $reverted = [];
        try {
            foreach ($targets as $file) {
                $path = $directory . '/migrations/' . $file;
                if (!is_file($path)) {
                    throw new \RuntimeException(sprintf(
                        'Plugin migration %s is recorded but missing from disk.',
                        $file,
                    ));
                }

                $this->revert($path);
                $reverted[] = $file;
                $this->unrecord($slug, $file);
            }
        } catch (\Throwable $e) {
            $this->recordRun($slug, 'error', 'rollback', count($reverted), $e->getMessage());
            throw $e;
        }

        $this->recordRun($slug, 'ok', 'rollback', count($reverted));

        return ['plugin' => $slug, 'reverted' => $reverted, 'remaining' => $this->applied($slug)];
    }

    /**
     * Apply pending migrations of every enabled plugin. One failing plugin never
     * stops the others; its error is reported in the row.
     *
     * @return list<array{plugin:string, pending:list<string>, applied:list<string>, error:?string}>
     */
    public function migrateEnabled(bool $dryRun = false): array
    {
        if ($this->plugins->isDisabledByOperator()) {
            return [];
        }

        $reports = [];
        foreach ($this->plugins->getEnabledSlugs() as $slug) {
            try {
                $reports[] = $this->migrate($slug, $dryRun) + ['error' => null];
            } catch (\Throwable $e) {
                $reports[] = [
                    'plugin'  => $slug,
                    'pending' => [],
                    'applied' => [],
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return $reports;
    }

    /**
     * @return list<string>
     */
    public function pending(string $slug): array
    {
        $directory = $this->plugins->resolveDirectory($slug);

        return $directory === null ? [] : $this->pendingForDirectory($slug, $directory);
    }

    /**
     * @return list<string>
     */
    public function applied(string $slug): array
    {
        return $this->allApplied()[$slug] ?? [];
    }

    /**
     * Outcome of the most recent apply/rollback run, shown in the plugin list.
     * Null until the first migration actually ran (or failed) for this plugin.
     *
     * @return array{status:string, action:string, count:int, at:string, message:?string}|null
     */
    public function lastResult(string $slug): ?array
    {
        return $this->runs()[$slug] ?? null;
    }

    private function resolvePlugin(string $slug): string
    {
        if (!PluginManifest::isValidSlug($slug)) {
            throw new InvalidPluginException(sprintf('Invalid plugin slug "%s".', $slug));
        }

        $directory = $this->plugins->resolveDirectory($slug);
        if ($directory === null) {
            throw new InvalidPluginException('Plugin directory is missing.');
        }

        return $directory;
    }

    /**
     * @return list<string>
     */
    private function pendingForDirectory(string $slug, string $directory): array
    {
        $applied = $this->applied($slug);

        return array_values(array_filter(
            $this->migrationFiles($directory),
            static fn (string $file): bool => !in_array($file, $applied, true),
        ));
    }

    /**
     * @return list<string>
     * @throws InvalidPluginException on a file that does not follow the naming contract.
     */
    private function migrationFiles(string $directory): array
    {
        $migrationsDir = $directory . '/migrations';
        if (!is_dir($migrationsDir)) {
            return [];
        }

        $files = glob($migrationsDir . '/*.php') ?: [];
        $names = [];

        foreach ($files as $file) {
            $name = basename($file);
            if (preg_match(self::FILE_PATTERN, $name) !== 1) {
                throw new InvalidPluginException(sprintf(
                    'Migration "%s" must be named YYYYMMDDHHMMSS_snake_name.php.',
                    $name,
                ));
            }
            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    /**
     * @return array{up: callable, down: ?callable}
     * @throws InvalidPluginException when the file does not expose an `up` callable.
     */
    private function load(string $file): array
    {
        try {
            $result = (static fn (string $path) => include $path)($file);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('Plugin migration %s could not be loaded: %s', basename($file), $e->getMessage()),
                0,
                $e,
            );
        }

        if (is_callable($result)) {
            return ['up' => $result, 'down' => null];
        }

        $up = is_array($result) && is_callable($result['up'] ?? null) ? $result['up'] : null;
        if ($up === null) {
            throw new InvalidPluginException(sprintf(
                'Migration %s must return a callable or an array with an "up" callable.',
                basename($file),
            ));
        }

        $down = is_callable($result['down'] ?? null) ? $result['down'] : null;

        return ['up' => $up, 'down' => $down];
    }

    private function run(string $file): void
    {
        $up = $this->load($file)['up'];

        try {
            $up($this->db);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('Plugin migration %s failed: %s', basename($file), $e->getMessage()),
                0,
                $e,
            );
        }
    }

    private function revert(string $file): void
    {
        $down = $this->load($file)['down'];

        if ($down === null) {
            throw new InvalidPluginException(sprintf(
                'Migration %s has no "down" callable, so it cannot be rolled back automatically.',
                basename($file),
            ));
        }

        try {
            $down($this->db);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('Plugin migration %s rollback failed: %s', basename($file), $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Drop this plugin's migration bookkeeping (applied files + last run).
     * Used when the operator removes a plugin's data; files are not touched.
     */
    public function forget(string $slug): void
    {
        $applied = $this->allApplied();
        if (isset($applied[$slug])) {
            unset($applied[$slug]);
            $this->save($applied);
        }

        $runs = $this->runs();
        if (isset($runs[$slug])) {
            unset($runs[$slug]);
            $this->settings->set(
                self::RUNS_KEY,
                json_encode($runs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                self::SETTINGS_GROUP,
                'json',
            );
        }
    }

    private function record(string $slug, string $file): void
    {
        $applied          = $this->allApplied();
        $applied[$slug][] = $file;

        $this->save($applied);
    }

    private function unrecord(string $slug, string $file): void
    {
        $applied    = $this->allApplied();
        $remaining  = array_values(array_filter(
            $applied[$slug] ?? [],
            static fn (string $appliedFile): bool => $appliedFile !== $file,
        ));

        if ($remaining === []) {
            unset($applied[$slug]);
        } else {
            $applied[$slug] = $remaining;
        }

        $this->save($applied);
    }

    /**
     * @param array<string, list<string>> $applied
     */
    private function save(array $applied): void
    {
        $this->settings->set(
            self::SETTINGS_KEY,
            json_encode($applied, JSON_UNESCAPED_SLASHES) ?: '{}',
            self::SETTINGS_GROUP,
            'json',
        );
    }

    /**
     * @return array<string, array{status:string, action:string, count:int, at:string, message:?string}>
     */
    private function runs(): array
    {
        $decoded = json_decode($this->settings->get(self::RUNS_KEY, '{}') ?? '{}', true);
        if (!is_array($decoded)) {
            return [];
        }

        $runs = [];
        foreach ($decoded as $slug => $run) {
            if (!is_string($slug) || !is_array($run)) {
                continue;
            }

            $runs[$slug] = [
                'status'  => ($run['status'] ?? '') === 'error' ? 'error' : 'ok',
                'action'  => ($run['action'] ?? '') === 'rollback' ? 'rollback' : 'apply',
                'count'   => (int) ($run['count'] ?? 0),
                'at'      => is_string($run['at'] ?? null) ? $run['at'] : '',
                'message' => is_string($run['message'] ?? null) ? $run['message'] : null,
            ];
        }

        return $runs;
    }

    private function recordRun(string $slug, string $status, string $action, int $count, ?string $message = null): void
    {
        $runs        = $this->runs();
        $runs[$slug] = [
            'status'  => $status,
            'action'  => $action,
            'count'   => $count,
            'at'      => date('Y-m-d H:i'),
            'message' => $message === null ? null : mb_substr($message, 0, self::MAX_MESSAGE_LENGTH),
        ];

        $this->settings->set(
            self::RUNS_KEY,
            json_encode($runs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            self::SETTINGS_GROUP,
            'json',
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function allApplied(): array
    {
        $decoded = json_decode($this->settings->get(self::SETTINGS_KEY, '{}') ?? '{}', true);
        if (!is_array($decoded)) {
            return [];
        }

        $applied = [];
        foreach ($decoded as $slug => $files) {
            if (!is_string($slug) || !is_array($files)) {
                continue;
            }
            $applied[$slug] = array_values(array_filter($files, 'is_string'));
        }

        return $applied;
    }
}
