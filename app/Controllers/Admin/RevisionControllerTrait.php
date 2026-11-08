<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ValeApp\Domain\Content\DiffHelper;
use ValeApp\Domain\Content\Entity\Revision;
use ValeApp\Infrastructure\Translation\Translator;

trait RevisionControllerTrait
{
    abstract protected function revisionUrlPrefix(): string;

    public function revisionsJson(Request $request, Response $response, array $args): Response
    {
        $postId = (int) $args['id'];
        $post   = $this->posts->findById($postId);
        if ($post === null) {
            return $response->withStatus(404);
        }

        $revisions    = $this->revisions->findByPostId($postId);
        $userCache    = [];
        $currentTitle = $post->getTitle();
        $data         = [];

        foreach ($revisions as $i => $rev) {
            $nextRev  = $revisions[$i + 1] ?? null;
            $authorId = $rev->getAuthorId();
            if (!isset($userCache[$authorId])) {
                $user                 = $this->users->findById($authorId);
                $userCache[$authorId] = $user?->getName() ?? 'Unknown';
            }

            $data[] = [
                'id'                   => $rev->getId(),
                'revision_number'      => $rev->getRevisionNumber(),
                'prev_revision_number' => $nextRev?->getRevisionNumber(),
                'author_name'          => $userCache[$authorId],
                'title'                => $rev->getTitle(),
                'created_at'           => $rev->getCreatedAt()->format('Y-m-d H:i:s'),
                'title_diff'           => DiffHelper::toHtml($currentTitle, $rev->getTitle()),
            ];
        }

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function revisionDetail(Request $request, Response $response, array $args): Response
    {
        $revId  = (int) $args['revId'];
        $postId = (int) $args['id'];

        $rev = $this->revisions->findById($revId);
        if ($rev === null || $rev->getPostId() !== $postId) {
            return $response->withStatus(404);
        }

        $data = [
            'id'            => $rev->getId(),
            'body'          => $rev->getBody(),
            'body_readable' => DiffHelper::toReadableText($rev->getBody()),
        ];

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function revisionDiff(Request $request, Response $response, array $args): Response
    {
        $revId  = (int) $args['revId'];
        $postId = (int) $args['id'];
        $type   = $args['type'] ?? '';

        if (!in_array($type, ['current', 'previous'], true)) {
            return $response->withStatus(400);
        }

        $post = $this->posts->findById($postId);
        if ($post === null) {
            return $response->withStatus(404);
        }

        $rev = $this->revisions->findById($revId);
        if ($rev === null || $rev->getPostId() !== $postId) {
            return $response->withStatus(404);
        }

        if ($type === 'current') {
            $oldBody = $post->getBody();
            $newBody = $rev->getBody();
        } else {
            $prevRev = $this->revisions->findPreviousByPostId($postId, $rev->getRevisionNumber());
            if ($prevRev === null) {
                $diff = '<div class="text-gray-500 dark:text-gray-400 p-3 italic">'
                    . $this->translator->get('No previous revision.') . '</div>';
                $response->getBody()->write(json_encode(['diff' => $diff], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json');
            }
            $oldBody = $prevRev->getBody();
            $newBody = $rev->getBody();
        }

        $lines = DiffHelper::diffHtml($oldBody, $newBody);

        if (!DiffHelper::hasChanges($lines)) {
            $diff = '<div class="text-gray-500 dark:text-gray-400 p-3 italic">'
                . $this->translator->get('No changes.') . '</div>';
        } else {
            $diff = DiffHelper::renderLines($lines);
        }

        $response->getBody()->write(json_encode(['diff' => $diff], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function restoreRevision(Request $request, Response $response, array $args): Response
    {
        $postId = (int) $args['id'];
        $revId  = (int) $args['revId'];

        $post = $this->posts->findById($postId);
        if ($post === null) {
            return $response->withStatus(404);
        }

        $rev = $this->revisions->findById($revId);
        if ($rev === null || $rev->getPostId() !== $postId) {
            return $response->withStatus(404);
        }

        $permissions = $request->getAttribute('user_permissions', $this->session->get('user_permissions', []));
        if (
            !in_array('posts.edit_others', $permissions, true)
            && $post->getAuthorId() !== (int) $this->session->get('user_id')
        ) {
            return $response->withStatus(403);
        }

        $userId = (int) $this->session->get('user_id');

        $snapshot = new Revision(
            $postId,
            $userId,
            $post->getTitle(),
            $post->getBody(),
            $post->getExcerpt(),
            $this->revisions->nextRevisionNumber($postId),
        );
        $this->revisions->save($snapshot);
        $this->revisions->deleteOldestByPostId($postId, $this->getMaxRevisions());

        $post->setTitle($rev->getTitle());
        $post->setBody($rev->getBody());
        $post->setExcerpt($rev->getExcerpt());
        $this->posts->save($post);

        $prefix     = $this->revisionUrlPrefix();
        $flashValue = $this->translator->get(
            'Revision #:number restored successfully.',
            [':number' => (string) $rev->getRevisionNumber()],
        );
        $this->session->set('flash_success', $flashValue);
        return $response->withHeader('Location', admin_url('/' . $prefix . '/' . $postId . '/edit'))->withStatus(302);
    }

    public function deleteRevision(Request $request, Response $response, array $args): Response
    {
        $postId = (int) $args['id'];
        $revId  = (int) $args['revId'];

        $rev = $this->revisions->findById($revId);
        if ($rev === null || $rev->getPostId() !== $postId) {
            return $response->withStatus(404);
        }

        $this->revisions->deleteById($revId);
        $prefix = $this->revisionUrlPrefix();
        $this->session->set('flash_success', $this->translator->get('Revision deleted.'));
        return $response->withHeader('Location', admin_url('/' . $prefix . '/' . $postId . '/edit'))->withStatus(302);
    }

    private function getMaxRevisions(): int
    {
        return max(0, (int) $this->settings->get('revisions_max', '10'));
    }
}
