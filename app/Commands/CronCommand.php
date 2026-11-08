<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Application\Cron\CronService;

/**
 * Run all due background jobs.
 *
 * Usage:
 *   php vale cron:run
 *   php vale cron:run --force-rates
 *   php vale cron:run --force-update
 *   php vale cron:run --clean-cache
 *   php vale cron:run --prune-revisions
 *   php vale cron:run --prune-revisions=5
 *
 * Typically called from a system cron job every minute:
 *   * * * * * /usr/bin/php /var/www/vale cron:run >> /var/log/vale-cron.log 2>&1
 */
final class CronCommand extends Command
{
    public function __construct(private readonly CronService $cronService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('cron:run')
            ->setDescription('Run all scheduled background jobs (campaigns, automations, posts, products, exchange rates)')
            ->addOption(
                'force-rates',
                null,
                InputOption::VALUE_NONE,
                'Force exchange rate refresh regardless of cache interval'
            )
            ->addOption(
                'force-update',
                null,
                InputOption::VALUE_NONE,
                'Force update check regardless of auto-check interval'
            )
            ->addOption(
                'clean-cache',
                null,
                InputOption::VALUE_NONE,
                'Clear the data cache'
            )
            ->addOption(
                'prune-revisions',
                null,
                InputOption::VALUE_OPTIONAL,
                'Prune old revisions per item (optionally specify keep count, e.g. --prune-revisions=5)',
                false
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview due jobs without executing them'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $forceRates     = $input->getOption('force-rates');
        $forceUpdate    = $input->getOption('force-update');
        $cleanCache     = $input->getOption('clean-cache');
        $pruneRevisions = $input->getOption('prune-revisions');

        if ((bool) $input->getOption('dry-run')) {
            try {
                $preview = $this->cronService->previewDue($forceRates, $forceUpdate);
            } catch (\Throwable $e) {
                $output->writeln('<error>Cron preview failed: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }

            $output->writeln('<comment>DRY RUN — no jobs will be executed.</comment>');
            $output->writeln(sprintf(
                '  Newsletter enabled        : %s',
                $preview['newsletter_enabled'] ? 'yes' : 'no'
            ));
            $output->writeln(sprintf('  Campaigns due             : %d', $preview['campaigns_due']));
            $output->writeln(sprintf('  Automations due           : %d', $preview['automations_due']));
            $output->writeln(sprintf('  Posts scheduled           : %d', $preview['posts_due']));
            $output->writeln(sprintf('  Posts expiring            : %d', $preview['posts_expiring']));
            $output->writeln(sprintf('  Posts going public        : %d', $preview['posts_go_public']));
            $output->writeln(sprintf('  Products scheduled        : %d', $preview['products_due']));
            $output->writeln(sprintf('  Products expiring         : %d', $preview['products_expiring']));
            $output->writeln(sprintf('  Products going public     : %d', $preview['products_go_public']));
            $output->writeln(sprintf(
                '  Rates refresh due         : %s',
                !$preview['rates_configured'] ? 'n/a (no provider)' : ($preview['rates_due'] ? 'yes' : 'no')
            ));
            $output->writeln(sprintf('  Update check due          : %s', $preview['update_check_due'] ? 'yes' : 'no'));

            if ($cleanCache) {
                $output->writeln(sprintf(
                    '  Data cache files to clear : %d',
                    $this->cronService->countDataCacheFiles()
                ));
            }
            if ($pruneRevisions !== false) {
                $keepCount = is_string($pruneRevisions) ? max(1, (int) $pruneRevisions) : 10;
                $output->writeln(sprintf(
                    '  Revisions to prune        : %d (keeping latest %d per item)',
                    $this->cronService->countPrunableRevisions($keepCount),
                    $keepCount
                ));
            }

            $output->writeln('<comment>Dry run complete. Run without --dry-run to execute the due jobs.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>Running scheduled jobs…</info>');
        if ($forceRates) {
            $output->writeln('  <comment>--force-rates enabled: will refresh exchange rates</comment>');
        }
        if ($forceUpdate) {
            $output->writeln('  <comment>--force-update enabled: will check for updates</comment>');
        }

        try {
            $result = $this->cronService->runDue($forceRates, $forceUpdate);
        } catch (\Throwable $e) {
            $output->writeln('<error>Cron failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('  Campaigns sent           : %d', $result['campaigns_sent']));
        $output->writeln(sprintf('  Automations sent         : %d', $result['automations_sent']));
        $output->writeln(sprintf('  Posts published          : %d', $result['posts_published']));
        $output->writeln(sprintf('  Posts expired            : %d', $result['posts_expired']));
        $output->writeln(sprintf('  Posts gone public        : %d', $result['posts_go_public']));
        $output->writeln(sprintf('  Swaps performed          : %d', $result['swaps_processed']));
        $output->writeln(sprintf('  Products published       : %d', $result['products_published']));
        $output->writeln(sprintf('  Products expired         : %d', $result['products_expired']));
        $output->writeln(sprintf('  Products gone public     : %d', $result['products_go_public']));
        $output->writeln(sprintf('  Subscriptions reconciled : %d', $result['subscriptions_reconciled']));
        $output->writeln(sprintf('  Trials expired           : %d', $result['trials_expired']));
        $output->writeln(sprintf('  Abandoned carts          : %d', $result['abandoned_carts_sent']));
        $output->writeln(sprintf('  Rates updated            : %s', $result['rates_updated'] ? 'yes' : 'no'));

        if ($result['update_checked']) {
            if ($result['update_available']) {
                $output->writeln(sprintf('  Update checked           : <comment>update available (v%s)</comment>', $result['update_version']));
            } else {
                $output->writeln('  Update checked           : up to date');
            }
        } else {
            $output->writeln('  Update checked           : no (not due yet)');
        }

        // ── Optional: clear data cache ────────────────────────────────
        if ($cleanCache) {
            $output->writeln('  <comment>--clean-cache enabled: clearing data cache…</comment>');
            try {
                $cleared = $this->cronService->cleanDataCache();
                $output->writeln(sprintf('  Data cache cleared: %d files removed', $cleared));
            } catch (\Throwable $e) {
                $output->writeln('<error>Data cache cleanup failed: ' . $e->getMessage() . '</error>');
            }
        }

        // ── Optional: prune revisions ─────────────────────────────────
        if ($pruneRevisions !== false) {
            $keepCount = is_string($pruneRevisions) ? max(1, (int) $pruneRevisions) : 10;
            $output->writeln(sprintf('  <comment>--prune-revisions enabled: pruning (keeping latest %d per item)…</comment>', $keepCount));
            try {
                $deleted = $this->cronService->pruneRevisions($keepCount);
                $output->writeln(sprintf('  Revisions pruned  : %d deleted', $deleted));
            } catch (\Throwable $e) {
                $output->writeln('<error>Revision pruning failed: ' . $e->getMessage() . '</error>');
            }
        }

        $output->writeln('<info>Done.</info>');

        return Command::SUCCESS;
    }
}
