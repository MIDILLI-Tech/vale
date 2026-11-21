<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\ContentTypeController;
use ValeApp\Domain\Content\Entity\ContentType;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class ContentTypeControllerTest extends TestCase
{
    private $translator;
    private $twig;
    private $contentTypes;
    private $session;
    private $roles;
    private $redirects;
    private $settings;
    private $posts;
    private $routing;
    private $controller;

    protected function setUp(): void
    {
        $this->translator   = $this->createStub(Translator::class);
        $this->twig         = $this->createStub(Twig::class);
        $this->contentTypes = $this->createStub(ContentTypeRepositoryInterface::class);
        $this->session      = $this->createStub(SessionManager::class);
        $this->roles        = $this->createStub(RoleRepositoryInterface::class);
        $this->redirects    = $this->createStub(RedirectService::class);
        $this->settings     = $this->createStub(DbalSettingsRepository::class);
        $this->posts        = $this->createStub(PostRepositoryInterface::class);
        $this->routing      = new RoutingValidator($this->settings, $this->contentTypes, $this->posts);

        $ctr = new ContentTypeController(
            $this->translator,
            $this->twig,
            $this->contentTypes,
            $this->session,
            $this->roles,
            $this->redirects,
            $this->settings,
            $this->posts,
            $this->routing
        );
    }

    public function testStoreFailsWhenCustomFieldKeyIsEmpty(): void
    {
        $this->session = $this->createMock(SessionManager::class);
        $this->twig    = $this->createMock(Twig::class);

        $this->controller = new ContentTypeController(
            $this->translator,
            $this->twig,
            $this->contentTypes,
            $this->session,
            $this->roles,
            $this->redirects,
            $this->settings,
            $this->posts,
            $this->routing
        );

        $data = [
            'name'              => 'Portfolio',
            'slug'              => 'portfolio',
            'field_schema_json' => json_encode([
                ['type' => 'text', 'label' => '', 'name' => ''],
            ]),
        ];

        $request = (new RequestFactory())->createRequest('POST', '/admin/content-types')
            ->withParsedBody($data);

        $this->session->expects(self::once())->method('set')->with('flash_error', self::anything());
        $this->twig->expects(self::once())->method('render')->with(
            self::anything(),
            'admin/content-types/form.twig',
            self::anything()
        )->willReturn((new ResponseFactory())->createResponse(422));

        $response = $this->controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
    }

    public function testStoreAutoGeneratesSlugFromNameWhenSlugIsEmpty(): void
    {
        $contentTypes = $this->createMock(ContentTypeRepositoryInterface::class);
        $contentTypes->method('slugExists')->willReturn(false);
        $contentTypes->method('findAll')->willReturn([]);

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturn('');

        $savedSlug = null;
        $contentTypes->expects(self::once())->method('save')
            ->willReturnCallback(static function (ContentType $type) use (&$savedSlug): ContentType {
                $savedSlug = (string) $type->getSlug();
                return $type;
            });

        $controller = new ContentTypeController(
            $this->translator,
            $this->twig,
            $contentTypes,
            $this->session,
            $this->roles,
            $this->redirects,
            $settings,
            $this->posts,
            new RoutingValidator($settings, $contentTypes, $this->posts)
        );

        $request = (new RequestFactory())->createRequest('POST', '/admin/content-types')
            ->withParsedBody([
                'name'              => 'Custom Content',
                'slug'              => '',
                'field_schema_json' => '[]',
            ]);

        $response = $controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('custom-content', $savedSlug);
    }

    public function testStoreSuffixesGeneratedSlugWhenAPageAlreadyUsesIt(): void
    {
        $page = new Post('test', new Slug('test'), 1);
        $page->setContentType('page');
        $page->setStatus('published');

        $contentTypes = $this->createMock(ContentTypeRepositoryInterface::class);
        $contentTypes->method('slugExists')->willReturn(false);
        $contentTypes->method('findAll')->willReturn([]);

        $posts = $this->createStub(PostRepositoryInterface::class);
        $posts->method('findAll')->willReturn([$page]);
        $posts->method('buildPagePath')->willReturn('test');

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturn('');

        $savedSlug = null;
        $contentTypes->expects(self::once())->method('save')
            ->willReturnCallback(static function (ContentType $type) use (&$savedSlug): ContentType {
                $savedSlug = (string) $type->getSlug();
                return $type;
            });

        $controller = new ContentTypeController(
            $this->translator,
            $this->twig,
            $contentTypes,
            $this->session,
            $this->roles,
            $this->redirects,
            $settings,
            $posts,
            new RoutingValidator($settings, $contentTypes, $posts)
        );

        $request = (new RequestFactory())->createRequest('POST', '/admin/content-types')
            ->withParsedBody([
                'name'              => 'test',
                'slug'              => '',
                'field_schema_json' => '[]',
            ]);

        $response = $controller->store($request, (new ResponseFactory())->createResponse());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('test-2', $savedSlug);
    }
}
