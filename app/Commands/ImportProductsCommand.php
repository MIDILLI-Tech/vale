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

final class ImportProductsCommand extends Command
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
            ->setName('import:products')
            ->setDescription('Import products from a Vale product CSV export file')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to Vale products CSV file')
            ->addOption('import-media', null, InputOption::VALUE_NONE, 'Download and import product/variant images if URLs are present')
            ->addOption('update-existing', null, InputOption::VALUE_NONE, 'Update existing products matched by slug instead of skipping')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview import without writing to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Vale Product CSV Importer');

        $filePath = $input->getOption('file');
        if (!$filePath || !file_exists($filePath)) {
            $io->error("Product CSV file not found: {$filePath}");

            return Command::FAILURE;
        }

        $dryRun         = (bool) $input->getOption('dry-run');
        $importMedia    = (bool) $input->getOption('import-media');
        $updateExisting = (bool) $input->getOption('update-existing');

        $mode = $dryRun ? 'Dry run (no writes)' : ($importMedia ? 'With image downloads' : 'Without image downloads');
        $io->text("Reading: {$filePath} ({$mode})" . ($updateExisting ? ' (update existing)' : ' (skip existing)'));

        $res = $this->importer->importProducts($filePath, $importMedia, $dryRun, $updateExisting);

        return $this->reportProductImportResult($io, $res);
    }
}
