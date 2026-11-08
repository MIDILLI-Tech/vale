<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrate URLs in database content when APP_URL or external URLs change.
 *
 * Base-path mode (default):
 *   php vale migrate:urls --from=/old --to=/new
 *   php vale migrate:urls --from=/vale --to=
 *   php vale migrate:urls --from= --to=/vale --dry-run
 *
 * Full URL mode (--full-migration required):
 *   php vale migrate:urls --from=https://old.com --to=https://new.com --full-migration --dry-run
 *   php vale migrate:urls --from=https://old.com --to=https://new.com --full-migration -y
 */
final class MigrateUrlsCommand extends Command
{
    private const EXCLUDED_COLUMNS = [
        'id', 'created_at', 'updated_at', 'published_at',
        'password', 'two_factor_secret', 'token', 'token_hash',
        'session_version', 'is_active', 'is_system', 'is_autoload',
        'sort_order', 'view_count', 'size', 'parent_id',
        'translation_of', 'revision_number', 'user_id', 'author_id',
        'post_id', 'role_id', 'permission_id', 'menu_id',
        'category_id', 'tag_id', 'media_id', 'comment_id',
        'featured_image_id', 'defaultRoleId', 'status',
        'type', 'disk', 'mime_type', 'locale', 'language',
        'comment_status', 'target', 'is_system',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('migrate:urls')
            ->setDescription('Migrate URLs in database content when APP_URL or external URLs change')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Old URL or basepath (e.g. /vale, https://old.com)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'New URL or basepath (e.g. /cms, https://new.com)')
            ->addOption('full-migration', null, InputOption::VALUE_NONE, 'Enable full URL replacement (required when --from/--to contain http:// or https://)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without updating the database')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $from = $input->getOption('from');
        $to   = $input->getOption('to');

        if ($from === null || $to === null) {
            $io->error('Both --from and --to options are required. Use an empty string for root (e.g. --from=)');
            return Command::FAILURE;
        }

        $fromRaw    = (string) $from;
        $toRaw      = (string) $to;
        $dryRun     = (bool) $input->getOption('dry-run');
        $noPrompt   = (bool) $input->getOption('yes');
        $fullFlag   = $input->getOption('full-migration');

        $isFullUrlMode = str_starts_with($fromRaw, 'http://')
            || str_starts_with($fromRaw, 'https://')
            || str_starts_with($toRaw, 'http://')
            || str_starts_with($toRaw, 'https://');

        if ($isFullUrlMode && !$fullFlag) {
            $io->error('Full URL replacement detected (--from or --to contains http:// or https://).');
            $io->text('Add the --full-migration flag to confirm this is intentional:');
            $io->text('  <info>php vale migrate:urls --from=' . $fromRaw . ' --to=' . $toRaw . ' --full-migration --dry-run</info>');
            return Command::FAILURE;
        }

        if ($isFullUrlMode) {
            return $this->executeFullUrlMode($io, $fromRaw, $toRaw, $dryRun, $noPrompt);
        }

        return $this->executeBasePathMode($io, $fromRaw, $toRaw, $dryRun, $noPrompt);
    }

    // ── Full URL mode ────────────────────────────────────────────

    private function executeFullUrlMode(
        SymfonyStyle $io,
        string $from,
        string $to,
        bool $dryRun,
        bool $noPrompt,
    ): int {
        if ($from === $to) {
            $io->info("Nothing to do: --from and --to are identical ({$from}).");
            return Command::SUCCESS;
        }

        $io->title('Vale Full URL Migration');
        $io->listing([
            "From URL      : {$from}",
            "To URL        : {$to}",
            "Mode          : " . ($dryRun ? 'DRY RUN' : 'LIVE'),
        ]);

        $io->warning('Full URL mode scans ALL text columns across the entire database.');
        $io->newLine();

        $targets = $this->getAllTextColumns();

        $io->text('Scanning ' . count($targets) . ' columns across ' . count(array_unique(array_column($targets, 0))) . ' tables...');
        $io->newLine();

        $patterns     = [[$from, $to]];
        $totalRows    = 0;
        $totalUpdated = 0;
        $results      = [];

        foreach ($targets as [$table, $column]) {
            [$changed, $scanned] = $this->processTable(
                $table,
                $column,
                'full',
                $patterns,
                $from,
                $to,
                $dryRun,
            );

            if ($scanned > 0) {
                $totalRows    += $scanned;
                $totalUpdated += $changed;

                $results[] = [
                    $table . '.' . $column,
                    $scanned,
                    $changed,
                    $changed > 0
                        ? ($dryRun ? '<comment>Would update</comment>' : '<info>Updated</info>')
                        : 'Unchanged',
                ];
            }
        }

        if (empty($results)) {
            $io->success('No occurrences of "' . $from . '" found in the database.');
            $this->clearCache($io);
            return Command::SUCCESS;
        }

        $io->table(['Target Column', 'Matches', 'Changes', 'Status'], $results);

        if ($dryRun) {
            $io->note("Dry run complete. {$totalUpdated} rows would be updated across " . count($results) . " columns.");
            return Command::SUCCESS;
        }

        if ($totalUpdated === 0) {
            $io->info('Found matching patterns but no actual changes needed.');
            $this->clearCache($io);
            return Command::SUCCESS;
        }

        if (!$noPrompt) {
            $question = new ConfirmationQuestion("About to update {$totalUpdated} rows. Continue?", false);
            if (!$io->askQuestion($question)) {
                $io->warning('Aborted.');
                return Command::SUCCESS;
            }
        }

        $io->comment('Applying changes...');

        $actualUpdated = 0;
        foreach ($targets as [$table, $column]) {
            [$changed] = $this->processTable($table, $column, 'full', $patterns, $from, $to, false, true);
            $actualUpdated += $changed;
        }

        $io->success("Successfully updated {$actualUpdated} rows.");

        $this->clearCache($io);

        return Command::SUCCESS;
    }

    /**
     * Discover all string/text/json columns across the database.
     *
     * @return array<array{0: string, 1: string}> [table, column]
     */
    private function getAllTextColumns(): array
    {
        $schemaManager = $this->connection->createSchemaManager();
        $targets       = [];
        $prefix        = VL_DB_PREFIX;

        foreach ($schemaManager->listTables() as $table) {
            $tableName = $table->getName();

            // Only scan Vale tables (skip any prefix mismatch)
            if ($prefix !== '' && !str_starts_with($tableName, $prefix)) {
                continue;
            }

            foreach ($table->getColumns() as $column) {
                $colName = $column->getName();

                if (in_array($colName, self::EXCLUDED_COLUMNS, true)) {
                    continue;
                }

                $type = $column->getType()->lookupName($column->getType());

                if (in_array($type, ['string', 'text', 'json'], true)) {
                    $targets[] = [$tableName, $colName];
                }
            }
        }

        return $targets;
    }

    // ── Base-path mode (existing behavior) ───────────────────────

    private function executeBasePathMode(
        SymfonyStyle $io,
        string $from,
        string $to,
        bool $dryRun,
        bool $noPrompt,
    ): int {
        $fromPath = $this->normalizePath($from);
        $toPath   = $this->normalizePath($to);

        if ($fromPath === $toPath) {
            $io->info("Nothing to do: --from and --to are identical ({$fromPath}).");
            return Command::SUCCESS;
        }

        $io->title('Vale URL Migration');
        $io->listing([
            "From basepath : " . ($fromPath ?: '(root)'),
            "To basepath   : " . ($toPath ?: '(root)'),
            "Mode          : " . ($dryRun ? 'DRY RUN' : 'LIVE'),
        ]);

        // Each entry: [table, column, type]
        // type 'html' uses src/href patterns
        // type 'url' uses direct path replacement
        $targets = [
            ['posts',                'body',              'html'],
            ['products',             'description',       'html'],
            ['products',             'short_description', 'html'],
            ['newsletter_campaigns', 'body_html',         'html'],
            ['newsletter_campaigns', 'body_html_b',       'html'],
            ['automation_steps',     'body_html',         'html'],
            ['automation_steps',     'body_html_b',       'html'],
            ['categories',           'description',       'html'],
            ['product_categories',   'description',       'html'],
            ['menu_items',           'url',               'url'],
            ['seo_meta',             'canonical_url',     'url'],
            ['redirects',            'old_path',          'url'],
            ['redirects',            'new_path',          'url'],
        ];

        $patterns = $this->buildPatterns($fromPath, $toPath);

        $totalRows    = 0;
        $totalUpdated = 0;
        $results      = [];

        foreach ($targets as [$table, $column, $type]) {
            $tableName           = VL_DB_PREFIX . $table;
            [$changed, $scanned] = $this->processTable($tableName, $column, $type, $patterns, $fromPath, $toPath, $dryRun);

            if ($scanned > 0) {
                $totalRows    += $scanned;
                $totalUpdated += $changed;

                $results[] = [
                    $tableName . '.' . $column,
                    $scanned,
                    $changed,
                    $changed > 0 ? ($dryRun ? '<comment>Would update</comment>' : '<info>Updated</info>') : 'Unchanged',
                ];
            }
        }

        if (empty($results)) {
            $io->success('No occurrences of the old basepath found.');
            $this->clearCache($io);
            return Command::SUCCESS;
        }

        $io->table(['Target Column', 'Matches', 'Changes', 'Status'], $results);

        if ($dryRun) {
            $io->note("Dry run complete. {$totalUpdated} rows would be updated across " . count($results) . " tables.");
            return Command::SUCCESS;
        }

        if ($totalUpdated === 0) {
            $io->info('Found matching patterns but no actual changes needed.');
            $this->clearCache($io);
            return Command::SUCCESS;
        }

        if (!$noPrompt) {
            $question = new ConfirmationQuestion("About to update {$totalUpdated} rows. Continue?", false);
            if (!$io->askQuestion($question)) {
                $io->warning('Aborted.');
                return Command::SUCCESS;
            }
        }

        $io->comment('Applying changes...');

        $actualUpdated = 0;
        foreach ($targets as [$table, $column, $type]) {
            $tableName = VL_DB_PREFIX . $table;
            [$changed] = $this->processTable($tableName, $column, $type, $patterns, $fromPath, $toPath, false, true);
            $actualUpdated += $changed;
        }

        $io->success("Successfully updated {$actualUpdated} rows.");

        $this->clearCache($io);

        $io->info('Done! Remember to update APP_URL in .env if you haven\'t already.');

        return Command::SUCCESS;
    }

    /**
     * Normalise a basepath: always starts with "/" if non-empty, no trailing slash.
     */
    private function normalizePath(string $path): string
    {
        $path = rtrim(trim($path), '/');
        if ($path === '' || $path === '/') {
            return '';
        }
        return '/' . ltrim($path, '/');
    }

    /**
     * Build search/replace patterns for HTML attributes.
     */
    private function buildPatterns(string $from, string $to): array
    {
        $patterns = [];
        foreach (['"', "'"] as $q) {
            $patterns[] = ['src=' . $q . $from . '/uploads/', 'src=' . $q . $to . '/uploads/'];

            if ($from !== '') {
                $patterns[] = ['href=' . $q . $from . '/', 'href=' . $q . $to . '/'];
                $patterns[] = ['href=' . $q . $from . $q,  'href=' . $q . ($to ?: '/') . $q];
            } else {
                $patterns[] = ['href=' . $q . '/', 'href=' . $q . $to . '/'];
            }
        }
        return $patterns;
    }

    /**
     * Scan and optionally update a single table column.
     */
    private function processTable(
        string $table,
        string $column,
        string $type,
        array $patterns,
        string $from,
        string $to,
        bool $dryRun,
        bool $apply = false,
    ): array {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tableExists($table)) {
            return [0, 0];
        }

        // Find primary key — skip tables without one (e.g. pivot tables).
        $tableDetails = $schemaManager->introspectTable($table);
        $pkColumns    = $tableDetails->getPrimaryKey();
        if ($pkColumns === null) {
            return [0, 0];
        }
        $pkName = $pkColumns->getColumns()[0] ?? null;
        if ($pkName === null) {
            return [0, 0];
        }

        $columns = $schemaManager->listTableColumns($table);
        $found   = false;
        foreach ($columns as $col) {
            if (strtolower($col->getName()) === strtolower($column)) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return [0, 0];
        }

        $whereParts = [];

        if ($type === 'full') {
            // Full URL mode: direct string match
            $whereParts[] = $this->connection->quoteIdentifier($column) . " LIKE " . $this->connection->quote('%' . $from . '%');
        } elseif ($type === 'html') {
            $searches = array_unique(array_column($patterns, 0));
            foreach ($searches as $s) {
                $whereParts[] = $this->connection->quoteIdentifier($column) . " LIKE " . $this->connection->quote('%' . $s . '%');
            }
        } else {
            if ($from === '') {
                $whereParts[] = $this->connection->quoteIdentifier($column) . " LIKE '/%'";
            } else {
                $whereParts[] = $this->connection->quoteIdentifier($column) . " LIKE " . $this->connection->quote($from . '%');
            }
        }

        $query = "SELECT " . $this->connection->quoteIdentifier($pkName) . ", " . $this->connection->quoteIdentifier($column) . " FROM " . $this->connection->quoteIdentifier($table);
        if (!empty($whereParts)) {
            $query .= " WHERE " . implode(' OR ', $whereParts);
        }

        $rows    = $this->connection->fetchAllAssociative($query);
        $scanned = count($rows);
        $changed = 0;

        if ($scanned === 0 || ($dryRun && !$apply)) {
            return [$scanned, $scanned];
        }

        foreach ($rows as $row) {
            $original = (string) ($row[$column] ?? '');
            $updated  = $original;

            if ($type === 'full') {
                // Full URL mode: direct str_replace
                $updated = str_replace($from, $to, $updated);
            } elseif ($type === 'html') {
                foreach ($patterns as [$search, $replace]) {
                    $updated = str_replace($search, $replace, $updated);
                }
            } else {
                // Plain URL replacement
                if ($from === '') {
                    if (str_starts_with($updated, '/') && !str_starts_with($updated, '//')) {
                        if ($to !== '' && !str_starts_with($updated, $to . '/')) {
                            $updated = $to . $updated;
                        }
                    }
                } else {
                    if ($updated === $from) {
                        $updated = $to ?: '/';
                    } elseif (str_starts_with($updated, $from . '/')) {
                        $updated = $to . substr($updated, strlen($from));
                    }
                }
            }

            if ($updated !== $original) {
                $changed++;
                if ($apply) {
                    $this->connection->update($table, [$column => $updated], [$pkName => $row[$pkName]]);
                }
            }
        }

        return [$changed, $scanned];
    }

    /**
     * Clear cache to ensure new URLs are reflected.
     */
    private function clearCache(SymfonyStyle $io): void
    {
        $cacheDir = storage_path('cache');
        if (!is_dir($cacheDir)) {
            return;
        }

        $count = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($it as $file) {
                if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                    @unlink($file->getPathname());
                    $count++;
                }
            }
        } catch (\Exception $e) {
            $io->warning("Could not fully clear cache: " . $e->getMessage());
        }

        if ($count > 0) {
            $io->note("Application cache cleared ({$count} files removed).");
        }
    }
}
