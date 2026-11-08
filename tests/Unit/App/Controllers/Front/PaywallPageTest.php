<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\App\Controllers\Front\PostController;
use ValeApp\Application\Content\BlogFeedService;
use ValeApp\Application\Seo\SeoState;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Seo\Repository\SeoMetaRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;

final class PaywallPageTest extends TestCase
{
    public function testPageFormAndTemplatesContainPaywallAndAccessControls(): void
    {
        $adminPageControllerContent  = file_get_contents(__DIR__ . '/../../../../../app/Controllers/Admin/PageController.php');
        $adminPageFormContent        = file_get_contents(__DIR__ . '/../../../../../resources/views/admin/pages/form.twig');
        $frontPostControllerContent  = file_get_contents(__DIR__ . '/../../../../../app/Controllers/Front/PostController.php');
        $pageTwigContent             = file_get_contents(__DIR__ . '/../../../../../resources/views/page.twig');
        $novaPageTwigContent         = file_get_contents(__DIR__ . '/../../../../../themes/nova/templates/page.twig');
        $vividPageTwigContent        = file_get_contents(__DIR__ . '/../../../../../themes/vivid/templates/page.twig');

        // Admin controller & form checks
        $this->assertStringContainsString('getMembershipTagNames', $adminPageControllerContent);
        $this->assertStringContainsString('membership_tags', $adminPageControllerContent);
        $this->assertStringContainsString('access_tags', $adminPageControllerContent);
        $this->assertStringContainsString('name="access_tags[]"', $adminPageFormContent);

        // Front controller showPage checks
        $this->assertStringContainsString('$accessTags = $page->getAccessTags()', $frontPostControllerContent);
        $this->assertStringContainsString('\'has_access\'', $frontPostControllerContent);

        // Theme templates paywall checks
        $this->assertStringContainsString('{% if has_access is defined and not has_access %}', $pageTwigContent);
        $this->assertStringContainsString('View Membership Plans', $pageTwigContent);

        $this->assertStringContainsString('{% if has_access is defined and not has_access %}', $novaPageTwigContent);
        $this->assertStringContainsString('View Membership Plans', $novaPageTwigContent);

        $this->assertStringContainsString('{% if has_access is defined and not has_access %}', $vividPageTwigContent);
        $this->assertStringContainsString('View Membership Plans', $vividPageTwigContent);
    }

    public function testShowPageGrantsOrRestrictsAccessBasedOnSubscriberTags(): void
    {
        $page = new Post('VIP Secret Page', new Slug('vip-page'), 1);
        $page->setId(5);
        $page->setContentType('page');
        $page->setStatus('published');
        $page->setAccessTags(['pro-plan']);

        $postsRepo = $this->createStub(PostRepositoryInterface::class);
        $postsRepo->method('findPageByPath')->willReturn($page);

        $session = $this->createStub(SessionManager::class);
        $session->method('get')->willReturn(10);

        $subscriber = $this->createStub(Subscriber::class);
        $subscriber->method('getTags')->willReturn(['basic-plan']);

        $subscribersRepo = $this->createStub(SubscriberRepositoryInterface::class);
        $subscribersRepo->method('findByUserId')->willReturn($subscriber);

        $renderedContext = [];
        $twig            = $this->createMock(Twig::class);
        $twig->method('getEnvironment')->willReturn(new \Twig\Environment(new \Twig\Loader\ArrayLoader([])));
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(function ($response, $template, $context) use (&$renderedContext) {
                $renderedContext = $context;
                return $response;
            });

        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturn('0');

        $controller = new PostController(
            $twig,
            $postsRepo,
            $this->createStub(CommentRepositoryInterface::class),
            $settings,
            $this->createStub(UserRepositoryInterface::class),
            $session,
            $this->createStub(CategoryRepositoryInterface::class),
            $this->createStub(TagRepositoryInterface::class),
            $this->createStub(SeoMetaRepositoryInterface::class),
            new SeoState(),
            new BlogFeedService(
                $postsRepo,
                $this->createStub(\ValeApp\Domain\Media\Repository\MediaRepositoryInterface::class),
                'http://example.com'
            ),
            $this->createStub(ContentTypeRepositoryInterface::class),
            $subscribersRepo
        );

        $request  = $this->createStub(ServerRequestInterface::class);
        $response = new \Slim\Psr7\Response();

        $controller->showPage($request, $response, ['path' => 'vip-page']);

        $this->assertFalse($renderedContext['has_access']);
        $this->assertSame(['pro-plan'], $renderedContext['access_tags']);
    }
}
