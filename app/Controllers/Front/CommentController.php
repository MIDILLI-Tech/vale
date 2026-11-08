<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ValeApp\Application\Comment\Service\CommentNotificationService;
use ValeApp\Application\Mail\MailService;
use ValeApp\Domain\Comment\Entity\Comment;
use ValeApp\Domain\Comment\Repository\CommentRepositoryInterface;
use ValeApp\Domain\Content\Repository\ContentTypeRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\Shop\Entity\ProductVote;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductRepositoryInterface;
use ValeApp\Domain\Shop\Repository\ProductVoteRepositoryInterface;
use ValeApp\Infrastructure\Analytics\UserAgentParser;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\IpAnonymizer;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class CommentController
{
    use CaptchaTrait;

    public function __construct(
        private readonly CommentRepositoryInterface $comments,
        private readonly PostRepositoryInterface $posts,
        private readonly SessionManager $session,
        private readonly Translator $translator,
        private readonly DbalSettingsRepository $settings,
        private readonly OrderRepositoryInterface $orders,
        private readonly ProductRepositoryInterface $products,
        private readonly ProductVoteRepositoryInterface $votes,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly UserAgentParser $uaParser,
        private readonly MailService $mail,
        private readonly CommentNotificationService $notifications,
        private readonly ContentTypeRepositoryInterface $contentTypes,
    ) {
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body          = (array) $request->getParsedBody();
        $entityType    = $body['entity_type'] ?? 'post';
        $entityId      = (int) ($body['entity_id'] ?? 0);
        $parentId      = isset($body['parent_id']) ? (int) $body['parent_id'] : null;
        $content       = trim($body['content'] ?? '');
        $currentUserId = $this->session->get('user_id');
        if ($currentUserId !== null) {
            $authorName  = (string) ($this->session->get('user_name')  ?? '');
            $authorEmail = (string) ($this->session->get('user_email') ?? '');
        } else {
            $authorName  = trim($body['author_name'] ?? '');
            $authorEmail = trim($body['author_email'] ?? '');
        }

        $referer = $request->getHeaderLine('Referer') ?: '/';

        // Basic validation
        if ($entityId < 1 || $content === '' || $authorName === '' || $authorEmail === '') {
            $this->session->flash('error', $this->translator->get('All fields are required.'));
            return $response->withHeader('Location', $referer)->withStatus(302);
        }

        if (!filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('error', $this->translator->get('Invalid email address.'));
            return $response->withHeader('Location', $referer)->withStatus(302);
        }

        if (!in_array($entityType, ['post', 'product'], true)) {
            $contentTypeInfo = $this->contentTypes->findBySlug(new Slug($entityType));
            if ($contentTypeInfo === null || !in_array('comments', $contentTypeInfo->getSupports(), true)) {
                return $response->withHeader('Location', $referer)->withStatus(302);
            }
        }

        // Captcha validation (skip for logged-in users)
        if ($this->session->get('user_id') === null) {
            if ($entityType === 'product') {
                $reviewType = $this->settings->get('review_captcha_type');
                $key        = ($reviewType !== null && $reviewType !== '') ? 'review_captcha_type' : 'comment_captcha_type';
            } else {
                $key = 'comment_captcha_type';
            }

            $error = $this->validateCaptcha($body, $key);
            if ($error !== null) {
                $this->session->flash('error', $this->translator->get($error));
                return $response->withHeader('Location', $referer)->withStatus(302);
            }
        }

        // Check review restrictions for products
        if ($entityType === 'product') {
            $onlyLoggedIn = $this->settings->get('only_logged_in_can_review', '0')       === '1';
            $onlyVerified = $this->settings->get('only_verified_buyers_can_review', '0') === '1';

            // Check if user is logged in when required
            if ($onlyLoggedIn && $currentUserId === null) {
                $this->session->flash('error', $this->translator->get('You must be logged in to write a review.'));
                return $response->withHeader('Location', $referer)->withStatus(302);
            }

            // Check if user owns the product when required
            if ($onlyVerified && $currentUserId !== null) {
                $hasPurchased = $this->orders->hasPurchasedProduct($authorEmail, $entityId);
                if (!$hasPurchased) {
                    $this->session->flash('error', $this->translator->get('Only verified buyers can write reviews for this product.'));
                    return $response->withHeader('Location', $referer)->withStatus(302);
                }
            }

            // For logged-in users, prevent duplicate reviews on the same product
            if ($currentUserId !== null) {
                $existing = $this->comments->findByUserAndEntity((int) $currentUserId, $entityType, $entityId);
                if ($existing !== null) {
                    $msg = $this->translator->get('You have already submitted a review for this product.');
                    $this->session->flash('error', $msg);
                    return $response->withHeader('Location', $referer)->withStatus(302);
                }
            }
        }

        $comment = new Comment($entityType, $entityId, $authorName, $authorEmail, $content);
        if ($currentUserId !== null) {
            $comment->setUserId((int) $currentUserId);
        }
        if ($parentId !== null && $parentId > 0) {
            $maxDepth    = (int) $this->settings->get('comment_max_depth', '2');
            $parentDepth = $this->comments->calculateDepth($parentId);
            if ($parentDepth < $maxDepth) {
                $comment->setParentId($parentId);
            }
        }

        $comment->setNotifyReplies(($body['notify_replies'] ?? '0') === '1');
        $comment->setLocale($this->translator->getLocale());

        $ipMode   = $this->settings->get('ip_privacy_mode', 'mask') ?? 'mask';
        $authorIp = $this->ipAnonymizer->anonymize(
            vl_client_ip($request),
            $ipMode,
        );
        $comment->setAuthorIp($authorIp);

        // Determine moderation setting based on entity type
        $moderationEnabled = $entityType === 'product'
            ? $this->settings->get('review_moderation', '0')
            : $this->settings->get('comment_moderation', '0');

        // For product reviews, check if the reviewer purchased the product
        if ($entityType === 'product') {
            $comment->setVerified($this->orders->hasPurchasedProduct($authorEmail, $entityId));
        }

        // Auto-approve logic
        if ($moderationEnabled === '0') {
            $comment->approve();
        } elseif ($this->session->get('user_id') !== null) {
            $comment->approve();
        } elseif ($comment->isVerified()) {
            $comment->approve();
        }

        $this->comments->save($comment);

        // Notify admin about new comment/review
        $this->sendAdminNotification($entityType, $entityId, $authorName, $authorEmail, $content, $body);

        // Notify parent author if this is a reply
        if ($comment->isApproved() && $comment->getParentId()) {
            $this->notifications->sendReplyNotification($comment);
        }

        // If this is a product comment and a rating was provided, save the vote too
        if ($entityType === 'product') {
            $rating      = (int) ($body['rating'] ?? 0);
            $starEnabled = $this->settings->get('star_rating_enabled', '1') === '1';
            if ($rating >= 1 && $rating <= 5) {
                $product = $this->products->findById($entityId);
                $vStatus = $product?->getVoteStatus();
                $canVote = $product !== null && ($vStatus === 'enabled' || ($vStatus === 'use_global' && $starEnabled));
                if ($canVote) {
                    $sessionId = $this->session->getId();
                    $userId    = $this->session->get('user_id');
                    $vote      = new ProductVote($product->getId(), $sessionId, $rating, $userId);
                    $vote->setCommentId($comment->getId());
                    $vote->setReview($content);
                    $this->votes->save($vote);
                }
            }
        }

        $this->session->flash('success', $this->translator->get(
            $comment->isApproved() ? 'Your comment has been posted.' : 'Your comment has been submitted for review.'
        ));
        return $response->withHeader('Location', $referer . '#comments')->withStatus(302);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id      = (int) ($args['id'] ?? 0);
        $comment = $this->comments->findById($id);

        if ($comment === null) {
            return $response->withStatus(404);
        }

        // Authorization: only the comment author (logged-in) can edit
        $userId = $this->session->get('user_id');
        if ($userId === null || (int) $comment->getUserId() !== (int) $userId) {
            $this->session->flash('error', $this->translator->get('You can only edit your own comments.'));
            return $response->withHeader('Location', $request->getHeaderLine('Referer') ?: '/')->withStatus(302);
        }

        // Check if editing is allowed globally
        $settingKey = $comment->getEntityType() === 'product' ? 'allow_review_editing' : 'allow_comment_editing';
        if ($this->settings->get($settingKey, '1') !== '1') {
            $this->session->flash('error', $this->translator->get('Comment editing is not allowed.'));
            return $response->withHeader('Location', $request->getHeaderLine('Referer') ?: '/')->withStatus(302);
        }

        $body    = (array) $request->getParsedBody();
        $content = trim($body['content'] ?? '');
        if ($content === '') {
            $this->session->flash('error', $this->translator->get('Comment cannot be empty.'));
            return $response->withHeader('Location', $request->getHeaderLine('Referer') ?: '/')->withStatus(302);
        }

        $comment->setBody($content);
        $comment->setNotifyReplies(($body['notify_replies'] ?? '0') === '1');
        $this->comments->save($comment);

        // Update vote rating if product review
        if ($comment->getEntityType() === 'product') {
            $rating = (int) ($body['rating'] ?? 0);
            $vote   = $this->votes->findByCommentId($comment->getId());

            if ($vote === null && $rating >= 1 && $rating <= 5) {
                // Try to find a vote by product and user as fallback for legacy data
                $vote = $this->votes->findByProductAndUser($comment->getEntityId(), (int) $userId);
                if ($vote) {
                    $vote->setCommentId($comment->getId());
                } else {
                    $vote = new ProductVote(
                        $comment->getEntityId(),
                        $this->session->getId(),
                        $rating,
                        (int) $userId
                    );
                    $vote->setCommentId($comment->getId());
                }
            }

            if ($vote !== null) {
                if ($rating >= 1 && $rating <= 5) {
                    $vote->setRating($rating);
                }
                $vote->setReview($content);
                $this->votes->save($vote);
            }
        }

        $this->session->flash('success', $this->translator->get('Your comment has been updated.'));
        return $response->withHeader('Location', $request->getHeaderLine('Referer') ?: '/')->withStatus(302);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id      = (int) ($args['id'] ?? 0);
        $comment = $this->comments->findById($id);

        if ($comment === null) {
            return $response->withStatus(404);
        }

        // Authorization: only the comment author (logged-in) can delete
        $userId = $this->session->get('user_id');
        if ($userId === null || (int) $comment->getUserId() !== (int) $userId) {
            $this->session->flash('error', $this->translator->get('You can only delete your own comments.'));
            return $response->withHeader('Location', $request->getHeaderLine('Referer') ?: '/')->withStatus(302);
        }

        // Check if deletion is allowed globally
        $settingKey = $comment->getEntityType() === 'product' ? 'allow_review_editing' : 'allow_comment_editing';
        if ($this->settings->get($settingKey, '1') !== '1') {
            $this->session->flash('error', $this->translator->get('Comment deletion is not allowed.'));
            return $response->withHeader('Location', $request->getHeaderLine('Referer') ?: '/')->withStatus(302);
        }

        // Delete associated vote if product review
        if ($comment->getEntityType() === 'product') {
            $vote = $this->votes->findByCommentId($comment->getId());
            if ($vote !== null) {
                $this->votes->delete($vote->getId());
            }
        }

        if ($this->comments->hasChildren($id)) {
            $comment->setBody($this->translator->get('[Comment deleted by author]'));
            $comment->setAuthorName($this->translator->get('Deleted'));
            $comment->setUserId(null);
            $this->comments->save($comment);
        } else {
            $this->comments->delete($id);
        }

        $this->session->flash('success', $this->translator->get('Your comment has been deleted.'));
        return $response->withHeader('Location', $request->getHeaderLine('Referer') ?: '/#comments')->withStatus(302);
    }

    private function sendAdminNotification(string $entityType, int $entityId, string $authorName, string $authorEmail, string $content, array $body): void
    {
        if ($this->mail === null) {
            return;
        }

        $adminEmail = trim((string) ($this->settings->get('admin_email', '') ?? ''));
        if ($adminEmail === '') {
            $adminEmail = trim((string) ($this->settings->get('mail_from_address', '') ?? ''));
        }
        if ($adminEmail === '') {
            return;
        }

        $entityTitle = '';
        $entityUrl   = '';
        if ($entityType === 'post') {
            $post = $this->posts->findById($entityId);
            if ($post !== null) {
                $entityTitle = $post->getTitle();
                $entityUrl   = absolute_url('/' . ltrim((string) $post->getSlug(), '/'));
            }
        } elseif ($entityType === 'product') {
            $product = $this->products->findById($entityId);
            if ($product !== null) {
                $entityTitle = $product->getName();
                $entityUrl   = absolute_url('/shop/' . $product->getSlug());
            }
        } else {
            $post = $this->posts->findById($entityId);
            if ($post !== null) {
                $entityTitle = $post->getTitle();
                $entityUrl   = absolute_url('/' . ltrim((string) $post->getSlug(), '/'));
            }
        }

        $rating   = (int) ($body['rating'] ?? 0);
        $isReview = $entityType === 'product' && $rating >= 1 && $rating <= 5;
        $template = $isReview ? 'mail/review-notification-admin' : 'mail/comment-notification-admin';

        $entityTypeLabel = match (true) {
            $isReview              => __('Product'),
            $entityType === 'post' => __('Post'),
            default                => $this->contentTypes->findBySlug(new Slug($entityType))?->getName() ?? ucfirst($entityType),
        };

        $context = [
            'author_name'       => $authorName,
            'author_email'      => $authorEmail,
            'comment_content'   => $content,
            'entity_type_label' => $entityTypeLabel,
            'entity_title'      => $entityTitle ?: "#{$entityId}",
            'entity_url'        => $entityUrl ?: absolute_url('/'),
            'admin_url'         => admin_url('/comments'),
        ];

        if ($isReview) {
            $context['rating'] = $rating;
        }

        try {
            $adminLocale  = $this->settings->get('locale', 'en');
            $subjectLabel = $isReview ? 'New Product Review' : 'New Comment';
            $subject      = $this->mail->getTranslator()->get($subjectLabel, [], $adminLocale) . ' — ' . ($entityTitle ?: "#{$entityId}");
            $this->mail->sendTemplate(
                $adminEmail,
                $subject,
                $template,
                $context,
                locale: $adminLocale,
                recipientType: 'admin',
            );
        } catch (\Throwable) {
            // Non-fatal
        }
    }
}
