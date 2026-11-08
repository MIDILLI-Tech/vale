<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Api;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Slim\Psr7\Uri;
use ValeApp\App\Controllers\Api\FeedController;
use ValeApp\Application\Shop\IdealoFeedService;
use ValeApp\Application\Shop\ProductFeedService;
use ValeApp\Application\Shop\ShopifyFeedService;
use ValeApp\Application\Shop\YandexMarketFeedService;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class FeedControllerTest extends TestCase
{
    private function createRequest(): Request
    {
        $stream = (new StreamFactory())->createStream('');
        $uri    = new Uri('https', 'example.com', 443, '/api/v1/feed/google-merchant');
        return new Request('GET', $uri, new Headers(), [], [], $stream);
    }

    public function testShopDisabledReturns404(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturn('0');

        $pRepo = $this->createStub(ProductRepositoryInterface::class);
        $mRepo = $this->createStub(MediaRepositoryInterface::class);

        $pfs = new ProductFeedService($pRepo, $mRepo, 'https://example.com');
        $ifs = new IdealoFeedService($pRepo, $mRepo, 'https://example.com');
        $yms = new YandexMarketFeedService($pRepo, $mRepo, 'https://example.com');
        $sfs = new ShopifyFeedService($pRepo, $mRepo, 'https://example.com');

        $controller = new FeedController($pfs, $ifs, $yms, $sfs, $settings);
        $res        = $controller->googleMerchant($this->createRequest(), new Response());

        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGoogleMerchantDisabledReturns404(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(function (string $key, $default = null) {
            if ($key === 'shop_enabled') {
                return '1';
            }
            if ($key === 'google_merchant_enabled') {
                return '0';
            }
            return $default;
        });

        $pRepo = $this->createStub(ProductRepositoryInterface::class);
        $mRepo = $this->createStub(MediaRepositoryInterface::class);

        $pfs = new ProductFeedService($pRepo, $mRepo, 'https://example.com');
        $ifs = new IdealoFeedService($pRepo, $mRepo, 'https://example.com');
        $yms = new YandexMarketFeedService($pRepo, $mRepo, 'https://example.com');
        $sfs = new ShopifyFeedService($pRepo, $mRepo, 'https://example.com');

        $controller = new FeedController($pfs, $ifs, $yms, $sfs, $settings);
        $res        = $controller->googleMerchant($this->createRequest(), new Response());

        $this->assertSame(404, $res->getStatusCode());
    }

    public function testGoogleMerchantEnabledReturnsXmlFeed(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(function (string $key, $default = null) {
            if ($key === 'shop_enabled') {
                return '1';
            }
            if ($key === 'google_merchant_enabled') {
                return '1';
            }
            return $default;
        });

        $pRepo = $this->createStub(ProductRepositoryInterface::class);
        $pRepo->method('findAll')->willReturn([]);
        $mRepo = $this->createStub(MediaRepositoryInterface::class);

        $pfs = new ProductFeedService($pRepo, $mRepo, 'https://example.com');
        $ifs = new IdealoFeedService($pRepo, $mRepo, 'https://example.com');
        $yms = new YandexMarketFeedService($pRepo, $mRepo, 'https://example.com');
        $sfs = new ShopifyFeedService($pRepo, $mRepo, 'https://example.com');

        $controller = new FeedController($pfs, $ifs, $yms, $sfs, $settings);
        $res        = $controller->googleMerchant($this->createRequest(), new Response());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('application/xml', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Product Feed', (string) $res->getBody());
    }
}
