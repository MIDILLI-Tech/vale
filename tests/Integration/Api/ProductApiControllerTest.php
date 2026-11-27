<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use ValeApp\App\Controllers\Api\ProductApiController;
use ValeApp\Domain\Media\Entity\Media;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Media\MediaUploadService;
use ValeApp\Infrastructure\Media\RemoteImageImporter;

final class ProductApiControllerTest extends TestCase
{
    private $productRepo;
    private $categoryRepo;
    private $mediaRepo;
    private $mediaService;
    private $imageImporter;
    private $slugService;
    private $events;
    private $controller;

    protected function setUp(): void
    {
        $this->productRepo   = $this->createStub(ProductRepositoryInterface::class);
        $this->categoryRepo  = $this->createStub(ProductCategoryRepositoryInterface::class);
        $this->mediaRepo     = $this->createStub(MediaRepositoryInterface::class);

        $this->mediaService  = $this->createStub(MediaUploadService::class);
        $this->imageImporter = new RemoteImageImporter($this->mediaRepo, $this->mediaService);
        $this->slugService   = $this->createStub(SlugService::class);
        $this->events        = $this->createStub(EventDispatcherInterface::class);

        $this->controller = $this->buildController();
    }

    private function buildController(): ProductApiController
    {
        return new ProductApiController(
            $this->productRepo,
            $this->categoryRepo,
            $this->mediaRepo,
            $this->imageImporter,
            $this->slugService,
            $this->events
        );
    }

    public function testStoreValidatesName(): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/products')
            ->withParsedBody([]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Product name is required.', $body['error']);
    }

    public function testStoreCreatesProduct(): void
    {
        $this->productRepo = $this->createMock(ProductRepositoryInterface::class);
        $this->controller  = $this->buildController();

        $data = [
            'name'  => 'Test Product',
            'price' => 10.99,
            'sku'   => 'TEST-123',
            'gtin'  => '1234567890123',
        ];
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/products')
            ->withParsedBody($data)
            ->withAttribute('api_user_id', 1);

        $this->slugService->method('unique')->willReturn(new Slug('test-product'));

        $this->productRepo->expects(self::once())->method('save')->willReturnCallback(function (Product $p) {
            $p->setId(1);
            return $p;
        });

        $this->productRepo->method('findGalleryImageIds')->willReturn([]);
        $this->productRepo->method('findDownloadFileIds')->willReturn([]);
        $this->productRepo->method('findVariationsByProductId')->willReturn([]);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
    }

    public function testUpdateExistingProduct(): void
    {
        $this->productRepo = $this->createMock(ProductRepositoryInterface::class);
        $this->controller  = $this->buildController();

        $product = new Product('Old Name', new Slug('old-name'));
        $product->setId(123);

        $this->productRepo->method('findById')->with(123)->willReturn($product);

        $data    = ['name' => 'New Name', 'price' => 99.99];
        $request = (new RequestFactory())->createRequest('PUT', '/api/v1/products/123')
            ->withParsedBody($data)
            ->withAttribute('api_user_id', 1);

        $this->productRepo->expects(self::once())->method('save')->willReturnCallback(function (Product $p) {
            return $p;
        });

        $this->productRepo->method('findGalleryImageIds')->willReturn([]);
        $this->productRepo->method('findVariationsByProductId')->willReturn([]);

        $response = $this->controller->update($request, (new ResponseFactory())->createResponse(), ['id' => '123']);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testDestroyProduct(): void
    {
        $this->productRepo = $this->createMock(ProductRepositoryInterface::class);
        $this->controller  = $this->buildController();

        $product = new Product('To Delete', new Slug('to-delete'));
        $product->setId(456);
        $this->productRepo->method('findById')->with(456)->willReturn($product);
        $this->productRepo->expects(self::once())->method('delete')->with(456);

        $request  = (new RequestFactory())->createRequest('DELETE', '/api/v1/products/456');
        $response = $this->controller->destroy($request, (new ResponseFactory())->createResponse(), ['id' => '456']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testIndexReturnsList(): void
    {
        $this->productRepo->method('findAll')->willReturn([]);
        $this->productRepo->method('count')->willReturn(0);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/products');
        $response = $this->controller->index($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body['data']);
    }

    public function testIndexFiltersByStatusList(): void
    {
        $seen = [];
        $this->productRepo->method('findAll')->willReturnCallback(
            function (Pagination $pagination, array $filters) use (&$seen): array {
                $seen[] = $filters;
                return [];
            }
        );
        $this->productRepo->method('count')->willReturn(0);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/products?status=draft,scheduled')
            ->withAttribute('api_abilities', 'write:products');
        $response = $this->controller->index($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['draft', 'scheduled'], $seen[0]['status']);
    }

    public function testIndexRejectsUnpublishedStatusesWithoutWriteAbility(): void
    {
        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/products?status=draft')
            ->withAttribute('api_abilities', 'read:products');
        $response = $this->controller->index($request, (new ResponseFactory())->createResponse());

        self::assertSame(403, $response->getStatusCode());
    }

    public function testIndexRejectsUnknownStatus(): void
    {
        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/products?status=foo');
        $response = $this->controller->index($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
    }

    public function testShowReturnsProduct(): void
    {
        $product = new Product('Test', new Slug('test'));
        $product->setId(123);
        $this->productRepo->method('findBySlug')->willReturn($product);
        $this->productRepo->method('findGalleryImageIds')->willReturn([]);
        $this->productRepo->method('findDownloadFileIds')->willReturn([]);
        $this->productRepo->method('findVariationsByProductId')->willReturn([]);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/products/test');
        $response = $this->controller->show($request, (new ResponseFactory())->createResponse(), ['slug' => 'test']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(123, $body['data']['id']);
    }

    public function testShowReturns404IfNotFound(): void
    {
        $this->productRepo->method('findBySlug')->willReturn(null);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/products/non-existent');
        $response = $this->controller->show($request, (new ResponseFactory())->createResponse(), ['slug' => 'non-existent']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testShowByIdReturnsProduct(): void
    {
        $product = new Product('Test', new Slug('test'));
        $product->setId(123);
        $this->productRepo->method('findById')->willReturn($product);
        $this->productRepo->method('findGalleryImageIds')->willReturn([]);
        $this->productRepo->method('findDownloadFileIds')->willReturn([]);
        $this->productRepo->method('findVariationsByProductId')->willReturn([]);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/products/id/123');
        $response = $this->controller->showById($request, (new ResponseFactory())->createResponse(), ['id' => '123']);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(123, $body['data']['id']);
    }

    public function testShowByIdReturns404IfNotFound(): void
    {
        $this->productRepo->method('findById')->willReturn(null);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/products/id/999');
        $response = $this->controller->showById($request, (new ResponseFactory())->createResponse(), ['id' => '999']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testIndexPassesFilters(): void
    {
        $seen = [];
        $this->productRepo->method('findAll')->willReturnCallback(
            static function ($pagination, array $filters) use (&$seen): array {
                $seen[] = $filters;
                return [];
            }
        );
        $this->productRepo->method('count')->willReturn(0);

        $request = (new RequestFactory())->createRequest(
            'GET',
            '/api/v1/products?status=draft&sku=ABC-1&search=abc&type=simple&on_sale=1'
        )->withAttribute('api_abilities', 'write:products');
        $response = $this->controller->index($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([[
            'status'  => 'draft',
            'sku'     => 'ABC-1',
            'search'  => 'abc',
            'type'    => 'simple',
            'on_sale' => true,
        ]], $seen);
    }

    public function testStoreWooStylePayload(): void
    {
        $this->productRepo = $this->createMock(ProductRepositoryInterface::class);
        $this->controller  = $this->buildController();

        $saved = null;
        $this->productRepo->expects(self::once())->method('save')->willReturnCallback(
            static function (Product $p) use (&$saved): Product {
                $p->setId(7);
                $saved = $p;
                return $p;
            }
        );
        $this->productRepo->method('findGalleryImageIds')->willReturn([]);
        $this->productRepo->method('findDownloadFileIds')->willReturn([]);
        $this->productRepo->method('findVariationsByProductId')->willReturn([]);

        $data = [
            'name'             => 'Woo Device',
            'sku'              => 'device-abc',
            'stock_status'     => 'onbackorder',
            'dimensions'       => ['length' => '20', 'width' => '10', 'height' => '6'],
            'upsell_ids'       => [9, 10, 10],
            'cross_sell_ids'   => [11],
            'sale_price'       => 99.99,
            'sale_price_start' => '2026-12-01T00:00:00+00:00',
            'sale_price_end'   => '2026-12-31T23:59:59+00:00',
        ];
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/products')
            ->withParsedBody($data)
            ->withAttribute('api_user_id', 1);

        $this->slugService->method('unique')->willReturn(new Slug('woo-device'));

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
        self::assertNotNull($saved);
        self::assertSame('on_backorder', $saved->getStockStatus());
        self::assertSame('20 x 10 x 6', $saved->getDimensions());
        self::assertSame([9, 10], $saved->getUpsellIds());
        self::assertSame([11], $saved->getCrossSellIds());
        self::assertSame('2026-12-01', $saved->getSalePriceStart()?->format('Y-m-d'));
        self::assertSame('2026-12-31', $saved->getSalePriceEnd()?->format('Y-m-d'));

        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['data']['on_sale']); // sale starts in the future
        self::assertSame([9, 10], $body['data']['upsell_ids']);
        self::assertSame([11], $body['data']['cross_sell_ids']);
    }

    public function testStoreRejectsBadSaleDate(): void
    {
        $request = (new RequestFactory())->createRequest('POST', '/api/v1/products')
            ->withParsedBody(['name' => 'Bad Date', 'sale_price_start' => 'not-a-date'])
            ->withAttribute('api_user_id', 1);

        $this->slugService->method('unique')->willReturn(new Slug('bad-date'));

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
    }

    public function testStorePersistsComplianceFieldsAndVariationBatch(): void
    {
        $this->productRepo = $this->createMock(ProductRepositoryInterface::class);
        $this->controller  = $this->buildController();

        $saved = null;
        $this->productRepo->expects(self::once())->method('save')->willReturnCallback(
            static function (Product $p) use (&$saved): Product {
                $p->setId(8);
                $saved = $p;
                return $p;
            }
        );
        $savedVariations = [];
        $this->productRepo->expects(self::once())->method('saveVariations')->willReturnCallback(
            static function (int $productId, array $variations) use (&$savedVariations): void {
                $savedVariations = $variations;
            }
        );
        $this->productRepo->method('findGalleryImageIds')->willReturn([]);
        $this->productRepo->method('findDownloadFileIds')->willReturn([]);
        $this->productRepo->method('findVariationsByProductId')->willReturn([]);
        $this->slugService->method('unique')->willReturn(new Slug('compliant-device'));

        $request = (new RequestFactory())->createRequest('POST', '/api/v1/products')
            ->withParsedBody([
                'name'                   => 'Compliant Device',
                'unit'                   => 'kg',
                'base_amount'            => '1.5',
                'manufacturer_name'      => 'ACME',
                'manufacturer_address'   => 'Street 1',
                'eu_responsible_name'    => 'ACME EU',
                'eu_responsible_address' => 'EU Street 1',
                'country_of_origin'      => 'de',
                'safety_warnings'        => 'Keep dry',
                'manual_url'             => 'https://example.com/manual.pdf',
                'manual_label'           => 'Manual',
                'digital_waiver'         => true,
                'min_age'                => 18,
                'batch_number'           => 'LOT-9',
                'variations'             => [[
                    'sku'              => 'C-1',
                    'price'            => 10,
                    'attributes'       => ['Size' => 'M'],
                    'batch_number'     => 'LOT-9-A',
                    'unit'             => 'kg',
                    'base_amount'      => '2.5',
                    'manual_documents' => [
                        ['type' => 'url', 'value' => 'https://example.com/v-manual.pdf', 'label' => 'Datasheet'],
                        ['type' => 'url', 'value' => 'javascript:alert(1)', 'label' => 'Bad'],
                    ],
                ]],
            ])
            ->withAttribute('api_user_id', 1);

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(201, $response->getStatusCode());
        self::assertNotNull($saved);
        self::assertSame('kg', $saved->getUnit());
        self::assertSame(1.5, $saved->getBaseAmount());
        self::assertSame('ACME', $saved->getManufacturerName());
        self::assertSame('Street 1', $saved->getManufacturerAddress());
        self::assertSame('ACME EU', $saved->getEuResponsibleName());
        self::assertSame('EU Street 1', $saved->getEuResponsibleAddress());
        self::assertSame('DE', $saved->getCountryOfOrigin());
        self::assertSame('Keep dry', $saved->getSafetyWarnings());
        self::assertSame('Manual', $saved->getManualLabel());
        self::assertTrue($saved->hasDigitalWaiver());
        self::assertSame(18, $saved->getMinAge());
        self::assertSame('LOT-9', $saved->getBatchNumber());
        self::assertSame('LOT-9-A', $savedVariations[0]['batch_number']);
        self::assertSame('kg', $savedVariations[0]['unit']);
        self::assertSame(2.5, $savedVariations[0]['base_amount']);
        self::assertCount(1, $savedVariations[0]['manual_documents']);
        self::assertSame('Datasheet', $savedVariations[0]['manual_documents'][0]['label']);
        self::assertSame('https://example.com/v-manual.pdf', $savedVariations[0]['manual_documents'][0]['url']);

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('kg', $body['data']['unit']);
        self::assertSame('ACME', $body['data']['manufacturer_name']);
        self::assertSame('DE', $body['data']['country_of_origin']);
        self::assertTrue($body['data']['digital_waiver']);
    }

    public function testShowByIdReturnsVariationComplianceFields(): void
    {
        $product = new Product('Test', new Slug('test'));
        $product->setId(123);
        $this->productRepo->method('findById')->willReturn($product);
        $this->productRepo->method('findGalleryImageIds')->willReturn([]);
        $this->productRepo->method('findDownloadFileIds')->willReturn([]);
        $this->productRepo->method('findVariationsByProductId')->willReturn([[
            'id'                         => 11,
            'sku'                        => 'V-1',
            'price'                      => '10.00',
            'sale_price'                 => null,
            'sale_price_start'           => null,
            'sale_price_end'             => null,
            'stock_quantity'             => 3,
            'stock_status'               => 'in_stock',
            'image_id'                   => null,
            'attributes'                 => '{"Size":"M"}',
            'download_mode'              => 'use_parent',
            'download_url'               => null,
            'download_limit'             => 0,
            'download_expiry_days'       => 0,
            'shipping_class'             => 'use_parent',
            'shipping_cost'              => null,
            'shipping_country_overrides' => '{}',
            'vat_mode'                   => 'use_parent',
            'vat_rate'                   => null,
            'refund_mode'                => 'use_parent',
            'batch_number'               => 'LOT-V',
            'unit'                       => 'kg',
            'base_amount'                => '2.5',
            'manual_documents'           => json_encode([
                ['type' => 'url', 'value' => 'https://example.com/m.pdf', 'label' => 'Manual', 'name' => '', 'url' => 'https://example.com/m.pdf'],
            ]),
        ]]);

        $request  = (new RequestFactory())->createRequest('GET', '/api/v1/products/id/123');
        $response = $this->controller->showById($request, (new ResponseFactory())->createResponse(), ['id' => '123']);

        self::assertSame(200, $response->getStatusCode());
        $variation = json_decode((string) $response->getBody(), true)['data']['variations'][0];
        self::assertSame('LOT-V', $variation['batch_number']);
        self::assertSame('kg', $variation['unit']);
        self::assertSame(2.5, $variation['base_amount']);
        self::assertSame('Manual', $variation['manual_documents'][0]['label']);
        self::assertSame('https://example.com/m.pdf', $variation['manual_documents'][0]['url']);
    }
}
