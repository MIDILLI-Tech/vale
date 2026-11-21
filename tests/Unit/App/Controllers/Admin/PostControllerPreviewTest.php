<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
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
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class PostControllerPreviewTest extends TestCase
{
    public function testPreviewRendersCustomFieldsFromSubmittedMeta(): void
    {
        $contentType = new ContentType('Portfolio', new Slug('portfolio'));
        $contentType->setFieldSchema([
            ['name' => 'website', 'label' => 'Website', 'type' => 'text'],
        ]);

        $contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $contentTypes->method('findBySlug')->willReturn($contentType);

        $captured = [];
        $twig     = $this->createMock(Twig::class);
        $twig->expects(self::once())->method('render')
            ->willReturnCallback(static function ($response, string $template, array $data) use (&$captured) {
                $captured = ['template' => $template, 'data' => $data];

                return $response;
            });

        $controller = new PostController(
            $this->createStub(Translator::class),
            $twig,
            $this->createStub(PostRepositoryInterface::class),
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
            $this->createStub(UserRepositoryInterface::class),
            (new \ReflectionClass(SavePostUseCase::class))->newInstanceWithoutConstructor(),
            $this->createStub(SaveSeoMetaUseCase::class),
            $this->createStub(SeoMetaRepositoryInterface::class),
            $this->createStub(SessionManager::class),
            $this->createStub(ProductRepositoryInterface::class),
            $this->createStub(RevisionRepositoryInterface::class),
            $this->createStub(DbalSettingsRepository::class),
            $contentTypes,
            $this->createStub(PlanRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            (new \ReflectionClass(OutboundWebhookService::class))->newInstanceWithoutConstructor(),
            $this->createStub(SlugService::class),
            $this->createStub(RedirectService::class),
        );

        $request = (new RequestFactory())->createRequest('POST', '/admin/posts/preview')
            ->withParsedBody([
                'title'        => 'My item',
                'slug'         => '',
                'body'         => 'See {{website}}',
                'content_type' => 'portfolio',
                'meta'         => ['website' => 'https://example.com'],
            ]);

        $response = $controller->preview($request, (new ResponseFactory())->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('post.twig', $captured['template']);
        self::assertTrue($captured['data']['is_preview']);
        self::assertSame($contentType, $captured['data']['type']);

        $preview = $captured['data']['post'];
        self::assertSame('portfolio', $preview->getContentType());
        self::assertSame('https://example.com', $preview->getMetaValue('website'));
        self::assertSame('my-item', (string) $preview->getSlug());
    }
}
