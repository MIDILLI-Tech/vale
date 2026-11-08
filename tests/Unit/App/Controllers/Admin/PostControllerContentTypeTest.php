<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\PostController;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Application\Seo\SaveSeoMetaUseCase;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class PostControllerContentTypeTest extends TestCase
{
    private PostController $controller;

    private $contentTypes;

    protected function setUp(): void
    {
        $this->contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);

        $this->controller = new PostController(
            translator: $this->makeTranslator(),
            twig: $this->makeTwig(),
            posts: $this->createStub(PostRepositoryInterface::class),
            categories: $this->createStub(CategoryRepositoryInterface::class),
            tags: $this->createStub(TagRepositoryInterface::class),
            users: $this->createStub(UserRepositoryInterface::class),
            savePost: new SavePostUseCase(
                $this->createStub(PostRepositoryInterface::class),
                $this->createStub(SlugService::class),
                $this->createStub(RevisionRepositoryInterface::class),
                $this->createStub(SettingsRepositoryInterface::class),
                $this->createStub(RedirectService::class),
                $this->createStub(ContentTypeRepositoryInterface::class),
            ),
            saveSeo: $this->createStub(SaveSeoMetaUseCase::class),
            seoRepo: $this->createStub(SeoMetaRepositoryInterface::class),
            session: $this->makeSession(),
            products: $this->createStub(ProductRepositoryInterface::class),
            revisions: $this->createStub(RevisionRepositoryInterface::class),
            settings: $this->makeSettings(),
            contentTypes: $this->contentTypes,
            plans: $this->createStub(PlanRepositoryInterface::class),
            events: $this->createStub(EventDispatcherInterface::class),
            webhookService: new OutboundWebhookService(
                $this->createStub(WebhookRepositoryInterface::class),
            ),
            slugService: $this->createStub(SlugService::class),
            redirects: $this->createStub(RedirectService::class),
        );
    }

    private function makeTranslator(): Translator
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->method('getAvailableLocales')->willReturn(['en', 'tr']);
        return $translator;
    }

    private function makeTwig(): Twig
    {
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            static fn ($response) => $response
        );
        return $twig;
    }

    private function makeSession(): SessionManager
    {
        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturn('super_admin');
        return $session;
    }

    private function makeSettings(): DbalSettingsRepository
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null) => $default
        );
        return $settings;
    }

    public function testCreateAcceptsArrayContentTypeQueryParam(): void
    {
        $type = new ContentType('Post', new Slug('post'));
        $this->contentTypes->method('findBySlug')->willReturn($type);

        $request  = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/posts/create')
            ->withQueryParams(['content_type' => ['value' => 'test']])
            ->withAttribute('user_role', 'super_admin');

        $response = $this->controller->create($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCreateAcceptsPlainStringContentTypeQueryParam(): void
    {
        $type = new ContentType('Post', new Slug('post'));
        $this->contentTypes->method('findBySlug')->willReturn($type);

        $request  = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/posts/create')
            ->withQueryParams(['content_type' => 'post'])
            ->withAttribute('user_role', 'super_admin');

        $response = $this->controller->create($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testCreateDefaultsToPostWhenContentTypeParamIsMissing(): void
    {
        $type = new ContentType('Post', new Slug('post'));
        $this->contentTypes->method('findBySlug')->willReturn($type);

        $request  = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/posts/create')
            ->withAttribute('user_role', 'super_admin');

        $response = $this->controller->create($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
    }
}
