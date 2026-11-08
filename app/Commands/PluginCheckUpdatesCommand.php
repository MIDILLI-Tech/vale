<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Application\Plugin\PluginUpdateService;

/**
 * Check plugin update feeds.
 *
 * Usage:  php vale plugin:check-updates [slug]
 */
final class PluginCheckUpdatesCommand extends Command
{
    public function __construct(
        private readonly PluginUpdateService $updater,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:check-updates')
            ->setDescription('Check plugin update feeds and cache the results')
            ->addArgument('slug', InputArgument::OPTIONAL, 'Only check this plugin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = trim((string) $input->getArgument('slug'));

        try {
            $results = $slug !== ''
                ? [$slug => $this->updater->checkOne($slug)]
                : $this->updater->checkAll();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($results === []) {
            $output->writeln('<comment>No installed plugin declares an update source.</comment>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Plugin', 'Installed', 'Latest', 'Status', 'Detail']);

        $failed = false;
        foreach ($results as $plugin => $status) {
            $error = isset($status['error']) && (string) $status['error'] !== ''
                ? (string) $status['error']
                : null;

            if ($error !== null) {
                $failed = true;
            }

            $table->addRow([
                $plugin,
                (string) ($status['current_version'] ?? '—'),
                (string) ($status['version'] ?? '—'),
                $error !== null
                    ? 'error'
                    : (!empty($status['available']) ? 'update available' : 'up to date'),
                $error ?? implode('; ', array_map('strval', (array) ($status['changelog'] ?? []))),
            ]);
        }

        $table->render();

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
