<?php

declare(strict_types=1);

namespace ValeApp\App\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class ExportProductsCommand extends Command
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('export:products')
            ->setDescription('Export products to a CSV file')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path', 'products-' . date('Y-m-d-His') . '.csv')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status (published, draft, all)', 'all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview the export without writing a file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $outPath  = $input->getOption('output');
        $status   = (string) $input->getOption('status');

        $io->title('Vale Product CSV Export');

        $validStatuses = ['all', 'published', 'draft'];
        if (!in_array($status, $validStatuses, true)) {
            $io->error(sprintf('Invalid --status value "%s". Allowed: %s', $status, implode(', ', $validStatuses)));
            return Command::FAILURE;
        }

        $filters = [];
        if ($status !== 'all') {
            $filters['status'] = $status;
        }

        $pagination = new Pagination(1, 100000);
        $products   = $this->products->findAll($pagination, $filters);

        if ((bool) $input->getOption('dry-run')) {
            $io->text('DRY RUN — no file will be written.');
            $io->text(sprintf('Products matching filters (status: %s): %d', $status, count($products)));
            $io->text(sprintf('Would write: %s', $outPath));
            $io->success('Dry run complete. Run without --dry-run to actually export.');
            return Command::SUCCESS;
        }

        $headers = [
            'id', 'name', 'slug', 'sku', 'type', 'status', 'language',
            'price', 'sale_price', 'sale_price_start', 'sale_price_end',
            'short_description', 'description',
            'stock_quantity', 'stock_status',
            'weight', 'length', 'width', 'height', 'dimensions',
            'featured_image_id', 'featured_image_url', 'gallery',
            'shipping_class', 'shipping_cost', 'shipping_country_overrides',
            'vat_mode', 'vat_rate', 'vat_country_overrides',
            'download_source', 'download_url', 'download_limit', 'download_expiry_days', 'download_files',
            'meta_title', 'meta_description',
            'comment_status', 'vote_status', 'total_sold',
            'gtin', 'mpn', 'brand', 'google_product_category', 'condition',
            'gender', 'age_group', 'color', 'size', 'material', 'pattern',
            'custom_label_0', 'custom_label_1', 'custom_label_2', 'custom_label_3', 'custom_label_4', 'gmc_exclude',
            'category_ids', 'upsell_ids', 'cross_sell_ids',
            'variations',
            'created_at', 'updated_at',
        ];

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '\\');

        foreach ($products as $product) {
            $variations     = $this->products->findVariationsByProductId((int) $product->getId());
            $variationsData = [];
            foreach ($variations as $v) {
                $attrs            = is_string($v['attributes']) ? json_decode($v['attributes'], true) : $v['attributes'];
                $variationsData[] = [
                    'sku'                        => $v['sku'],
                    'price'                      => (float) $v['price'],
                    'sale_price'                 => isset($v['sale_price']) ? (float) $v['sale_price'] : null,
                    'stock_quantity'             => (int) $v['stock_quantity'],
                    'stock_status'               => $v['stock_status'],
                    'attributes'                 => $attrs ?: new \stdClass(),
                    'image_url'                  => null,
                    'download_mode'              => $v['download_mode'] ?? 'use_parent',
                    'download_url'               => $v['download_url']  ?? null,
                    'download_limit'             => (int) ($v['download_limit'] ?? 0),
                    'download_expiry_days'       => (int) ($v['download_expiry_days'] ?? 0),
                    'download_files'             => [],
                    'shipping_class'             => $v['shipping_class'] ?? 'use_parent',
                    'shipping_cost'              => isset($v['shipping_cost']) ? (float) $v['shipping_cost'] : null,
                    'shipping_country_overrides' => is_string($v['shipping_country_overrides'])
                        ? json_decode($v['shipping_country_overrides'], true)
                        : ($v['shipping_country_overrides'] ?: new \stdClass()),
                    'vat_mode'                   => $v['vat_mode'] ?? 'use_parent',
                    'vat_rate'                   => isset($v['vat_rate']) ? (float) $v['vat_rate'] : null,
                ];
            }

            $row = [
                'id'                         => $product->getId(),
                'name'                       => $product->getName(),
                'slug'                       => (string) $product->getSlug(),
                'sku'                        => $product->getSku(),
                'type'                       => $product->getType(),
                'status'                     => $product->getStatus(),
                'language'                   => $product->getLanguage(),
                'price'                      => $product->getPrice(),
                'sale_price'                 => $product->getSalePrice(),
                'sale_price_start'           => $product->getSalePriceStart()?->format('Y-m-d H:i:s') ?? '',
                'sale_price_end'             => $product->getSalePriceEnd()?->format('Y-m-d H:i:s')   ?? '',
                'short_description'          => $product->getShortDescription(),
                'description'                => $product->getDescription(),
                'stock_quantity'             => $product->getStockQuantity(),
                'stock_status'               => $product->getStockStatus(),
                'weight'                     => $product->getWeight(),
                'length'                     => $product->getLength(),
                'width'                      => $product->getWidth(),
                'height'                     => $product->getHeight(),
                'dimensions'                 => $product->getDimensions(),
                'featured_image_id'          => $product->getFeaturedImageId(),
                'featured_image_url'         => null,
                'gallery'                    => json_encode(new \stdClass()),
                'shipping_class'             => $product->getShippingClass(),
                'shipping_cost'              => $product->getShippingCost(),
                'shipping_country_overrides' => json_encode($product->getShippingCountryOverrides() ?: new \stdClass()),
                'vat_mode'                   => $product->getVatMode(),
                'vat_rate'                   => $product->getVatRate(),
                'vat_country_overrides'      => json_encode($product->getVatCountryOverrides() ?: new \stdClass()),
                'download_source'            => $product->getDownloadSource(),
                'download_url'               => $product->getDownloadUrl(),
                'download_limit'             => $product->getDownloadLimit(),
                'download_expiry_days'       => $product->getDownloadExpiryDays(),
                'download_files'             => json_encode(new \stdClass()),
                'meta_title'                 => $product->getMetaTitle(),
                'meta_description'           => $product->getMetaDescription(),
                'comment_status'             => $product->getCommentStatus(),
                'vote_status'                => $product->getVoteStatus(),
                'total_sold'                 => $product->getTotalSold(),
                'gtin'                       => $product->getGtin(),
                'mpn'                        => $product->getMpn(),
                'brand'                      => $product->getBrand(),
                'google_product_category'    => $product->getGoogleProductCategory(),
                'condition'                  => $product->getCondition(),
                'gender'                     => $product->getGender(),
                'age_group'                  => $product->getAgeGroup(),
                'color'                      => $product->getColor(),
                'size'                       => $product->getSize(),
                'material'                   => $product->getMaterial(),
                'pattern'                    => $product->getPattern(),
                'custom_label_0'             => $product->getCustomLabel0(),
                'custom_label_1'             => $product->getCustomLabel1(),
                'custom_label_2'             => $product->getCustomLabel2(),
                'custom_label_3'             => $product->getCustomLabel3(),
                'custom_label_4'             => $product->getCustomLabel4(),
                'gmc_exclude'                => $product->isGmcExclude() ? 1 : 0,
                'category_ids'               => implode(',', array_map('strval', $product->getCategoryIds())),
                'upsell_ids'                 => implode(',', $product->getUpsellIds()),
                'cross_sell_ids'             => implode(',', $product->getCrossSellIds()),
                'variations'                 => json_encode($variationsData ?: new \stdClass()),
                'created_at'                 => $product->getCreatedAt()?->format('Y-m-d H:i:s') ?? '',
                'updated_at'                 => $product->getUpdatedAt()?->format('Y-m-d H:i:s') ?? '',
            ];

            $line = [];
            foreach ($row as $value) {
                if ($value === null || $value === '') {
                    $line[] = '';
                } elseif (is_array($value) || is_object($value)) {
                    $str = json_encode($value, JSON_UNESCAPED_UNICODE);
                    if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                        $str = "'" . $str;
                    }
                    $line[] = $str;
                } else {
                    $str = (string) $value;
                    if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                        $str = "'" . $str;
                    }
                    $line[] = $str;
                }
            }
            fputcsv($stream, $line, ',', '"', '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if (file_put_contents($outPath, $csv) === false) {
            $io->error('Failed to write export file: ' . $outPath);
            return Command::FAILURE;
        }

        $io->success('Exported ' . count($products) . ' products to: ' . $outPath);

        return Command::SUCCESS;
    }
}
