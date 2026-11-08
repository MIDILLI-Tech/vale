<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Application\Plugin\PluginManager;
use ValeApp\Application\Plugin\PluginMigrator;
use ValeApp\Infrastructure\Cache\PageCache;

/**
 * Activate a plugin that lives in content/plugins/<slug>/.
 *
 * Usage:  php vale plugin:enable my-plugin [--dry-run]
 */
final class PluginEnableCommand extends Command
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly PluginMigrator $migrator,
        private readonly ?PageCache $pageCache = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:enable')
            ->setDescription('Activate an installed plugin')
            ->addArgument('slug', InputArgument::REQUIRED, 'Plugin directory name inside content/plugins/')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug   = (string) $input->getArgument('slug');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $this->plugins->enable($slug, $dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>%s plugin "%s".</info>',
            $dryRun ? 'Would enable' : 'Enabled',
            $slug,
        ));

        if ($dryRun) {
            return Command::SUCCESS;
        }

        // Activation runs the plugin's own migrations (content/plugins/<slug>/migrations/).
        try {
            $applied = $this->migrator->migrate($slug)['applied'];
            if ($applied !== []) {
                $output->writeln(sprintf('<info>Applied %d plugin migration(s).</info>', count($applied)));
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Plugin enabled, but its migrations failed: ' . $e->getMessage() . '</error>');
            $output->writeln('<comment>Fix the migration, then run: php vale plugin:migrate ' . $slug . '</comment>');

            return Command::FAILURE;
        }

        $this->pageCache?->flush();

        return Command::SUCCESS;
    }
}
