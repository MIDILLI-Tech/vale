<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Application\Plugin\PluginManager;
use ValeApp\Infrastructure\Cache\PageCache;

/**
 * Deactivate a plugin without deleting its files.
 *
 * Works from the CLI even when a plugin breaks the admin panel — the reason
 * this command exists. `content/plugins/.disabled` (or VL_DISABLE_PLUGINS=1)
 * is the heavier hammer that disables every plugin at once.
 *
 * Usage:  php vale plugin:disable my-plugin [--dry-run]
 */
final class PluginDisableCommand extends Command
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly ?PageCache $pageCache = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:disable')
            ->setDescription('Deactivate a plugin (keeps its files and settings)')
            ->addArgument('slug', InputArgument::REQUIRED, 'Plugin directory name inside content/plugins/')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug   = (string) $input->getArgument('slug');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $this->plugins->disable($slug, $dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>%s plugin "%s".</info>',
            $dryRun ? 'Would disable' : 'Disabled',
            $slug,
        ));

        $this->pageCache?->flush();

        return Command::SUCCESS;
    }
}
