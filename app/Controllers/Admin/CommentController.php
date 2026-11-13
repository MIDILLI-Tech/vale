<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Comment\Service\CommentNotificationService;
use ValeApp\Domain\Comment\Entity\Comment;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Cache\PageCacheInvalidator;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class CommentController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly CommentRepositoryInterface $comments,
        private readonly SessionManager $session,
        private readonly Connection $db,
        private readonly CommentNotificationService $notifications,
        private readonly ContentTypeRepositoryInterface $contentTypes,
        private readonly SettingsRepositoryInterface $settings,
        private readonly AdminSearchCache $searchCache,
        private readonly ?PageCacheInvalidator $pageCacheInvalidator = null,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params           = $request->getQueryParams();
        $filters          = [];
        $entityTypeFilter = [];

        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }
        if (!empty($params['entity_type'])) {
            $filters['entity_type']          = $params['entity_type'];
            $entityTypeFilter['entity_type'] = $params['entity_type'];
        }
        if (!empty($params['search'])) {
            $filters['search'] = $params['search'];
        }

        $baseCount  = $this->comments->count($entityTypeFilter);
        $clientSide = $baseCount <= client_side_search_fetch_limit() && empty($filters['search']);

        if ($clientSide) {
            $pagination = new Pagination(1, 100000);
            $comments   = $this->comments->findAll($pagination, $filters);
            $total      = $baseCount;
        } else {
            $page       = max(1, (int) ($params['page'] ?? 1));
            $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 20)));
            $pagination = new Pagination($page, $perPage, $params['sort'] ?? null, $params['dir'] ?? 'desc');

            $searchKey = $this->commentSearchKeyParts($filters, $pagination);
            $cached    = $searchKey !== null
                ? $this->searchCache->getObjects('comment_search', $searchKey, [Comment::class])
                : null;
            $comments = $cached !== null ? $cached['items'] : null;

            if ($comments === null) {
                $total    = $this->comments->count($filters);
                $comments = $this->comments->findAll($pagination, $filters);
                if ($searchKey !== null) {
                    $this->searchCache->setObjects('comment_search', $searchKey, $total, $comments, 120);
                }
            } else {
                $total = $cached['total'];
            }
        }

        $counts = [
            'all'      => $this->comments->count($entityTypeFilter),
            'pending'  => $this->comments->count(array_merge($entityTypeFilter, ['status' => 'pending'])),
            'approved' => $this->comments->count(array_merge($entityTypeFilter, ['status' => 'approved'])),
            'spam'     => $this->comments->count(array_merge($entityTypeFilter, ['status' => 'spam'])),
        ];

        $entityTitles             = $this->buildEntityTitles($comments);
        $parentComments           = $this->buildParentComments($comments);
        $contentTypesWithComments = array_values(array_filter(
            $this->contentTypes->findAll(),
            fn ($ct) => in_array('comments', $ct->getSupports(), true) && $ct->isActive()
        ));

        // Build entity type options for the filter dropdown
        // Include 'product' (Reviews) when shop is enabled AND product reviews are enabled
        $entityTypeOptions = [];
        foreach ($contentTypesWithComments as $ct) {
            $entityTypeOptions[(string) $ct->getSlug()] = $ct->getName();
        }
        $shopEnabled           = $this->settings->get('shop_enabled', '1')            === '1';
        $productReviewsEnabled = $this->settings->get('product_reviews_enabled', '1') === '1';
        if ($shopEnabled && $productReviewsEnabled) {
            $entityTypeOptions['product'] = $this->translator->get('Reviews');
        }

        return $this->twig->render($response, 'admin/comments/index.twig', [
            'comments'                  => $comments,
            'total'                     => $total,
            'filters'                   => $params,
            'counts'                    => $counts,
            'entityTitles'              => $entityTitles,
            'parentComments'            => $parentComments,
            'contentTypesWithComments'  => $contentTypesWithComments,
            'entityTypeOptions'         => $entityTypeOptions,
            'client_side'               => $clientSide,
            'page'                      => $clientSide ? 1 : $pagination->page,
            'per_page'                  => $clientSide ? 20 : $pagination->perPage,
            'base_url'                  => admin_url('/comments'),
        ]);
    }

    /** Approve/spam/trash — POST /admin/comments/{id}/moderate */
    public function moderate(Request $request, Response $response, array $args): Response
    {
        $id      = (int) $args['id'];
        $body    = (array) $request->getParsedBody();
        $action  = $body['action'] ?? '';
        $comment = $this->comments->findById($id);

        if ($comment !== null && in_array($action, ['approve', 'spam', 'trash', 'pending'], true)) {
            $wasAlreadyApproved = $comment->isApproved();
            match ($action) {
                'approve' => $comment->approve(),
                'spam'    => $comment->spam(),
                'trash'   => $comment->trash(),
                'pending' => $comment->setStatus('pending'),
            };
            $this->comments->save($comment);
            $this->pageCacheInvalidator?->forComment($comment->getEntityType(), (int) $comment->getEntityId());
            $this->searchCache->bump('comment_search');

            // Notify parent author if this was just approved and is a reply
            if ($action === 'approve' && !$wasAlreadyApproved && $comment->getParentId()) {
                $this->notifications->sendReplyNotification($comment);
            }
        }

        $referer = $request->getHeaderLine('Referer') ?: admin_url();
        return $response->withHeader('Location', $referer)->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $comment = $this->comments->findById((int) $args['id']);
        $this->comments->delete((int) $args['id']);
        if ($comment !== null) {
            $this->pageCacheInvalidator?->forComment($comment->getEntityType(), (int) $comment->getEntityId());
        }
        $this->searchCache->bump('comment_search');
        return $response->withHeader('Location', admin_url('/comments'))->withStatus(302);
    }

    /** POST /admin/bulk/comments */
    public function bulkAction(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $rawIds = $body['ids'] ?? '[]';
        if (is_string($rawIds) && str_contains($rawIds, ',')) {
            $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        } else {
            $ids = array_filter(array_map('intval', is_array($rawIds) ? $rawIds : (array) json_decode((string) $rawIds, true)));
        }
        $action = $body['action'] ?? '';

        if ($ids === []) {
            $this->session->set('flash_error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/comments'))->withStatus(302);
        }

        $count = 0;

        foreach ($ids as $id) {
            $id      = (int) $id;
            $comment = $this->comments->findById($id);
            if ($comment === null) {
                continue;
            }

            if ($action === 'delete') {
                $this->comments->delete($id);
                $this->pageCacheInvalidator?->forComment($comment->getEntityType(), (int) $comment->getEntityId());
                $count++;
            } elseif (in_array($action, ['approve', 'spam', 'unapprove'], true)) {
                $wasAlreadyApproved = $comment->isApproved();
                match ($action) {
                    'approve'    => $comment->approve(),
                    'spam'       => $comment->spam(),
                    'unapprove'  => $comment->setStatus('pending'),
                };
                $this->comments->save($comment);
                $this->pageCacheInvalidator?->forComment($comment->getEntityType(), (int) $comment->getEntityId());

                if ($action === 'approve' && !$wasAlreadyApproved && $comment->getParentId()) {
                    $this->notifications->sendReplyNotification($comment);
                }
                $count++;
            }
        }

        $this->searchCache->bump('comment_search');

        $this->session->set('flash_success', $this->translator->get(':count comment(s) updated.', [':count' => (string) $count]));
        return $response->withHeader('Location', admin_url('/comments'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $comment = $this->comments->findById((int) $args['id']);
        if ($comment === null) {
            return $response->withStatus(404);
        }

        $entityTitles = $this->buildEntityTitles([$comment]);
        $entityTitle  = $entityTitles[$comment->getEntityType()][$comment->getEntityId()] ?? null;

        return $this->twig->render($response, 'admin/comments/edit.twig', [
            'comment'     => $comment,
            'entityTitle' => $entityTitle,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $comment = $this->comments->findById((int) $args['id']);
        if ($comment === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();

        // Only allow editing safe fields — no HTML, trimmed, non-empty
        $authorName  = trim(strip_tags($body['author_name'] ?? ''));
        $authorEmail = trim($body['author_email'] ?? '');
        $commentBody = trim(strip_tags($body['body'] ?? ''));
        $status      = $body['status'] ?? $comment->getStatus();

        if ($authorName === '' || $commentBody === '' || !filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
            $this->session->set('flash_error', $this->translator->get('Invalid input.'));
            return $response->withHeader('Location', admin_url('/comments'))->withStatus(302);
        }

        if (!in_array($status, ['pending', 'approved', 'spam', 'trash'], true)) {
            $status = $comment->getStatus();
        }

        $comment->setAuthorName($authorName);
        $comment->setAuthorEmail($authorEmail);
        $comment->setBody($commentBody);
        $wasAlreadyApproved = $comment->isApproved();
        $comment->setStatus($status);
        $comment->setNotifyReplies(($body['notify_replies'] ?? '0') === '1');

        $this->comments->save($comment);
        $this->pageCacheInvalidator?->forComment($comment->getEntityType(), (int) $comment->getEntityId());
        $this->searchCache->bump('comment_search');

        // Notify parent author if this was just approved and is a reply
        if ($comment->isApproved() && !$wasAlreadyApproved && $comment->getParentId()) {
            $this->notifications->sendReplyNotification($comment);
        }
        $this->session->set('flash_success', $this->translator->get('Comment updated.'));
        return $response->withHeader('Location', admin_url('/comments'))->withStatus(302);
    }

    /**
     * Batch-load entity titles for the given comments to avoid N+1.
     * Returns ['post' => [id => title], 'product' => [id => name], ...]
     *
     * @param  Comment[] $comments
     * @return array<string, array<int, string>>
     */
    private function buildEntityTitles(array $comments): array
    {
        $postIds    = [];
        $productIds = [];

        foreach ($comments as $c) {
            if ($c->getEntityType() === 'product') {
                $productIds[] = $c->getEntityId();
            } else {
                // both 'post' and 'page' live in the posts table
                $postIds[] = $c->getEntityId();
            }
        }

        $titles = ['post' => [], 'page' => [], 'product' => []];

        if ($postIds) {
            $uniquePostIds  = array_values(array_unique($postIds));
            $placeholders   = implode(',', array_fill(0, count($uniquePostIds), '?'));
            $rows           = $this->db->fetchAllAssociative(
                "SELECT id, title, content_type FROM " . VL_DB_PREFIX . "posts WHERE id IN ({$placeholders})",
                $uniquePostIds,
            );
            foreach ($rows as $row) {
                $type                            = $row['content_type'] ?? 'post';
                $titles[$type][(int) $row['id']] = $row['title'];
            }
        }

        if ($productIds) {
            $uniqueProductIds = array_values(array_unique($productIds));
            $placeholders     = implode(',', array_fill(0, count($uniqueProductIds), '?'));
            $rows             = $this->db->fetchAllAssociative(
                "SELECT id, name FROM " . VL_DB_PREFIX . "products WHERE id IN ({$placeholders})",
                $uniqueProductIds,
            );
            foreach ($rows as $row) {
                $titles['product'][(int) $row['id']] = $row['name'];
            }
        }

        return $titles;
    }

    /**
     * Batch-load parent comment info for replies to avoid N+1.
     * Returns ['parentId' => ['authorName' => ..., 'entityType' => ..., 'entityId' => ...], ...]
     *
     * @param  Comment[] $comments
     * @return array<int, array{authorName: string, entityType: string, entityId: int}>
     */
    private function buildParentComments(array $comments): array
    {
        $parentIds = [];
        foreach ($comments as $c) {
            if ($c->getParentId() !== null && $c->getParentId() > 0) {
                $parentIds[] = (int) $c->getParentId();
            }
        }

        if ($parentIds === []) {
            return [];
        }

        $uniqueParentIds  = array_values(array_unique($parentIds));
        $placeholders     = implode(',', array_fill(0, count($uniqueParentIds), '?'));
        $rows             = $this->db->fetchAllAssociative(
            'SELECT id, author_name, entity_type, entity_id FROM ' . VL_DB_PREFIX . 'comments WHERE id IN (' . $placeholders . ')',
            $uniqueParentIds,
            array_fill(0, count($uniqueParentIds), ParameterType::INTEGER),
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = [
                'authorName'  => (string) $row['author_name'],
                'entityType'  => (string) $row['entity_type'],
                'entityId'    => (int) $row['entity_id'],
            ];
        }

        return $result;
    }

    /**
     * Build cache key parts when a server-side search/filter is active.
     * Returns null for unfiltered views (handled client-side).
     *
     * @return array<string, mixed>|null
     */
    private function commentSearchKeyParts(array $filters, Pagination $pagination): ?array
    {
        $search     = trim($filters['search'] ?? '');
        $status     = $filters['status']      ?? '';
        $entityType = $filters['entity_type'] ?? '';
        if ($search === '' && $status === '' && $entityType === '') {
            return null;
        }
        return [
            'v'          => 1,
            'search'     => $search,
            'status'     => $status,
            'entityType' => $entityType,
            'sort'       => $pagination->sortBy  ?? '',
            'dir'        => $pagination->sortDir ?? 'desc',
            'page'       => $pagination->page,
            'perPage'    => $pagination->perPage,
        ];
    }
}
