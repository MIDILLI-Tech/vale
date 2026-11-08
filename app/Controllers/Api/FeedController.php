<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Application\Shop\IdealoFeedService;
use ValeApp\Application\Shop\ProductFeedService;
use ValeApp\Application\Shop\ShopifyFeedService;
use ValeApp\Application\Shop\YandexMarketFeedService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class FeedController
{
    private const TAXONOMY_PATH = __DIR__ . '/../../../resources/google-taxonomy.json';

    public function __construct(
        private readonly ProductFeedService $feedService,
        private readonly IdealoFeedService $idealoFeedService,
        private readonly YandexMarketFeedService $yandexFeedService,
        private readonly ShopifyFeedService $shopifyFeedService,
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    /** All feed methods require shop_enabled (like blog->RSS gating) */
    private function shopDisabledResponse(ResponseInterface $response): ?ResponseInterface
    {
        if (($this->settings->get('shop_enabled', '1') ?? '1') !== '1') {
            $response->getBody()->write('<error>The shop is disabled.</error>');
            return $response->withStatus(404);
        }
        return null;
    }

    public function googleMerchant(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $disabled = $this->shopDisabledResponse($response);
        if ($disabled !== null) {
            return $disabled;
        }

        if (($this->settings->get('google_merchant_enabled', '0') ?? '0') !== '1') {
            $response->getBody()->write('<error>Google Merchant Center feed is disabled. Enable it in Settings > E-Commerce > Shop.</error>');
            return $response->withStatus(404)->withHeader('Content-Type', 'application/xml; charset=utf-8');
        }

        $xml = $this->feedService->generateXml();

        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="google-merchant-feed.xml"');
    }

    public function bing(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $disabled = $this->shopDisabledResponse($response);
        if ($disabled !== null) {
            return $disabled;
        }

        if (($this->settings->get('channel_bing_enabled', '0') ?? '0') !== '1') {
            $response->getBody()->write('<error>Bing feed is disabled.</error>');
            return $response->withStatus(404)->withHeader('Content-Type', 'application/xml; charset=utf-8');
        }

        $xml = $this->feedService->generateXml();

        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="bing-feed.xml"');
    }

    public function meta(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $disabled = $this->shopDisabledResponse($response);
        if ($disabled !== null) {
            return $disabled;
        }

        if (($this->settings->get('channel_meta_enabled', '0') ?? '0') !== '1') {
            $response->getBody()->write('<error>Meta (Facebook/Instagram) feed is disabled.</error>');
            return $response->withStatus(404)->withHeader('Content-Type', 'application/xml; charset=utf-8');
        }

        $xml = $this->feedService->generateXml();

        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="meta-feed.xml"');
    }

    public function pinterest(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $disabled = $this->shopDisabledResponse($response);
        if ($disabled !== null) {
            return $disabled;
        }

        if (($this->settings->get('channel_pinterest_enabled', '0') ?? '0') !== '1') {
            $response->getBody()->write('<error>Pinterest feed is disabled.</error>');
            return $response->withStatus(404)->withHeader('Content-Type', 'application/xml; charset=utf-8');
        }

        $xml = $this->feedService->generateXml();

        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="pinterest-feed.xml"');
    }

    public function idealo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $disabled = $this->shopDisabledResponse($response);
        if ($disabled !== null) {
            return $disabled;
        }

        if (($this->settings->get('channel_idealo_enabled', '0') ?? '0') !== '1') {
            $response->getBody()->write('error,Idealo feed is disabled.');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/csv; charset=utf-8');
        }

        $csv = $this->idealoFeedService->generateCsv();

        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="idealo-feed.csv"');
    }

    public function tiktok(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $disabled = $this->shopDisabledResponse($response);
        if ($disabled !== null) {
            return $disabled;
        }

        if (($this->settings->get('channel_tiktok_enabled', '0') ?? '0') !== '1') {
            $response->getBody()->write('<error>TikTok Shop feed is disabled.</error>');
            return $response->withStatus(404)->withHeader('Content-Type', 'application/xml; charset=utf-8');
        }

        $xml = $this->feedService->generateXml();

        $response->getBody()->write($xml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="tiktok-shop-feed.xml"');
    }

    public function shopify(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $disabled = $this->shopDisabledResponse($response);
        if ($disabled !== null) {
            return $disabled;
        }

        if (($this->settings->get('channel_shopify_enabled', '0') ?? '0') !== '1') {
            $response->getBody()->write('<error>Shopify RSS feed is disabled.</error>');
            return $response->withStatus(404)->withHeader('Content-Type', 'application/rss+xml; charset=utf-8');
        }

        $rss = $this->shopifyFeedService->generateRss();

        $response->getBody()->write($rss);

        return $response
            ->withHeader('Content-Type', 'application/rss+xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="shopify-feed.xml"');
    }

    public function yandexMarket(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $disabled = $this->shopDisabledResponse($response);
        if ($disabled !== null) {
            return $disabled;
        }

        if (($this->settings->get('channel_yandex_enabled', '0') ?? '0') !== '1') {
            $response->getBody()->write('<error>Yandex Market feed is disabled.</error>');
            return $response->withStatus(404)->withHeader('Content-Type', 'application/xml; charset=utf-8');
        }

        $yml = $this->yandexFeedService->generateYml();

        $response->getBody()->write($yml);

        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="yandex-market-feed.xml"');
    }

    /** GET /api/v1/feed/google-taxonomy */
    public function browseTaxonomy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $path       = self::TAXONOMY_PATH;
        $categories = [];

        if (!file_exists($path)) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            try {
                $txt = @file_get_contents('https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt');
                if ($txt !== false && $txt !== '') {
                    $lines  = explode("\n", $txt);
                    $parsed = [];
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || str_starts_with($line, '#')) {
                            continue;
                        }
                        if (preg_match('/^\d+\s*-\s*(.+)$/', $line, $m)) {
                            $parsed[] = trim($m[1]);
                        } else {
                            $parsed[] = $line;
                        }
                    }
                    if ($parsed !== []) {
                        @file_put_contents($path, json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }
            } catch (\Throwable) {
                // Non-fatal if offline
            }
        }

        if (file_exists($path)) {
            $content    = file_get_contents($path);
            $categories = json_decode($content, true) ?? [];
        }

        $params = $request->getQueryParams();
        $search = trim((string) ($params['q'] ?? ''));
        if ($search !== '') {
            $categories = array_values(array_filter($categories, fn (string $c) => stripos($c, $search) !== false));
        }

        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(500, max(10, (int) ($params['per_page'] ?? 100)));
        $total   = count($categories);
        $offset  = ($page - 1) * $perPage;
        $items   = array_slice($categories, $offset, $perPage);

        $payload = json_encode([
            'data' => $items,
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
