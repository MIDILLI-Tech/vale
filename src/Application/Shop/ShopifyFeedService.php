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

final class ShopifyFeedService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly MediaRepositoryInterface $media,
        private readonly string $siteUrl,
        private readonly string $shopName = 'My Shop',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?SettingsRepositoryInterface $settings = null,
    ) {
    }

    public function generateRss(): string
    {
        $doc               = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $rss = $doc->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', 'http://www.w3.org/2005/Atom');
        $doc->appendChild($rss);

        $channel = $doc->createElement('channel');
        $rss->appendChild($channel);

        $channel->appendChild($doc->createElement('title', $this->shopName . ' Products'));
        $channel->appendChild($doc->createElement('link', $this->siteUrl . '/collections/all'));
        $channel->appendChild($doc->createElement('description', $this->shopName . ' product feed'));

        $atomLink = $doc->createElementNS('http://www.w3.org/2005/Atom', 'atom:link');
        $atomLink->setAttribute('href', $this->siteUrl . '/api/v1/feed/shopify');
        $atomLink->setAttribute('rel', 'self');
        $atomLink->setAttribute('type', 'application/rss+xml');
        $channel->appendChild($atomLink);

        $page     = 1;
        $perPage  = 200;
        $maxItems = 1000;

        do {
            $products = $this->products->findAll(
                new Pagination($page, $perPage),
                ['status' => 'published'],
            );

            foreach ($products as $product) {
                if ($product->isGmcExclude()) {
                    continue;
                }

                $item = $this->buildItem($doc, $product);
                if ($item !== null) {
                    $channel->appendChild($item);
                }
            }

            $page++;
        } while (count($products) === $perPage && ($page * $perPage) < $maxItems);

        return $doc->saveXML();
    }

    private function buildItem(\DOMDocument $doc, Product $product): ?\DOMElement
    {
        if ($product->getSku() === null || $product->getSku() === '') {
            return null;
        }

        $item = $doc->createElement('item');

        $item->appendChild($doc->createElement('title', $this->sanitize($product->getName())));

        $link = $this->productUrl($product);
        $item->appendChild($doc->createElement('link', $link));

        $guid = $doc->createElement('guid', $link);
        $guid->setAttribute('isPermaLink', 'true');
        $item->appendChild($guid);

        $desc = $doc->createElement('description');
        $desc->appendChild($doc->createCDATASection($this->sanitize(
            $product->getMetaDescription() ?? $product->getShortDescription() ?? $product->getDescription() ?? '',
        )));
        $item->appendChild($desc);

        if ($product->getPublishedAt() !== null) {
            $item->appendChild($doc->createElement('pubDate', $product->getPublishedAt()->format('r')));
        }

        $this->addImages($doc, $item, $product);

        return $item;
    }

    private function addImages(\DOMDocument $doc, \DOMElement $item, Product $product): void
    {
        if ($product->getFeaturedImageId() === null) {
            return;
        }
        try {
            $media = $this->media->findById($product->getFeaturedImageId());
            if ($media !== null) {
                // RSS 2.0 has no image_link element; enclosure is the valid way.
                $enc = $doc->createElement('enclosure');
                $enc->setAttribute('url', $this->mediaUrl($media->getUrl()));
                $enc->setAttribute('length', (string) $media->getSize());
                $enc->setAttribute('type', $media->getMimeType());
                $item->appendChild($enc);
            }
        } catch (\Throwable) {
        }
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
