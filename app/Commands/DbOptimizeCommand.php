<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Infrastructure\Media\MediaUploadService;

final class DbOptimizeCommand extends Command
{
    private const ALL_TABLES = [
        'users', 'roles', 'permissions', 'user_roles', 'role_permissions',
        'settings', 'api_tokens', 'password_resets',
        'content_types', 'posts', 'post_meta', 'post_categories', 'post_tags',
        'revisions', 'comments',
        'categories', 'tags',
        'media', 'menus', 'menu_items',
        'seo_meta', 'redirects',
        'products', 'product_categories', 'product_to_categories',
        'product_images', 'product_variants', 'product_reviews', 'product_review_votes',
        'orders', 'order_items', 'order_downloads', 'order_logs',
        'coupons', 'coupon_usage',
        'subscriptions', 'subscription_logs',
        'membership_plans', 'user_memberships',
        'newsletter_subscribers', 'newsletter_campaigns', 'newsletter_automations',
        'form_submissions',
        'pageviews',
        'sessions',
        'webhooks', 'webhook_logs',
        'abandoned_carts',
        'country_tax_rates',
        'reviews', 'review_votes',
        'product_coupons',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly RevisionRepositoryInterface $revisions,
        private readonly CommentRepositoryInterface $comments,
        private readonly MediaRepositoryInterface $media,
        private readonly MediaUploadService $uploadService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('db:optimize')
            ->setDescription('Optimize the database — prune old data and compact tables')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Run all optimization tasks (default when no option given)')
            ->addOption('revisions', 'r', InputOption::VALUE_OPTIONAL, 'Prune old revisions (keep count, default 10)', false)
            ->addOption('content-type', 'c', InputOption::VALUE_OPTIONAL, 'Limit revision pruning to one content type slug (e.g. post, page)', null)
            ->addOption('spam', 's', InputOption::VALUE_NONE, 'Delete spam comments')
            ->addOption('trash', 't', InputOption::VALUE_NONE, 'Delete trashed comments')
            ->addOption('orphaned-meta', 'm', InputOption::VALUE_NONE, 'Delete orphaned post_meta records')
            ->addOption('orphaned-media-files', null, InputOption::VALUE_NONE, 'Delete media records where the file is missing on disk')
            ->addOption('orphaned-media-unused', null, InputOption::VALUE_NONE, 'Delete media records not referenced in any content')
            ->addOption('pageviews', 'p', InputOption::VALUE_OPTIONAL, 'Purge pageviews older than N days (default 365)', false)
            ->addOption('optimize-tables', 'o', InputOption::VALUE_NONE, 'Run OPTIMIZE TABLE on all tables')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all       = (bool) $input->getOption('all');
        $dryRun    = (bool) $input->getOption('dry-run');

        $hasExplicit = $input->getOption('revisions') !== false
            || $input->getOption('spam')
            || $input->getOption('trash')
            || $input->getOption('orphaned-meta')
            || $input->getOption('orphaned-media-files')
            || $input->getOption('orphaned-media-unused')
            || $input->getOption('pageviews') !== false
            || $input->getOption('optimize-tables');

        $runAll = $all || !$hasExplicit;

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN</comment> — no changes will be made.');
            $output->writeln('');
        }

        $exitCode = Command::SUCCESS;

        // ── Revisions ──────────────────────────────────────────────────
        if ($runAll || $input->getOption('revisions') !== false) {
            $keepCount   = $input->getOption('revisions');
            $keepCount   = is_string($keepCount) ? max(1, (int) $keepCount) : 10;
            $contentType = $input->getOption('content-type');
            $contentType = is_string($contentType) && $contentType !== '' ? $contentType : null;

            if ($contentType === null) {
                $totalRevisions = $this->revisions->countAll();
                $output->writeln("Revisions: {$totalRevisions} total, keeping latest {$keepCount} per item");
            } else {
                $prunable = $this->revisions->countPrunable($keepCount, $contentType);
                $output->writeln("Revisions (content type '{$contentType}'): {$prunable} prunable, keeping latest {$keepCount} per item");
            }

            if (!$dryRun) {
                $deleted = $this->revisions->pruneAll($keepCount, $contentType);
                $output->writeln("  <info>Pruned {$deleted} revision(s).</info>");
            } else {
                $output->writeln('  <comment>Would prune revisions.</comment>');
            }
        }

        // ── Spam comments ─────────────────────────────────────────────
        if ($runAll || $input->getOption('spam')) {
            $spamCount = $this->comments->countByStatus('spam');
            $output->writeln("Spam comments: {$spamCount}");

            if (!$dryRun) {
                $deleted = $this->comments->deleteByStatus('spam');
                $output->writeln("  <info>Deleted {$deleted} spam comment(s).</info>");
            } elseif ($spamCount > 0) {
                $output->writeln('  <comment>Would delete spam comments.</comment>');
            }
        }

        // ── Trashed comments ──────────────────────────────────────────
        if ($runAll || $input->getOption('trash')) {
            $trashCount = $this->comments->countByStatus('trash');
            $output->writeln("Trashed comments: {$trashCount}");

            if (!$dryRun) {
                $deleted = $this->comments->deleteByStatus('trash');
                $output->writeln("  <info>Deleted {$deleted} trashed comment(s).</info>");
            } elseif ($trashCount > 0) {
                $output->writeln('  <comment>Would delete trashed comments.</comment>');
            }
        }

        // ── Orphaned post_meta ────────────────────────────────────────
        if ($runAll || $input->getOption('orphaned-meta')) {
            $prefix        = VL_DB_PREFIX;
            $orphanedCount = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM {$prefix}post_meta WHERE post_id NOT IN (SELECT id FROM {$prefix}posts)"
            );
            $output->writeln("Orphaned post_meta records: {$orphanedCount}");

            if (!$dryRun && $orphanedCount > 0) {
                $deleted = (int) $this->db->executeStatement(
                    "DELETE FROM {$prefix}post_meta WHERE post_id NOT IN (SELECT id FROM {$prefix}posts)"
                );
                $output->writeln("  <info>Deleted {$deleted} orphaned post_meta record(s).</info>");
            } elseif ($orphanedCount > 0) {
                $output->writeln('  <comment>Would delete orphaned post_meta records.</comment>');
            }
        }

        // ── Orphaned media files (missing on disk) ─────────────────────
        // Media records are only ever deleted when explicitly requested —
        // never as part of the default/all optimization run. Records that are
        // still referenced by content are always kept (the file can simply be
        // restored); deleting them would break live pages.
        if ($input->getOption('orphaned-media-files')) {
            $missingFiles  = $this->media->findMissingFiles();
            $referencedIds = $this->media->findReferencedIds();

            $deletable = [];
            $kept      = [];
            foreach ($missingFiles as $missing) {
                if (isset($referencedIds[(int) $missing['id']])) {
                    $kept[] = $missing;
                } else {
                    $deletable[] = $missing;
                }
            }

            $output->writeln('Orphaned media files (missing on disk): ' . count($missingFiles));

            foreach ($kept as $missing) {
                $output->writeln(sprintf(
                    '  <comment>Kept #%d (%s): still referenced by content — restore the file instead.</comment>',
                    $missing['id'],
                    $missing['path'],
                ));
            }

            $count = count($deletable);
            if (!$dryRun && $count > 0) {
                foreach ($deletable as $missing) {
                    $m = $this->media->findById((int) $missing['id']);
                    if ($m !== null) {
                        // The original is gone, but variant files (thumbnails/WebP) may
                        // still exist on disk — remove them before dropping the record.
                        $this->uploadService->deleteFile($m);
                    }
                    $this->media->delete((int) $missing['id']);
                }
                $output->writeln("  <info>Deleted {$count} orphaned media record(s).</info>");
            } elseif ($count > 0) {
                $output->writeln('  <comment>Would delete orphaned media records (missing on disk).</comment>');
            }
        }

        // ── Unused media (not referenced in content) ──────────────────
        // Explicit-only, same reasoning as orphaned-media-files above.
        if ($input->getOption('orphaned-media-unused')) {
            $unusedMedia = $this->media->findUnused();
            $count       = count($unusedMedia);
            $output->writeln("Unused media (not referenced in content): {$count}");

            if (!$dryRun && $count > 0) {
                foreach ($unusedMedia as $unused) {
                    $m = $this->media->findById((int) $unused['id']);
                    if ($m !== null) {
                        // Removes the original plus every variant file (thumbnails/WebP).
                        $this->uploadService->deleteFile($m);
                    }
                    $this->media->delete((int) $unused['id']);
                }
                $output->writeln("  <info>Deleted {$count} unused media record(s) and file(s).</info>");
            } elseif ($count > 0) {
                $output->writeln('  <comment>Would delete unused media records and files.</comment>');
            }
        }

        // ── Pageviews ─────────────────────────────────────────────────
        if ($runAll || $input->getOption('pageviews') !== false) {
            $days   = $input->getOption('pageviews');
            $days   = is_string($days) ? max(1, (int) $days) : 365;
            $prefix = VL_DB_PREFIX;

            $cutoff   = date('Y-m-d H:i:s', time() - ($days * 86400));
            $oldCount = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM {$prefix}pageviews WHERE created_at < ?",
                [$cutoff]
            );
            $output->writeln("Pageviews older than {$days} days: {$oldCount}");

            if (!$dryRun && $oldCount > 0) {
                $deleted = (int) $this->db->executeStatement(
                    "DELETE FROM {$prefix}pageviews WHERE created_at < ?",
                    [$cutoff]
                );
                $output->writeln("  <info>Deleted {$deleted} old pageview(s).</info>");
            } elseif ($oldCount > 0) {
                $output->writeln('  <comment>Would delete old pageviews.</comment>');
            }
        }

        // ── OPTIMIZE TABLE / VACUUM ────────────────────────────────────
        if ($runAll || $input->getOption('optimize-tables')) {
            $prefix = VL_DB_PREFIX;
            $output->writeln('Optimizing tables…');

            if ($dryRun) {
                $output->writeln('  <comment>Would run table optimization / VACUUM on all tables.</comment>');
            } else {
                try {
                    $schemaManager  = $this->db->createSchemaManager();
                    $existingTables = array_map('strtolower', $schemaManager->listTableNames());
                    $platformClass  = strtolower($this->db->getDatabasePlatform()::class);

                    if (str_contains($platformClass, 'sqlite') || str_contains($platformClass, 'postgres') || str_contains($platformClass, 'pgsql')) {
                        $this->db->executeStatement("VACUUM");
                        $output->writeln("  <info>Optimized database (VACUUM executed)</info>");
                    } else {
                        foreach (self::ALL_TABLES as $table) {
                            $fullName = strtolower($prefix . $table);
                            if (!in_array($fullName, $existingTables, true)) {
                                continue;
                            }
                            $quoted = $this->db->quoteIdentifier($prefix . $table);
                            $this->db->executeStatement("OPTIMIZE TABLE {$quoted}");
                            $output->writeln("  <info>Optimized {$prefix}{$table}</info>");
                        }
                    }
                } catch (\Throwable $e) {
                    $output->writeln("  <error>Failed to optimize database/tables: {$e->getMessage()}</error>");
                    $exitCode = Command::FAILURE;
                }
            }
        }

        $output->writeln('');
        $output->writeln($dryRun
            ? '<comment>Dry run complete. Run without --dry-run to apply.</comment>'
            : '<info>Done.</info>');

        return $exitCode;
    }
}
