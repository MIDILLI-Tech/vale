<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Application\Plugin\PluginUpdateService;

/**
 * Install a plugin from a local ZIP archive.
 *
 * Usage:  php vale plugin:install path/to/plugin.zip [--dry-run]
 */
final class PluginInstallCommand extends Command
{
    public function __construct(
        private readonly PluginUpdateService $updater,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:install')
            ->setDescription('Install a plugin from a ZIP archive (installed disabled)')
            ->addArgument('zip', InputArgument::REQUIRED, 'Path to the plugin ZIP archive')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate the package without installing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $zipPath = (string) $input->getArgument('zip');
        $dryRun  = (bool) $input->getOption('dry-run');

        if (!is_file($zipPath)) {
            $output->writeln('<error>The plugin ZIP file does not exist: ' . $zipPath . '</error>');

            return Command::INVALID;
        }

        try {
            $result = $this->updater->installFromArchive($zipPath, basename($zipPath), $dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>Plugin installation failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln(sprintf(
                '<info>Would install plugin "%s" v%s as "%s".</info>',
                $result['name'],
                $result['version'],
                $result['slug'],
            ));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Installed plugin "%s" v%s as "%s" (disabled).</info>',
            $result['name'],
            $result['version'],
            $result['slug'],
        ));
        $output->writeln(
            '<comment>Review the plugin, then run: php vale plugin:enable ' . $result['slug'] . '</comment>',
        );

        return Command::SUCCESS;
    }
}
