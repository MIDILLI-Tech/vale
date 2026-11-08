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

final class YandexMarketFeedService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly MediaRepositoryInterface $media,
        private readonly string $siteUrl,
        private readonly string $shopName = 'My Shop',
        private readonly string $currency = 'EUR',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?SettingsRepositoryInterface $settings = null,
    ) {
    }

    public function generateYml(): string
    {
        $doc               = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $yml = $doc->createElement('yml_catalog');
        $yml->setAttribute('date', date('Y-m-d H:i'));
        $doc->appendChild($yml);

        $shop = $doc->createElement('shop');
        $yml->appendChild($shop);

        $shop->appendChild($doc->createElement('name', $this->shopName));
        $shop->appendChild($doc->createElement('url', $this->siteUrl));

        $currencies = $doc->createElement('currencies');
        $currency   = $doc->createElement('currency');
        $currency->setAttribute('id', $this->currency);
        $currency->setAttribute('rate', '1');
        $currencies->appendChild($currency);
        $shop->appendChild($currencies);

        $categories = $doc->createElement('categories');
        $cat        = $doc->createElement('category', 'All Products');
        $cat->setAttribute('id', '1');
        $categories->appendChild($cat);
        $shop->appendChild($categories);

        $offers = $doc->createElement('offers');
        $shop->appendChild($offers);

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
                            $offers->appendChild($variant);
                        }
                    } else {
                        $offer = $this->buildOffer($doc, $product);
                        if ($offer !== null) {
                            $offers->appendChild($offer);
                        }
                    }
                } else {
                    $offer = $this->buildOffer($doc, $product);
                    if ($offer !== null) {
                        $offers->appendChild($offer);
                    }
                }
            }

            $page++;
        } while (count($products) === $perPage);

        return $doc->saveXML();
    }

    private function buildOffer(\DOMDocument $doc, Product $product): ?\DOMElement
    {
        if ($product->getSku() === null || $product->getSku() === '') {
            return null;
        }

        $offer = $doc->createElement('offer');
        $offer->setAttribute('id', $product->getSku());
        $offer->setAttribute('available', $product->isInStock() ? 'true' : 'false');

        $this->appendEl($doc, $offer, 'url', $this->productUrl($product));
        $this->appendEl($doc, $offer, 'price', number_format($product->getEffectivePrice(), 2, '.', ''));
        $this->appendEl($doc, $offer, 'currencyId', $this->currency);
        $this->appendEl($doc, $offer, 'categoryId', '1');
        $this->appendEl($doc, $offer, 'delivery', $product->isDigital() ? 'false' : 'true');
        $this->appendEl($doc, $offer, 'name', $this->sanitize($product->getName()), true);
        $this->appendEl($doc, $offer, 'description', $this->sanitize(strip_tags($product->getDescription() ?? '')), true);

        $this->addImages($doc, $offer, $product);

        if ($product->getBrand() !== null) {
            $this->appendEl($doc, $offer, 'vendor', $product->getBrand());
        }
        if ($product->getGtin() !== null) {
            $this->appendEl($doc, $offer, 'ean', $product->getGtin());
        }

        return $offer;
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

            $varPrice       = !empty($v['price']) ? (float) $v['price'] : $product->getPrice();
            $varSalePrice   = !empty($v['sale_price']) ? (float) $v['sale_price'] : null;
            $effectivePrice = $varSalePrice ?? $varPrice;

            $varStockStatus = $v['stock_status'] ?? 'in_stock';
            $isAvailable    = $varStockStatus === 'in_stock';

            $varTitle  = $product->getName();
            $attrs     = [];
            if (is_string($v['attributes'] ?? null)) {
                $attrs = json_decode($v['attributes'], true) ?? [];
            } elseif (is_array($v['attributes'] ?? null)) {
                $attrs = $v['attributes'];
            }
            $attrParts = [];
            foreach ($attrs as $av) {
                if (is_string($av) && $av !== '') {
                    $attrParts[] = $av;
                }
            }
            if ($attrParts !== []) {
                $varTitle .= ' - ' . implode(' / ', $attrParts);
            }

            $offer = $doc->createElement('offer');
            $offer->setAttribute('id', (string) $varSku);
            $offer->setAttribute('available', $isAvailable ? 'true' : 'false');

            $this->appendEl($doc, $offer, 'url', $this->productUrl($product));
            $this->appendEl($doc, $offer, 'price', number_format($effectivePrice, 2, '.', ''));
            $this->appendEl($doc, $offer, 'currencyId', $this->currency);
            $this->appendEl($doc, $offer, 'categoryId', '1');
            $this->appendEl($doc, $offer, 'delivery', $product->isDigital() ? 'false' : 'true');
            $this->appendEl($doc, $offer, 'name', $this->sanitize($varTitle), true);
            $this->appendEl($doc, $offer, 'description', $this->sanitize(strip_tags($product->getDescription() ?? '')), true);

            if ($product->getBrand() !== null) {
                $this->appendEl($doc, $offer, 'vendor', $product->getBrand());
            }
            if ($product->getGtin() !== null) {
                $this->appendEl($doc, $offer, 'ean', $product->getGtin());
            }

            $varImageId = !empty($v['image_id']) ? (int) $v['image_id'] : null;
            if ($varImageId !== null) {
                try {
                    $media = $this->media->findById($varImageId);
                    if ($media !== null) {
                        $this->appendEl($doc, $offer, 'picture', $this->mediaUrl($media->getUrl()));
                    }
                } catch (\Throwable) {
                }
            } elseif ($product->getFeaturedImageId() !== null) {
                $this->addFeaturedImage($doc, $offer, $product);
            }

            $items[] = $offer;
        }

        return $items;
    }

    private function addImages(\DOMDocument $doc, \DOMElement $offer, Product $product): void
    {
        $this->addFeaturedImage($doc, $offer, $product);

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
                    $this->appendEl($doc, $offer, 'picture', $this->mediaUrl($media->getUrl()));
                }
            } catch (\Throwable) {
            }
        }
    }

    private function addFeaturedImage(\DOMDocument $doc, \DOMElement $offer, Product $product): void
    {
        if ($product->getFeaturedImageId() === null) {
            return;
        }
        try {
            $media = $this->media->findById($product->getFeaturedImageId());
            if ($media !== null) {
                $this->appendEl($doc, $offer, 'picture', $this->mediaUrl($media->getUrl()));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to load featured image #{id} for product #{pid}: {msg}', [
                'id'  => $product->getFeaturedImageId(),
                'pid' => $product->getId(),
                'msg' => $e->getMessage(),
            ]);
        }
    }

    private function appendEl(\DOMDocument $doc, \DOMElement $parent, string $name, string $value, bool $cdata = false): void
    {
        $el = $doc->createElement($name);
        if ($cdata) {
            $el->appendChild($doc->createCDATASection($value));
        } else {
            $el->appendChild($doc->createTextNode($value));
        }
        $parent->appendChild($el);
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

    private function sanitize(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        return trim($text);
    }
}
