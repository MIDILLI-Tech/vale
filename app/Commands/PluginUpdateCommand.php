<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Application\Plugin\PluginCapabilitiesChangedException;
use ValeApp\Application\Plugin\PluginUpdateService;
use ValeApp\Infrastructure\Cache\PageCache;

/**
 * Download, verify and install one plugin update.
 *
 * Usage:  php vale plugin:update <slug> [--acknowledge-capabilities]
 */
final class PluginUpdateCommand extends Command
{
    public function __construct(
        private readonly PluginUpdateService $updater,
        private readonly ?PageCache $pageCache = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:update')
            ->setDescription('Install the latest release of a plugin from its update feed')
            ->addArgument('slug', InputArgument::REQUIRED, 'Plugin slug')
            ->addOption(
                'acknowledge-capabilities',
                null,
                InputOption::VALUE_NONE,
                'Approve capabilities the installed version does not declare',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = trim((string) $input->getArgument('slug'));

        try {
            try {
                $result = $this->updater->update($slug);
            } catch (PluginCapabilitiesChangedException $e) {
                $output->writeln(sprintf(
                    '<comment>Plugin "%s" v%s adds capabilities: %s.</comment>',
                    $slug,
                    $e->version,
                    implode(', ', $e->capabilities),
                ));

                if (!$input->getOption('acknowledge-capabilities')) {
                    $output->writeln('Re-run with --acknowledge-capabilities to approve them.');

                    return Command::FAILURE;
                }

                $output->writeln('Approved with --acknowledge-capabilities.');

                $result = $this->updater->update($slug, $e->version);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Plugin update failed: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $this->pageCache?->flush();

        $output->writeln(sprintf(
            '<info>Updated plugin "%s" from v%s to v%s.</info>',
            $slug,
            $result['from'],
            $result['to'],
        ));

        if ($result['migrations'] > 0) {
            $output->writeln(sprintf(
                '<info>Applied %d plugin migration(s).</info>',
                $result['migrations'],
            ));
        }

        return Command::SUCCESS;
    }
}
