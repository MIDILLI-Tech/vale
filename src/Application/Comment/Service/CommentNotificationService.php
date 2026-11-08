<?php

declare(strict_types=1);

namespace ValeApp\Application\Comment\Service;

use ValeApp\Application\Mail\MailService;
use ValeApp\Domain\Comment\Entity\Comment;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;

final class CommentNotificationService
{
    public function __construct(
        private readonly CommentRepositoryInterface $comments,
        private readonly PostRepositoryInterface $posts,
        private readonly ProductRepositoryInterface $products,
        private readonly MailService $mail,
        private readonly SettingsRepositoryInterface $settings,
        private readonly ContentTypeRepositoryInterface $contentTypes,
    ) {
    }

    public function sendReplyNotification(Comment $reply): void
    {
        if ($this->mail === null || !$reply->getParentId()) {
            return;
        }

        $parent = $this->comments->findById($reply->getParentId());
        if ($parent === null || !$parent->isNotifyReplies() || !$parent->getAuthorEmail()) {
            return;
        }

        // Don't notify if replying to oneself
        if (strtolower(trim($reply->getAuthorEmail())) === strtolower(trim($parent->getAuthorEmail()))) {
            return;
        }

        $entityTitle = '';
        $entityUrl   = '';
        if ($reply->getEntityType() === 'post') {
            $post = $this->posts->findById($reply->getEntityId());
            if ($post !== null) {
                $entityTitle = $post->getTitle();
                $entityUrl   = absolute_url('/' . ltrim((string) $post->getSlug(), '/'));
            }
        } elseif ($reply->getEntityType() === 'product') {
            $product = $this->products->findById($reply->getEntityId());
            if ($product !== null) {
                $entityTitle = $product->getName();
                $entityUrl   = absolute_url('/shop/' . $product->getSlug());
            }
        } else {
            $post = $this->posts->findById($reply->getEntityId());
            if ($post !== null) {
                $entityTitle = $post->getTitle();
                $entityUrl   = absolute_url('/' . ltrim((string) $post->getSlug(), '/'));
            }
        }

        $commentUrl = $this->buildCommentUrl($reply, $entityUrl);

        $context = [
            'author_name'     => $reply->getAuthorName(),
            'comment_content' => $reply->getBody(),
            'entity_title'    => $entityTitle ?: "#{$reply->getEntityId()}",
            'entity_url'      => $entityUrl ?: absolute_url('/'),
            'comment_url'     => $commentUrl,
        ];

        try {
            $recipientLocale = $parent->getLocale() ?: $this->settings->get('locale', 'en');
            $this->mail->sendTemplate(
                $parent->getAuthorEmail(),
                $this->mail->getTranslator()->get('New Reply to Your Comment', [], $recipientLocale),
                'mail/comment-reply',
                $context,
                locale: $recipientLocale,
            );
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    private function buildCommentUrl(Comment $reply, string $entityUrl): string
    {
        if ($entityUrl === '') {
            return absolute_url('/#comments');
        }

        $commentsPP  = max(1, (int) $this->settings->get('comments_per_page', '5'));
        $parent      = $this->comments->findById($reply->getParentId());
        $parentId    = $parent?->getId() ?? $reply->getParentId();

        $topLevel    = $this->comments->findTopLevelByEntityPaginated(
            $reply->getEntityType(),
            $reply->getEntityId(),
            new Pagination(1, 9999),
        );

        $page = 1;
        foreach ($topLevel as $i => $tl) {
            if ((int) $tl->getId() === (int) $parentId) {
                $page = (int) ceil(($i + 1) / $commentsPP);
                break;
            }
        }

        return $entityUrl . '?cpage=' . $page . '#comment-' . $reply->getId();
    }
}
