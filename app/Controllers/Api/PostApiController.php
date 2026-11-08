<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Application\Content\SavePostUseCase;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\Exception\ValidationException;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Media\RemoteImageImporter;

final class PostApiController
{
    public function __construct(
        private readonly PostRepositoryInterface $posts,
        private readonly SavePostUseCase $savePost,
        private readonly TagRepositoryInterface $tags,
        private readonly CategoryRepositoryInterface $categories,
        private readonly RemoteImageImporter $imageImporter,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /** GET /api/v1/posts */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 15)));
        $filters = ['content_type' => 'post', 'status' => 'published'];

        $posts = $this->posts->findAll(new Pagination($page, $perPage), $filters);
        $total = $this->posts->count($filters);

        return $this->json($response, [
            'data' => array_map(fn (Post $p) => $this->serializePost($p), $posts),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /** POST /api/v1/posts or /api/v1/pages */
    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        return $this->saveContent($request, $response, null);
    }

    /** PUT /api/v1/posts/{id} or /api/v1/pages/{id} */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        if ($this->posts->findById($id) === null) {
            return $this->error($response, 404, 'Not Found');
        }
        return $this->saveContent($request, $response, $id);
    }

    /** DELETE /api/v1/posts/{id} or /api/v1/pages/{id} */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (int) ($args['id'] ?? 0);
        $post = $this->posts->findById($id);
        if ($post === null) {
            return $this->error($response, 404, 'Not Found');
        }

        $payload = [
            'post_id'           => $id,
            'post_slug'         => (string) $post->getSlug(),
            'post_content_type' => $post->getContentType(),
        ];
        $this->posts->delete($id);
        $this->events->doAction('post.deleted', $payload);

        return $this->json($response, ['message' => 'Deleted']);
    }

    private function saveContent(ServerRequestInterface $request, ResponseInterface $response, ?int $id): ResponseInterface
    {
        $contentType = $request->getAttribute('_content_type', 'post');
        $input       = (array) $request->getParsedBody();
        $userId      = (int) $request->getAttribute('api_user_id');

        try {
            $data = $this->buildUseCaseData($input, $userId, $contentType, $id);
            $post = $this->savePost->execute($data, $id);

            // Persist an explicit publish date even outside scheduled status
            if (!empty($data['_explicit_date'])) {
                $post->setPublishedAt($data['_explicit_date']);
                $post = $this->posts->save($post);
            }
        } catch (ValidationException $e) {
            return $this->json($response->withStatus(422), [
                'error'  => 'Validation failed',
                'errors' => $e->getErrors(),
            ]);
        }

        $isNew = $id === null;
        $this->events->doAction($isNew ? 'post.created' : 'post.updated', $post);
        if ($post->getStatus() === 'published') {
            $this->events->doAction('post.published', $post);
        }

        return $this->json($response->withStatus($isNew ? 201 : 200), [
            'message' => $isNew ? 'Content created' : 'Content updated',
            'data'    => $this->serializePost($post),
        ]);
    }

    /**
     * Maps API input onto SavePostUseCase data, merging with the existing
     * entity on updates so partial payloads are supported.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildUseCaseData(array $input, int $userId, string $contentType, ?int $id): array
    {
        $existing = $id !== null ? $this->posts->findById($id) : null;

        $pick = static function (string $key, mixed $fallback) use ($input): mixed {
            return array_key_exists($key, $input) && $input[$key] !== null ? $input[$key] : $fallback;
        };

        $title       = (string) $pick('title', $existing?->getTitle() ?? '');
        $body        = $pick('body', $pick('html', $existing?->getBody()));
        $excerpt     = $pick('excerpt', $existing?->getExcerpt());
        $status      = (string) $pick('status', $existing?->getStatus() ?? 'draft');
        $language    = (string) $pick('language', $existing?->getLanguage() ?? 'en');
        $authorId    = (int) $pick('author_id', $existing?->getAuthorId() ?? $userId);
        $metaTitle   = $pick('meta_title', $existing?->getMetaTitle());
        $metaDesc    = $pick('meta_description', $existing?->getMetaDescription());
        $publishedAt = $input['published_at'] ?? null;

        $featuredImageId = $existing?->getFeaturedImageId();
        if (!empty($input['featured_image'])) {
            $featuredImageId = $this->imageImporter->importFromInput((string) $input['featured_image'], $userId)
                ?? $featuredImageId;
        }

        $categoryIds = $existing?->getCategoryIds() ?? [];
        if (isset($input['categories']) && is_array($input['categories'])) {
            $categoryIds = [];
            foreach ($input['categories'] as $cid) {
                $cid = (int) $cid;
                if ($cid > 0 && $this->categories->findById($cid) !== null) {
                    $categoryIds[] = $cid;
                }
            }
            $categoryIds = array_values(array_unique($categoryIds));
        }

        $tagIds = $existing?->getTagIds() ?? [];
        if (isset($input['tags']) && is_array($input['tags'])) {
            $names       = array_map(static fn ($t): string => trim((string) $t), $input['tags']);
            $tagEntities = $this->tags->findOrCreateByNames(array_filter($names), $contentType, $language);
            $tagIds      = array_map(static fn ($t): int => $t->getId(), $tagEntities);
        }

        $data = [
            'title'             => $title,
            'slug'              => isset($input['slug']) ? (string) $input['slug'] : '',
            'body'              => $body    !== null ? (string) $body : null,
            'excerpt'           => $excerpt !== null ? (string) $excerpt : null,
            'status'            => $status,
            'content_type'      => $contentType,
            'language'          => $language,
            'author_id'         => $authorId > 0 ? $authorId : $userId,
            'featured_image_id' => $featuredImageId,
            'meta_title'        => $metaTitle !== null ? (string) $metaTitle : null,
            'meta_description'  => $metaDesc  !== null ? (string) $metaDesc : null,
            'category_ids'      => $categoryIds,
            'tag_ids'           => $tagIds,
            'create_redirect'   => false,
        ];

        if ($publishedAt !== null && $publishedAt !== '') {
            $ts                     = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $publishedAt)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $publishedAt);
            $data['status']         = $ts !== false && $ts > new \DateTimeImmutable() ? 'scheduled' : $status;
            $data['published_at']   = $ts !== false ? $ts->format('Y-m-d\TH:i') : null;
            $data['_explicit_date'] = $ts !== false ? $ts : null;
        }

        return $data;
    }

    /** GET /api/v1/posts/{slug} */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $this->error($response, 404, 'Not Found');
        }

        $post = $this->posts->findBySlug($slug, 'post');
        if ($post === null || $post->getStatus() !== 'published') {
            return $this->error($response, 404, 'Not Found');
        }

        return $this->json($response, ['data' => $this->serializePost($post)]);
    }

    /** GET /api/v1/pages */
    public function pageIndex(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 15)));
        $filters = ['content_type' => 'page', 'status' => 'published'];

        $pages = $this->posts->findAll(new Pagination($page, $perPage), $filters);
        $total = $this->posts->count($filters);

        return $this->json($response, [
            'data' => array_map(fn (Post $p) => $this->serializePage($p), $pages),
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /** GET /api/v1/pages/{slug} */
    public function pageShow(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $this->error($response, 404, 'Not Found');
        }

        $page = $this->posts->findBySlug($slug, 'page');
        if ($page === null || $page->getStatus() !== 'published') {
            return $this->error($response, 404, 'Not Found');
        }

        return $this->json($response, ['data' => $this->serializePage($page)]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function error(ResponseInterface $response, int $status, string $message): ResponseInterface
    {
        return $this->json($response->withStatus($status), ['error' => $message]);
    }

    /** @return array<string, mixed> */
    private function serializePost(Post $post): array
    {
        return [
            'id'           => $post->getId(),
            'slug'         => (string) $post->getSlug(),
            'title'        => $post->getTitle(),
            'excerpt'      => $post->getExcerpt(),
            'body'         => $post->getBody(),
            'status'       => $post->getStatus(),
            'published_at' => $post->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'categories'   => $post->getCategoryIds(),
            'tags'         => $post->getTagIds(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePage(Post $page): array
    {
        return [
            'id'       => $page->getId(),
            'slug'     => (string) $page->getSlug(),
            'title'    => $page->getTitle(),
            'excerpt'  => $page->getExcerpt(),
            'body'     => $page->getBody(),
            'status'   => $page->getStatus(),
            'order'    => $page->getSortOrder(),
            'parentId' => $page->getParentId(),
        ];
    }
}
