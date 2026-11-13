<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\ProductController;
use ValeApp\Application\Seo\SaveSeoMetaUseCase;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class ProductControllerTest extends TestCase
{
    private $translator;
    private $twig;
    private $products;
    private $categories;
    private $media;
    private $slugService;
    private $session;
    private $settings;
    private $seoRepo;
    private $saveSeo;
    private $redirects;
    private $searchCache;
    private $plans;
    private $controller;
    protected function setUp(): void
    {
        $this->translator  = $this->createStub(Translator::class);
        $this->twig        = $this->createStub(Twig::class);
        $this->products    = $this->createStub(ProductRepositoryInterface::class);
        $this->categories  = $this->createStub(ProductCategoryRepositoryInterface::class);
        $this->media       = $this->createStub(MediaRepositoryInterface::class);
        $this->slugService = $this->createStub(SlugService::class);
        $this->session     = $this->createStub(SessionManager::class);
        $this->settings    = $this->createStub(DbalSettingsRepository::class);
        $this->seoRepo     = $this->createStub(SeoMetaRepositoryInterface::class);
        $this->saveSeo     = $this->createStub(SaveSeoMetaUseCase::class);
        $this->redirects   = $this->createStub(RedirectService::class);
        $this->searchCache = new AdminSearchCache($this->createStub(CacheInterface::class));
        $this->plans       = $this->createStub(PlanRepositoryInterface::class);
        $this->plans->method('findAll')->willReturn([]);
        $this->controller = $this->buildController();
    }

    private function buildController(): ProductController
    {
        return new ProductController(
            $this->translator,
            $this->twig,
            $this->products,
            $this->categories,
            $this->media,
            $this->slugService,
            $this->session,
            $this->settings,
            $this->seoRepo,
            $this->saveSeo,
            $this->redirects,
            $this->searchCache,
            $this->plans,
            $this->createStub(\ValeApp\Domain\Shared\Event\EventDispatcherInterface::class)
        );
    }

    public function testIndexRendersTemplate(): void
    {
        $this->twig       = $this->createMock(Twig::class);
        $this->controller = $this->buildController();

        $this->products->method('findAll')->willReturn([]);
        $this->twig->expects(self::once())->method('render')->with(
            self::anything(),
            'admin/products/index.twig'
        );

        $request = (new RequestFactory())->createRequest('GET', '/admin/products');
        $this->controller->index($request, (new ResponseFactory())->createResponse());
    }

    public function testStoreCreatesProductAndRedirects(): void
    {
        $this->products   = $this->createMock(ProductRepositoryInterface::class);
        $this->session    = $this->createMock(SessionManager::class);
        $this->controller = $this->buildController();

        $data = [
            'name'      => 'New Product',
            'language'  => 'en',
            'condition' => 'new',
            'status'    => 'published',
        ];
        $request = (new RequestFactory())->createRequest('POST', '/admin/products')
            ->withParsedBody($data);

        $this->slugService->method('unique')->willReturn(new Slug('new-product'));
        $this->products->expects(self::once())->method('save')->willReturnCallback(function (Product $p) {
            $p->setId(123);
            return $p;
        });
        $this->session->expects(self::once())->method('flash')->with('success', self::anything());

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/admin/products', $response->getHeaderLine('Location'));
    }

    public function testDestroyProduct(): void
    {
        $this->products   = $this->createMock(ProductRepositoryInterface::class);
        $this->session    = $this->createMock(SessionManager::class);
        $this->controller = $this->buildController();

        $this->products->expects(self::once())->method('delete')->with(456);
        $this->session->expects(self::once())->method('flash')->with('success', self::anything());

        $request  = (new RequestFactory())->createRequest('DELETE', '/admin/products/456');
        $response = $this->controller->destroy($request, (new ResponseFactory())->createResponse(), ['id' => '456']);

        self::assertSame(302, $response->getStatusCode());
    }

    public function testBulkExportSelectedReturnsCsv(): void
    {
        $this->products   = $this->createStub(ProductRepositoryInterface::class);
        $this->controller = $this->buildController();

        $p = new Product('Test Product', new Slug('test-product'));
        $p->setId(10);
        $p->setSku('SKU-1');
        $p->setPrice(19.99);

        $this->products->method('findById')->willReturn($p);
        $this->products->method('findGalleryImageIds')->willReturn([]);
        $this->products->method('findDownloadFileIds')->willReturn([]);
        $this->products->method('findVariationsByProductId')->willReturn([]);

        $request = (new RequestFactory())->createRequest('POST', '/admin/products/bulk-export')
            ->withParsedBody(['export_scope' => 'selected', 'ids' => '10']);
        $response = $this->controller->bulkExport($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/csv', (string) $response->getHeaderLine('Content-Type'));

        $csv = (string) $response->getBody();
        self::assertStringContainsString('id', $csv);
        self::assertStringContainsString('Test Product', $csv);
        self::assertStringContainsString('SKU-1', $csv);
    }

    public function testBulkExportSelectedWithoutIdsRedirects(): void
    {
        $this->session    = $this->createMock(SessionManager::class);
        $this->controller = $this->buildController();

        $this->session->expects(self::once())->method('set');

        $request = (new RequestFactory())->createRequest('POST', '/admin/products/bulk-export')
            ->withParsedBody(['export_scope' => 'selected', 'ids' => '']);
        $response = $this->controller->bulkExport($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
    }

    public function testBulkExportAllReturnsCsvForEveryProduct(): void
    {
        $this->products   = $this->createStub(ProductRepositoryInterface::class);
        $this->controller = $this->buildController();

        $p = new Product('Another', new Slug('another'));
        $p->setId(20);
        $p->setPrice(5.0);

        $this->products->method('findAll')->willReturn([$p]);
        $this->products->method('findById')->willReturn($p);
        $this->products->method('findGalleryImageIds')->willReturn([]);
        $this->products->method('findDownloadFileIds')->willReturn([]);
        $this->products->method('findVariationsByProductId')->willReturn([]);

        $request = (new RequestFactory())->createRequest('POST', '/admin/products/bulk-export')
            ->withParsedBody(['export_scope' => 'all']);
        $response = $this->controller->bulkExport($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Another', (string) $response->getBody());
    }

    public function testBulkExportSelectAllTriggersAllScope(): void
    {
        $this->products   = $this->createStub(ProductRepositoryInterface::class);
        $this->controller = $this->buildController();

        $p = new Product('Bulk Product', new Slug('bulk-product'));
        $p->setId(30);

        $this->products->method('findAll')->willReturn([$p]);
        $this->products->method('findById')->willReturn($p);
        $this->products->method('findGalleryImageIds')->willReturn([]);
        $this->products->method('findDownloadFileIds')->willReturn([]);
        $this->products->method('findVariationsByProductId')->willReturn([]);

        $request = (new RequestFactory())->createRequest('POST', '/admin/products/bulk-export')
            ->withParsedBody(['select_all' => '1', 'export_scope' => 'selected', 'ids' => '999']);
        $response = $this->controller->bulkExport($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Bulk Product', (string) $response->getBody());
    }

    public function testBulkExportSelectAllWithFiltersTriggersFilteredScope(): void
    {
        $this->products   = $this->createStub(ProductRepositoryInterface::class);
        $this->controller = $this->buildController();

        $p = new Product('Filtered Product', new Slug('filtered-product'));
        $p->setId(40);

        $this->products->method('findAll')->willReturn([$p]);
        $this->products->method('findById')->willReturn($p);
        $this->products->method('findGalleryImageIds')->willReturn([]);
        $this->products->method('findDownloadFileIds')->willReturn([]);
        $this->products->method('findVariationsByProductId')->willReturn([]);

        $request = (new RequestFactory())->createRequest('POST', '/admin/products/bulk-export')
            ->withParsedBody(['select_all' => '1', 'search' => 'shirt', 'ids' => '999']);
        $response = $this->controller->bulkExport($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Filtered Product', (string) $response->getBody());
    }
}
