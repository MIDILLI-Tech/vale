<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Application\Plugin\PluginManager;

/**
 * List every plugin dropped into content/plugins/ with its activation state.
 *
 * Usage:  php vale plugin:list
 */
final class PluginListCommand extends Command
{
    public function __construct(
        private readonly PluginManager $plugins,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('plugin:list')
            ->setDescription('List installed plugins and their activation state');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->plugins->isDisabledByOperator()) {
            $output->writeln('<comment>All plugins are disabled by the operator (VL_DISABLE_PLUGINS or content/plugins/.disabled).</comment>');
        } elseif (!$this->plugins->isGloballyEnabled()) {
            $output->writeln('<comment>All plugins are switched off globally (Plugins screen). Activation state is kept.</comment>');
        }

        $rows = $this->plugins->findAll();
        if ($rows === []) {
            $output->writeln('<comment>No plugins installed. Drop a plugin directory into content/plugins/.</comment>');

            return Command::SUCCESS;
        }

        $failures = $this->plugins->failures();
        $table    = new Table($output);
        $table->setHeaders(['Slug', 'Name', 'Version', 'Requires', 'Status', 'Last Error']);

        foreach ($rows as $row) {
            $status = $row['error'] !== null
                ? 'invalid'
                : ($row['enabled'] ? 'enabled' : 'disabled');

            $requires = [];
            if ($row['requires_vale'] !== null) {
                $requires[] = 'Vale ' . $row['requires_vale'];
            }
            if ($row['requires_php'] !== null) {
                $requires[] = 'PHP ' . $row['requires_php'];
            }
            if (($row['requires_ext'] ?? []) !== []) {
                $requires[] = 'ext: ' . implode(', ', $row['requires_ext']);
            }
            foreach ($row['requires_plugin'] ?? [] as $dependency => $constraint) {
                $requires[] = 'plugin: ' . $dependency . ($constraint !== null ? ' ' . $constraint : '');
            }

            $table->addRow([
                $row['slug'],
                $row['name'],
                $row['version'],
                $requires === [] ? '-' : implode(' · ', $requires),
                $status,
                $row['error'] ?? ($failures[$row['slug']]['message'] ?? ''),
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
