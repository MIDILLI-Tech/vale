<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Admin\ContentTypeController;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\RoutingValidator;
use ValeApp\Domain\Roles\Repository\RoleRepositoryInterface;
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
}
