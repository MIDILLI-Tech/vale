<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ValeApp\Application\Update\BackupService;

/**
 * List available backups.
 */
final class BackupListCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('backup:list')
            ->setDescription('List available backups');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backups = $this->backupService->listBackups();

        if (empty($backups)) {
            $output->writeln('<comment>No backups found.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders([
            'Name', 'Date', 'Version', 'DB Driver', 'Files', 'DB Size', 'Total Size',
        ]);

        foreach ($backups as $b) {
            $table->addRow([
                $b['name'],
                $b['date'],
                'v' . $b['version'],
                strtoupper((string) ($b['db_driver'] ?? 'UNKNOWN')),
                $b['file_count'],
                $this->formatBytes($b['db_size']),
                $this->formatBytes($b['size_bytes']),
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = (int) floor(log($bytes, 1024));
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
