<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\ProductCategoryController;
use ValeApp\Domain\Shop\Repository\ProductCategoryRepositoryInterface;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class ProductCategoryControllerTest extends TestCase
{
    private Translator $translator;
    private Twig $twig;
    private ProductCategoryRepositoryInterface $categories;
    private DbalSettingsRepository $settings;

    protected function setUp(): void
    {
        $this->translator = $this->createStub(Translator::class);
        $this->translator->method('getLocale')->willReturn('tr');
        $this->translator->method('getAvailableLocales')->willReturn(['en' => 'English', 'tr' => 'Türkçe']);

        $this->twig = $this->createStub(Twig::class);

        $this->categories = $this->createStub(ProductCategoryRepositoryInterface::class);
        $this->categories->method('findAll')->willReturn([]);
        $this->categories->method('countUsage')->willReturn([]);
        $this->categories->method('findTranslationsForRoots')->willReturn([]);
        $this->categories->method('findPotentialOriginals')->willReturn([]);

        $this->settings = $this->createStub(DbalSettingsRepository::class);
        $this->settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $default,
        );
    }

    private function buildController(): ProductCategoryController
    {
        return new ProductCategoryController(
            $this->translator,
            $this->twig,
            $this->categories,
            $this->createStub(SlugService::class),
            $this->createStub(SessionManager::class),
            $this->settings,
            $this->createStub(RedirectService::class),
        );
    }

    /**
     * @return array{language: ?string, filters: array<string, mixed>, defaultLanguage: ?string}
     */
    private function captureIndex(string $url): array
    {
        $captured = ['language' => 'unset', 'filters' => [], 'defaultLanguage' => 'unset'];

        $this->categories = $this->createStub(ProductCategoryRepositoryInterface::class);
        $this->categories->method('findAll')->willReturnCallback(
            static function (?string $language = null) use (&$captured): array {
                $captured['language'] = $language;
                return [];
            },
        );
        $this->categories->method('countUsage')->willReturn([]);
        $this->categories->method('findTranslationsForRoots')->willReturn([]);
        $this->categories->method('findPotentialOriginals')->willReturn([]);

        $this->twig = $this->createMock(Twig::class);
        $this->twig->expects(self::once())->method('render')->willReturnCallback(
            static function ($response, string $template, array $data) use (&$captured) {
                $captured['filters']         = $data['filters'];
                $captured['defaultLanguage'] = $data['default_category_language'];
                return $response;
            },
        );

        $request = (new RequestFactory())->createRequest('GET', $url);
        $this->buildController()->index($request, (new ResponseFactory())->createResponse());

        return $captured;
    }

    public function testIndexLoadsAllLanguagesByDefault(): void
    {
        $captured = $this->captureIndex('/admin/product-categories');

        self::assertNull($captured['language']);
        self::assertSame([], $captured['filters']);
    }

    public function testIndexFiltersByRequestedLanguage(): void
    {
        $captured = $this->captureIndex('/admin/product-categories?language=en');

        self::assertSame('en', $captured['language']);
        self::assertSame(['language' => 'en'], $captured['filters']);
    }

    public function testIndexTreatsEmptyLanguageAsAllLanguages(): void
    {
        $captured = $this->captureIndex('/admin/product-categories?language=');

        self::assertNull($captured['language']);
    }

    public function testIndexUsesSiteDefaultLanguageWhenNoLanguageIsSelected(): void
    {
        $this->translator = $this->createStub(Translator::class);
        $this->translator->method('getLocale')->willReturn('en');
        $this->translator->method('getAvailableLocales')->willReturn(['en' => 'English', 'tr' => 'Türkçe']);

        $this->settings = $this->createStub(DbalSettingsRepository::class);
        $this->settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => match ($key) {
                'default_locale' => 'tr',
                default          => $default,
            },
        );

        self::assertSame('tr', $this->captureIndex('/admin/product-categories')['defaultLanguage']);
        self::assertSame('en', $this->captureIndex('/admin/product-categories?language=en')['defaultLanguage']);
    }
}
