<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\Application\Maintenance\ExportImportService;
use ValeApp\Infrastructure\Cache\PageCache;

final class ImportCommand extends Command
{
    use Concerns\FlushesPageCache;

    public function __construct(
        private readonly ExportImportService $exportImportService,
        private readonly ?PageCache $pageCache = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('import')
            ->setDescription('Import site data from a JSON file')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to the export JSON or ZIP file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do not write to database, just show what would be imported')
            ->addOption('include-secrets', null, InputOption::VALUE_NONE, 'Also import secret settings (API keys, payment secrets). By default secrets are skipped.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $filePath = (string) $input->getOption('file');
        $dryRun   = $input->getOption('dry-run');

        if ($filePath === '' || !file_exists($filePath)) {
            $io->error('File not found: ' . $filePath);
            return Command::FAILURE;
        }

        $io->title('Vale Import');
        $io->text('Importing from: ' . $filePath);

        $isZip = str_ends_with(strtolower($filePath), '.zip');
        $json  = '';

        if ($isZip) {
            if (!class_exists(\ZipArchive::class)) {
                $io->error('ZIP extension not found.');
                return Command::FAILURE;
            }

            $zip = new \ZipArchive();
            if ($zip->open($filePath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($entryName), '.json')) {
                        $json = $zip->getFromIndex($i);
                        break;
                    }
                }
                $zip->close();
            }

            if (empty($json)) {
                $io->error('No JSON file found inside the ZIP archive.');
                return Command::FAILURE;
            }
        } else {
            $json = file_get_contents($filePath);
        }

        $data = json_decode($json, true);

        if ($data === null) {
            $io->error('Invalid JSON data.');
            return Command::FAILURE;
        }

        $results = $this->exportImportService->import($data, $dryRun, [
            'include_secrets' => (bool) $input->getOption('include-secrets'),
        ]);

        if ($results['success']) {
            $this->flushPageCache($this->pageCache, (bool) $dryRun, $io);
            $io->success('Import completed successfully.' . ($dryRun ? ' (DRY RUN)' : ''));
            foreach ($results['imported'] as $type => $count) {
                $skipped = $results['skipped'][$type] ?? 0;
                $io->text("- {$type}: {$count} imported, {$skipped} skipped");
            }
            foreach ($results['warnings'] ?? [] as $warning) {
                $io->warning($warning);
            }
            $io->note('Media files are NOT part of the export (database rows only). Copy the uploads directory, then run: php vale media:scan');
            return Command::SUCCESS;
        } else {
            $io->error('Import failed: ' . ($results['errors']['global'] ?? 'Unknown error'));
            return Command::FAILURE;
        }
    }
}
