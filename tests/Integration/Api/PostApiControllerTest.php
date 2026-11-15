<?php

declare(strict_types=1);

namespace ValeApp\Tests\Integration\Api;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use ValeApp\App\Controllers\Api\PostApiController;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\RevisionRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\RedirectService;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Media\MediaUploadService;
use ValeApp\Infrastructure\Media\RemoteImageImporter;

final class PostApiControllerTest extends TestCase
{
    private PostRepositoryInterface $posts;
    private PostApiController $controller;

    /** @var array<int, array<string, mixed>> */
    private array $filtersSeen = [];

    protected function setUp(): void
    {
        $this->filtersSeen = [];

        $this->posts = $this->createStub(PostRepositoryInterface::class);
        $this->posts->method('findAll')->willReturnCallback(
            function (Pagination $pagination, array $filters): array {
                $this->filtersSeen[] = $filters;
                return [];
            }
        );
        $this->posts->method('count')->willReturn(0);

        $savePost = new SavePostUseCase(
            $this->posts,
            $this->createStub(SlugService::class),
            $this->createStub(RevisionRepositoryInterface::class),
            $this->createStub(SettingsRepositoryInterface::class),
            $this->createStub(RedirectService::class),
            $this->createStub(ContentTypeRepositoryInterface::class),
        );

        $this->controller = new PostApiController(
            $this->posts,
            $savePost,
            $this->createStub(TagRepositoryInterface::class),
            $this->createStub(CategoryRepositoryInterface::class),
            new RemoteImageImporter(
                $this->createStub(MediaRepositoryInterface::class),
                $this->createStub(MediaUploadService::class),
            ),
            $this->createStub(EventDispatcherInterface::class),
        );
    }

    public function testIndexDefaultsToPublishedPosts(): void
    {
        [$status, $body] = $this->listPosts();

        self::assertSame(200, $status);
        self::assertSame('post', $this->filtersSeen[0]['content_type']);
        self::assertSame('published', $this->filtersSeen[0]['status']);
        self::assertSame('published', $body['meta']['status']);
    }

    public function testIndexFiltersDrafts(): void
    {
        [$status, $body] = $this->listPosts('?status=draft', 'write:posts');

        self::assertSame(200, $status);
        self::assertSame('draft', $this->filtersSeen[0]['status']);
        self::assertSame('draft', $body['meta']['status']);
    }

    public function testIndexAcceptsCommaSeparatedStatuses(): void
    {
        [$status] = $this->listPosts('?status=draft,scheduled', 'write:posts');

        self::assertSame(200, $status);
        self::assertSame(['draft', 'scheduled'], $this->filtersSeen[0]['status']);
    }

    public function testIndexWithAllCoversEveryStatusExceptTrash(): void
    {
        [$status] = $this->listPosts('?status=all', 'write:posts');

        self::assertSame(200, $status);
        self::assertSame(
            ['published', 'draft', 'scheduled', 'unlisted', 'expired', 'archived'],
            $this->filtersSeen[0]['status']
        );
    }

    public function testPageIndexFiltersDrafts(): void
    {
        [$status, $body] = $this->listPages('?status=draft,scheduled', 'write:pages');

        self::assertSame(200, $status);
        self::assertSame('page', $this->filtersSeen[0]['content_type']);
        self::assertSame(['draft', 'scheduled'], $this->filtersSeen[0]['status']);
        self::assertSame(['draft', 'scheduled'], $body['meta']['status']);
    }

    public function testPageIndexRejectsUnknownStatus(): void
    {
        [$status] = $this->listPages('?status=bogus');

        self::assertSame(422, $status);
    }

    public function testPageIndexRequiresPageWriteAbilityForUnpublished(): void
    {
        [$status] = $this->listPages('?status=draft', 'write:posts');

        self::assertSame(403, $status);
    }

    public function testIndexRejectsUnpublishedStatusesWithoutWriteAbility(): void
    {
        [$status, $body] = $this->listPosts('?status=draft', 'read:posts');

        self::assertSame(403, $status);
        self::assertSame([], $this->filtersSeen);
        self::assertStringContainsString('write:posts', $body['message']);
    }

    public function testIndexAllowsWildcardAbility(): void
    {
        [$status] = $this->listPosts('?status=trash', '*');

        self::assertSame(200, $status);
        self::assertSame('trash', $this->filtersSeen[0]['status']);
    }

    public function testIndexRejectsUnknownStatus(): void
    {
        [$status, $body] = $this->listPosts('?status=private');

        self::assertSame(422, $status);
        self::assertStringContainsString('Unknown status "private"', $body['errors']['status'][0]);
        self::assertSame([], $this->filtersSeen);
    }

    public function testShowReturnsPublishedPostForReadOnlyToken(): void
    {
        $this->posts->method('findBySlug')->willReturn($this->makePost('published'));

        [$status, $body] = $this->getSingle('show', 'read:posts');

        self::assertSame(200, $status);
        self::assertSame('published', $body['data']['status']);
    }

    public function testShowReturnsDraftForWriteToken(): void
    {
        $this->posts->method('findBySlug')->willReturn($this->makePost('draft'));

        [$status, $body] = $this->getSingle('show', 'write:posts');

        self::assertSame(200, $status);
        self::assertSame('draft', $body['data']['status']);
    }

    public function testShowHidesDraftFromReadOnlyToken(): void
    {
        $this->posts->method('findBySlug')->willReturn($this->makePost('draft'));

        [$status] = $this->getSingle('show', 'read:posts');

        self::assertSame(404, $status);
    }

    public function testShowHidesTrashedPostFromWriteToken(): void
    {
        $this->posts->method('findBySlug')->willReturn($this->makePost('trash'));

        [$status] = $this->getSingle('show', 'write:posts');

        self::assertSame(404, $status);
    }

    public function testPageShowReturnsDraftForPageWriteToken(): void
    {
        $this->posts->method('findBySlug')->willReturn($this->makePost('draft'));

        [$status] = $this->getSingle('pageShow', 'write:pages');

        self::assertSame(200, $status);
    }

    public function testPageShowHidesDraftWithoutPageWriteAbility(): void
    {
        $this->posts->method('findBySlug')->willReturn($this->makePost('draft'));

        [$status] = $this->getSingle('pageShow', 'write:posts');

        self::assertSame(404, $status);
    }

    /**
     * @param 'show'|'pageShow' $action
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function getSingle(string $action, string $abilities): array
    {
        $request = (new RequestFactory())->createRequest('GET', '/api/v1/posts/draft-post')
            ->withAttribute('api_abilities', $abilities);
        $response = $this->controller->{$action}(
            $request,
            (new ResponseFactory())->createResponse(),
            ['slug' => 'draft-post'],
        );

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function makePost(string $status): Post
    {
        $post = new Post('Draft Post', new Slug('draft-post'), 1);
        $post->setId(5);
        $post->setStatus($status);

        return $post;
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function listPosts(string $query = '', ?string $abilities = null): array
    {
        $request = (new RequestFactory())->createRequest('GET', '/api/v1/posts' . $query);
        if ($abilities !== null) {
            $request = $request->withAttribute('api_abilities', $abilities);
        }
        $response = $this->controller->index($request, (new ResponseFactory())->createResponse());

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function listPages(string $query = '', ?string $abilities = null): array
    {
        $request = (new RequestFactory())->createRequest('GET', '/api/v1/pages' . $query);
        if ($abilities !== null) {
            $request = $request->withAttribute('api_abilities', $abilities);
        }
        $response = $this->controller->pageIndex($request, (new ResponseFactory())->createResponse());

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }
}
