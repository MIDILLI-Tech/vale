<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\NewsletterController;
use ValeApp\App\Controllers\Admin\ProductController;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Newsletter\NewsletterService;
use ValeApp\Application\Seo\SaveSeoMetaUseCase;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\Product;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class CsvFormulaInjectionTest extends TestCase
{
    private function createMailService(): MailService
    {
        return new MailService(
            $this->createStub(MailInterface::class),
            $this->createStub(Twig::class),
            $this->createStub(DbalSettingsRepository::class),
            $this->createStub(Translator::class),
            $this->createStub(ThemeManager::class),
        );
    }

    public function testSubscriberCsvExportEscapesFormulaCharacters(): void
    {
        $subscriber = new Subscriber('=calc@example.com', 'token123');
        $subscriber->setName('+Formula Name');
        $subscriber->setTags(['-dangerous-tag', '@admin']);
        $subscriber->confirm();

        $subscribersRepo = $this->createStub(SubscriberRepositoryInterface::class);
        $subscribersRepo->method('findAll')->willReturn([$subscriber]);

        $campaignsRepo = $this->createStub(CampaignRepositoryInterface::class);
        $campaignsRepo->method('getSubscribersStats')->willReturn([]);

        $mailService = $this->createMailService();

        $newsletterService = new NewsletterService(
            $this->createStub(MailInterface::class),
            $campaignsRepo,
            $subscribersRepo,
            $this->createStub(CouponRepositoryInterface::class),
            $this->createStub(PostRepositoryInterface::class),
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
            $this->createStub(MediaRepositoryInterface::class),
            'http://localhost',
        );

        $automationService = new AutomationService(
            $this->createStub(AutomationRepositoryInterface::class),
            $subscribersRepo,
            $this->createStub(MailInterface::class),
            $this->createStub(OrderRepositoryInterface::class),
            'http://localhost',
        );

        $controller = new NewsletterController(
            $this->createStub(Translator::class),
            $this->createStub(Twig::class),
            $subscribersRepo,
            $campaignsRepo,
            $this->createStub(SessionManager::class),
            $this->createStub(FormRepositoryInterface::class),
            $newsletterService,
            $mailService,
            $automationService,
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
            new AdminSearchCache($this->createStub(CacheInterface::class)),
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getParsedBody')->willReturn([]);

        $response = new Response();
        $res      = $controller->exportSubscribers($request, $response);

        $csv = (string) $res->getBody();

        $this->assertStringContainsString("'=calc@example.com", $csv);
        $this->assertStringContainsString("'+Formula Name", $csv);
        $this->assertStringContainsString("'-dangerous-tag, @admin", $csv);
    }

    public function testProductCsvExportEscapesFormulaCharacters(): void
    {
        $product = new Product('=Evil Product Name', new Slug('evil-product'));
        $product->setSku('+SKU123');
        $product->setShortDescription('-Dangerous Short Description');

        $productsRepo = $this->createStub(ProductRepositoryInterface::class);
        $productsRepo->method('findById')->willReturn($product);
        $productsRepo->method('findGalleryImageIds')->willReturn([]);
        $productsRepo->method('findDownloadFileIds')->willReturn([]);
        $productsRepo->method('findVariationsByProductId')->willReturn([]);

        $plansRepo = $this->createStub(\ValeApp\Domain\Membership\Repository\PlanRepositoryInterface::class);
        $plansRepo->method('findAll')->willReturn([]);

        $controller = new ProductController(
            $this->createStub(Translator::class),
            $this->createStub(Twig::class),
            $productsRepo,
            $this->createStub(ProductCategoryRepositoryInterface::class),
            $this->createStub(MediaRepositoryInterface::class),
            $this->createStub(SlugService::class),
            $this->createStub(SessionManager::class),
            $this->createStub(DbalSettingsRepository::class),
            $this->createStub(SeoMetaRepositoryInterface::class),
            $this->createStub(SaveSeoMetaUseCase::class),
            $this->createStub(RedirectService::class),
            new AdminSearchCache($this->createStub(CacheInterface::class)),
            $plansRepo
        );
        $request  = $this->createStub(ServerRequestInterface::class);
        $response = new Response();

        $res = $controller->exportCsv($request, $response, ['id' => 1]);

        $csv = (string) $res->getBody();

        $this->assertStringContainsString("'=Evil Product Name", $csv);
        $this->assertStringContainsString("'+SKU123", $csv);
        $this->assertStringContainsString("'-Dangerous Short Description", $csv);
    }
}
