<?php

declare(strict_types=1);

namespace ValeApp\Application\Cron;

use ValeApp\Application\Mail\MailService;
use ValeApp\Domain\Content\Entity\Post;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shop\Entity\Product;

/**
 * Emails the admin a single digest of the changes a cron run made on its own:
 * scheduled publishing, unlisted records going public and expiries.
 *
 * Cron is the only caller on purpose. Manual, API and CLI publishes fire
 * `post.published` too, so hooking that event would also mail for actions an
 * admin just performed in the panel.
 */
final class ScheduledTaskNotificationService
{
    public function __construct(
        private readonly MailService $mail,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * @param array{published?: list<Post>, went_public?: list<Post>, expired?: list<array{post: Post, replaced_by: Post|null}>} $content
     * @param array{published?: list<Product>, went_public?: list<Product>, expired?: list<Product>}                        $products
     */
    public function notifyRun(array $content, array $products = []): void
    {
        $published = array_merge(
            $this->mapPosts($content['published'] ?? []),
            $this->mapProducts($products['published'] ?? []),
        );

        $wentPublic = array_merge(
            $this->mapPosts($content['went_public'] ?? []),
            $this->mapProducts($products['went_public'] ?? []),
        );

        $expired = array_merge(
            $this->mapExpired($content['expired'] ?? []),
            $this->mapProducts($products['expired'] ?? []),
        );

        if ($published === [] && $wentPublic === [] && $expired === []) {
            return;
        }

        $adminEmail = trim((string) ($this->settings->get('admin_email', '') ?? ''));
        if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $siteName = (string) ($this->settings->get('site_name', 'Vale') ?? 'Vale');
        $subject  = '[' . $siteName . '] ' . $this->mail->getTranslator()->get('Scheduled task report');

        $this->mail->sendTemplate(
            $adminEmail,
            $subject,
            'mail/scheduled-task-report',
            [
                'published'    => $published,
                'went_public'  => $wentPublic,
                'expired'      => $expired,
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'content_url'  => $this->adminLink('/posts'),
            ],
            recipientType: 'admin',
        );
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<array{title: string, type: string, url: string}>
     */
    private function mapPosts(array $posts): array
    {
        $items = [];

        foreach ($posts as $post) {
            $items[] = [
                'title' => (string) $post->getTitle(),
                'type'  => $this->typeLabel($post->getContentType()),
                'url'   => $this->contentLink($post),
            ];
        }

        return $items;
    }

    /**
     * @param list<Product> $products
     *
     * @return list<array{title: string, type: string, url: string, replaced_by: string}>
     */
    private function mapProducts(array $products): array
    {
        $items = [];

        foreach ($products as $product) {
            $items[] = [
                'title'       => (string) $product->getName(),
                'type'        => 'Product',
                'url'         => $this->adminLink('/products/' . (int) $product->getId() . '/edit'),
                'replaced_by' => '',
            ];
        }

        return $items;
    }

    /**
     * @param list<array{post: Post, replaced_by: Post|null}> $expired
     *
     * @return list<array{title: string, type: string, url: string, replaced_by: string}>
     */
    private function mapExpired(array $expired): array
    {
        $items = [];

        foreach ($expired as $entry) {
            $items[] = [
                'title'       => (string) $entry['post']->getTitle(),
                'type'        => $this->typeLabel($entry['post']->getContentType()),
                'url'         => $this->contentLink($entry['post']),
                'replaced_by' => (string) ($entry['replaced_by']?->getTitle() ?? ''),
            ];
        }

        return $items;
    }

    /**
     * Link to the admin edit screen: it is correct for every content type,
     * while the public URL depends on routing prefixes and content language.
     */
    private function contentLink(Post $post): string
    {
        $section = $post->getContentType() === 'page' ? 'pages' : 'posts';

        return $this->adminLink('/' . $section . '/' . (int) $post->getId() . '/edit');
    }

    private function typeLabel(string $contentType): string
    {
        return match ($contentType) {
            'post'  => 'Post',
            'page'  => 'Page',
            default => ucfirst(str_replace(['-', '_'], ' ', $contentType)),
        };
    }

    private function adminLink(string $path): string
    {
        $adminPath = admin_url($path);
        $base      = rtrim((string) env('APP_URL', ''), '/');

        if ($base === '') {
            return $adminPath;
        }

        if (!preg_match('~^https?://~i', $base)) {
            $base = 'https://' . $base;
        }

        return $base . $adminPath;
    }
}
