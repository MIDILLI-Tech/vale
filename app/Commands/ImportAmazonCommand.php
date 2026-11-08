<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\App\Commands\Concerns\ProductImportCommandTrait;
use ValeApp\Application\Shop\ProductImporter;
use ValeApp\Infrastructure\Cache\PageCache;

final class ImportAmazonCommand extends Command
{
    use ProductImportCommandTrait;

    public function __construct(
        private readonly ProductImporter $importer,
        private readonly ?PageCache $pageCache = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('import:amazon')
            ->setDescription('Import products from an Amazon inventory report')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to Amazon inventory report file (CSV/TXT/XLSM/XLSX)')
            ->addOption('import-media', null, InputOption::VALUE_NONE, 'Download and import product images (if URLs are present in the report)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview import without writing to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Vale Amazon Product Importer');

        $filePath = $input->getOption('file');
        if (!$filePath || !file_exists($filePath)) {
            $io->error("Amazon export file not found: {$filePath}");

            return Command::FAILURE;
        }

        $dryRun      = (bool) $input->getOption('dry-run');
        $importMedia = (bool) $input->getOption('import-media');
        $mode        = $dryRun ? 'Dry run (no writes)' : ($importMedia ? 'With image downloads' : 'Without image downloads');
        $io->text("Reading: {$filePath} ({$mode})");

        $res = $this->importer->importAmazon($filePath, $importMedia, $dryRun);

        return $this->reportProductImportResult($io, $res);
    }
}
