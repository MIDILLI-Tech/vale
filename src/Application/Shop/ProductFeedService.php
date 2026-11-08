<?php

declare(strict_types=1);

namespace ValeApp\Application\Shop;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class ProductFeedService
{
    private const FEED_VERSION = '2.0';

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly MediaRepositoryInterface $media,
        private readonly string $siteUrl,
        private readonly string $currency = 'EUR',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?SettingsRepositoryInterface $settings = null,
    ) {
    }

    public function generateXml(): string
    {
        $doc               = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', self::FEED_VERSION);
        $rss->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:g',
            'http://base.google.com/ns/1.0',
        );
        $doc->appendChild($rss);

        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);

        $channel->appendChild($doc->createElement('title', 'Product Feed'));
        $channel->appendChild($doc->createElement('link', $this->siteUrl));
        $channel->appendChild($doc->createElement('description', 'Google Merchant Center product feed'));

        $page    = 1;
        $perPage = 200;

        do {
            $products = $this->products->findAll(
                new Pagination($page, $perPage),
                ['status' => 'published'],
            );

            foreach ($products as $product) {
                if ($product->isGmcExclude()) {
                    continue;
                }

                if ($product->getType() === 'variable') {
                    $variants = $this->buildVariants($doc, $product);
                    if ($variants !== []) {
                        foreach ($variants as $variant) {
                            $channel->appendChild($variant);
                        }
                    } else {
                        $item = $this->buildItem($doc, $product);
                        if ($item !== null) {
                            $channel->appendChild($item);
                        }
                    }
                } else {
                    $item = $this->buildItem($doc, $product);
                    if ($item !== null) {
                        $channel->appendChild($item);
                    }
                }
            }

            $page++;
        } while (count($products) === $perPage);

        return $doc->saveXML();
    }

    private function buildItem(\DOMDocument $doc, Product $product): ?\DOMElement
    {
        if ($product->getSku() === null || $product->getSku() === '') {
            return null;
        }

        $item = $doc->createElement('item');

        $this->appendG($doc, $item, 'id', $product->getSku());
        $this->appendG($doc, $item, 'title', $this->sanitize($product->getName()), true);
        $this->appendG($doc, $item, 'description', $this->sanitize(
            $product->getMetaDescription() ?? $product->getShortDescription() ?? $product->getDescription() ?? '',
        ), true);
        $this->appendG($doc, $item, 'link', $this->productUrl($product));
        $this->appendG($doc, $item, 'availability', $this->mapAvailability($product));
        $this->appendG($doc, $item, 'price', $this->formatPrice($product->getPrice()));

        if ($product->isSaleActive() && $product->getSalePrice() !== null) {
            $this->appendG($doc, $item, 'sale_price', $this->formatPrice($product->getSalePrice()));
        }

        if ($product->getGtin() !== null) {
            $this->appendG($doc, $item, 'gtin', $product->getGtin());
        }
        if ($product->getMpn() !== null) {
            $this->appendG($doc, $item, 'mpn', $product->getMpn());
        }
        if ($product->getBrand() !== null) {
            $this->appendG($doc, $item, 'brand', $product->getBrand());
        }
        if ($product->getGtin() === null && $product->getMpn() === null && $product->getBrand() === null) {
            // Required by Google for products without brand/gtin/mpn,
            // otherwise the item gets disapproved for missing identifiers.
            $this->appendG($doc, $item, 'identifier_exists', 'no');
        }
        if ($product->getGoogleProductCategory() !== null) {
            $this->appendG($doc, $item, 'google_product_category', $product->getGoogleProductCategory());
        }

        $this->appendG($doc, $item, 'condition', $product->getCondition());

        if ($product->getGender() !== null) {
            $this->appendG($doc, $item, 'gender', $product->getGender());
        }
        if ($product->getAgeGroup() !== null) {
            $this->appendG($doc, $item, 'age_group', $product->getAgeGroup());
        }
        if ($product->getColor() !== null) {
            $this->appendG($doc, $item, 'color', $product->getColor());
        }
        if ($product->getSize() !== null) {
            $this->appendG($doc, $item, 'size', $product->getSize());
        }
        if ($product->getMaterial() !== null) {
            $this->appendG($doc, $item, 'material', $product->getMaterial());
        }
        if ($product->getPattern() !== null) {
            $this->appendG($doc, $item, 'pattern', $product->getPattern());
        }

        foreach (['0', '1', '2', '3', '4'] as $i) {
            $getter = 'getCustomLabel' . $i;
            $val    = $product->$getter();
            if ($val !== null) {
                $this->appendG($doc, $item, 'custom_label_' . $i, $val);
            }
        }

        if ($product->getWeight() !== null) {
            $this->appendG($doc, $item, 'shipping_weight', number_format($product->getWeight(), 2, '.', '') . ' kg');
        }

        if ($product->getShippingCost() !== null && $product->getShippingCost() > 0) {
            $shipping    = $doc->createElement('g:shipping');
            $shipCountry = strtoupper(trim((string) ($this->settings?->get('shop_country', 'US') ?? 'US')));
            if ($shipCountry === '') {
                $shipCountry = 'US';
            }
            $country  = $doc->createElement('g:country', $shipCountry);
            $service  = $doc->createElement('g:service', 'Standard');
            $priceVal = $this->formatPrice($product->getShippingCost());
            $gprice   = $doc->createElement('g:price', $priceVal);
            $shipping->appendChild($country);
            $shipping->appendChild($service);
            $shipping->appendChild($gprice);
            $item->appendChild($shipping);
        }

        $this->addImages($doc, $item, $product);

        return $item;
    }

    /** @return \DOMElement[] */
    private function buildVariants(\DOMDocument $doc, Product $product): array
    {
        $items = [];
        try {
            $variations = $this->products->findVariationsByProductId($product->getId() ?? 0);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch variations for product #{id}: {msg}', [
                'id'  => $product->getId(),
                'msg' => $e->getMessage(),
            ]);
            return [];
        }

        foreach ($variations as $v) {
            $varSku = !empty($v['sku']) ? $v['sku'] : $product->getSku() . '-' . $v['id'];
            if (empty($varSku)) {
                continue;
            }

            $item = $doc->createElement('item');

            $this->appendG($doc, $item, 'id', (string) $varSku);
            $this->appendG($doc, $item, 'item_group_id', (string) $product->getSku());
            $this->appendG($doc, $item, 'title', $this->sanitize($product->getName()), true);
            $this->appendG($doc, $item, 'description', $this->sanitize(
                $product->getMetaDescription() ?? $product->getShortDescription() ?? $product->getDescription() ?? '',
            ), true);
            $this->appendG($doc, $item, 'link', $this->productUrl($product));

            $varPrice     = !empty($v['price']) ? (float) $v['price'] : $product->getPrice();
            $varSalePrice = !empty($v['sale_price']) ? (float) $v['sale_price'] : null;

            $varStockStatus = $v['stock_status'] ?? 'in_stock';
            $this->appendG($doc, $item, 'availability', $varStockStatus === 'in_stock' ? 'in_stock' : 'out_of_stock');
            $this->appendG($doc, $item, 'price', $this->formatPrice($varPrice));
            if ($varSalePrice !== null) {
                $this->appendG($doc, $item, 'sale_price', $this->formatPrice($varSalePrice));
            }

            if ($product->getGtin() !== null) {
                $this->appendG($doc, $item, 'gtin', $product->getGtin());
            }
            if ($product->getMpn() !== null) {
                $this->appendG($doc, $item, 'mpn', $product->getMpn());
            }
            if ($product->getBrand() !== null) {
                $this->appendG($doc, $item, 'brand', $product->getBrand());
            }
            if ($product->getGtin() === null && $product->getMpn() === null && $product->getBrand() === null) {
                $this->appendG($doc, $item, 'identifier_exists', 'no');
            }
            $this->appendG($doc, $item, 'condition', $product->getCondition());

            $attrs = [];
            if (is_string($v['attributes'] ?? null)) {
                $attrs = json_decode($v['attributes'], true) ?? [];
            } elseif (is_array($v['attributes'] ?? null)) {
                $attrs = $v['attributes'];
            }
            foreach ($attrs as $key => $val) {
                $gKey = match (strtolower((string) $key)) {
                    'color'     => 'color',
                    'size'      => 'size',
                    'material'  => 'material',
                    'pattern'   => 'pattern',
                    'gender'    => 'gender',
                    'age_group' => 'age_group',
                    default     => null,
                };
                if ($gKey !== null && is_string($val)) {
                    $this->appendG($doc, $item, $gKey, $val);
                }
            }

            $varImageId = !empty($v['image_id']) ? (int) $v['image_id'] : null;
            if ($varImageId !== null) {
                try {
                    $media = $this->media->findById($varImageId);
                    if ($media !== null) {
                        $this->appendG($doc, $item, 'image_link', $this->mediaUrl($media->getUrl()));
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to load variation image #{id}: {msg}', [
                        'id'  => $varImageId,
                        'msg' => $e->getMessage(),
                    ]);
                }
            } elseif ($product->getFeaturedImageId() !== null) {
                $this->addFeaturedImage($doc, $item, $product);
            }

            $items[] = $item;
        }

        return $items;
    }

    private function addImages(\DOMDocument $doc, \DOMElement $item, Product $product): void
    {
        $this->addFeaturedImage($doc, $item, $product);

        try {
            $galleryIds = $this->products->findGalleryImageIds($product->getId() ?? 0);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch gallery for product #{id}: {msg}', [
                'id'  => $product->getId(),
                'msg' => $e->getMessage(),
            ]);
            return;
        }

        foreach ($galleryIds as $mediaId) {
            try {
                $media = $this->media->findById($mediaId);
                // Gallery may hold videos; feeds only accept image links.
                if ($media !== null && $media->isImage()) {
                    $this->appendG($doc, $item, 'additional_image_link', $this->mediaUrl($media->getUrl()));
                }
            } catch (\Throwable) {
            }
        }
    }

    private function addFeaturedImage(\DOMDocument $doc, \DOMElement $item, Product $product): void
    {
        if ($product->getFeaturedImageId() === null) {
            return;
        }
        try {
            $media = $this->media->findById($product->getFeaturedImageId());
            if ($media !== null) {
                $this->appendG($doc, $item, 'image_link', $this->mediaUrl($media->getUrl()));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load featured image #{id} for product #{pid}: {msg}', [
                'id'  => $product->getFeaturedImageId(),
                'pid' => $product->getId(),
                'msg' => $e->getMessage(),
            ]);
        }
    }

    private function appendG(\DOMDocument $doc, \DOMElement $parent, string $name, string $value, bool $cdata = false): void
    {
        $el = $doc->createElement('g:' . $name);

        if ($cdata) {
            $el->appendChild($doc->createCDATASection($value));
        } else {
            $el->appendChild($doc->createTextNode($value));
        }

        $parent->appendChild($el);
    }

    private function sanitize(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        return trim($text);
    }

    private function formatPrice(float $amount): string
    {
        return number_format($amount, 2, '.', '') . ' ' . $this->currency;
    }

    private function productUrl(Product $product): string
    {
        $shopPrefix = '/' . ltrim($this->settings?->get('routing_shop_prefix', '/shop') ?? '/shop', '/');
        return rtrim($this->siteUrl, '/') . $shopPrefix . '/' . ltrim((string) $product->getSlug(), '/');
    }

    private function mediaUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        return rtrim($this->siteUrl, '/') . '/' . ltrim($path, '/');
    }

    private function mapAvailability(Product $product): string
    {
        return match ($product->getStockStatus()) {
            'in_stock'                  => 'in_stock',
            'on_backorder', 'backorder' => 'backorder',
            'out_of_stock'              => 'out_of_stock',
            default                     => 'in_stock',
        };
    }
}
