<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\Application\Maintenance\ExportImportService;

final class ExportCommand extends Command
{
    public function __construct(
        private readonly ExportImportService $exportImportService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('export')
            ->setDescription('Export site data to a JSON file')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Types to export (pages, posts, products, orders, users, categories, tags, seo_meta, site_settings, media, content_types, menus, comments, forms, coupons, redirects, tax_rates, membership_plans)', ['pages', 'posts', 'products', 'users', 'categories', 'tags', 'seo_meta', 'site_settings', 'media', 'content_types', 'menus', 'comments', 'forms', 'coupons', 'redirects', 'tax_rates', 'membership_plans'])
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path', 'export-' . date('Y-m-d-His') . '.json')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without writing any files')
            ->addOption('include-secrets', null, InputOption::VALUE_NONE, 'Include secret settings (API keys, payment secrets) in the export file. By default secrets are stripped.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $types      = $input->getOption('type');
        $outputPath = $input->getOption('output');

        $io->title('Vale Export');

        $validTypes = ['pages', 'posts', 'products', 'orders', 'users', 'categories', 'tags', 'seo_meta', 'site_settings', 'media', 'content_types', 'menus', 'comments', 'forms', 'coupons', 'redirects', 'tax_rates', 'membership_plans'];
        $invalid    = array_diff($types, $validTypes);
        if ($invalid !== []) {
            $io->error('Invalid export type(s): ' . implode(', ', $invalid));
            $io->text('Allowed types: ' . implode(', ', $validTypes));
            return Command::FAILURE;
        }

        $io->text('Exporting: ' . implode(', ', $types));

        $data = $this->exportImportService->export($types, [
            'include_secrets' => (bool) $input->getOption('include-secrets'),
        ]);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ((bool) $input->getOption('dry-run')) {
            $io->text('DRY RUN — no files will be written.');

            $rows = [];
            foreach ($data['data'] ?? [] as $type => $records) {
                $rows[] = [$type, is_countable($records) ? count($records) : 0];
            }
            if ($rows !== []) {
                $io->table(['Type', 'Records'], $rows);
            }

            $format = str_ends_with(strtolower($outputPath), '.zip') ? 'zip' : 'json';
            $io->text(sprintf('Would write: %s (%s)', $outputPath, $format));
            if (($data['secrets_stripped'] ?? 0) > 0) {
                $io->note(sprintf('%d secret setting(s) would be stripped from the export. Pass --include-secrets to keep them.', $data['secrets_stripped']));
            }
            $io->success('Dry run complete. Run without --dry-run to actually export.');
            return Command::SUCCESS;
        }

        $isZip = str_ends_with(strtolower($outputPath), '.zip');

        if ($isZip) {
            try {
                $jsonFilename = str_replace('.zip', '.json', basename($outputPath));
                $tempZip      = $this->exportImportService->createZip($json, $jsonFilename);
                if (rename($tempZip, $outputPath)) {
                    $io->success('Export completed successfully as ZIP: ' . $outputPath);
                    return Command::SUCCESS;
                }
            } catch (\Exception $e) {
                $io->error('ZIP export failed: ' . $e->getMessage() . '. Falling back to JSON.');
                $outputPath = str_replace('.zip', '.json', $outputPath);
            }
        }

        if (file_put_contents($outputPath, $json) === false) {
            $io->error('Failed to write export file to: ' . $outputPath);
            return Command::FAILURE;
        }

        if (($data['secrets_stripped'] ?? 0) > 0) {
            $io->note(sprintf('%d secret setting(s) stripped from the export. Pass --include-secrets to keep them.', $data['secrets_stripped']));
        }
        $io->success('Export completed successfully: ' . $outputPath);

        return Command::SUCCESS;
    }
}
