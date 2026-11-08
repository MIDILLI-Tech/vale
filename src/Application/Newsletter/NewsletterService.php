<?php

declare(strict_types=1);

namespace ValeApp\Application\Newsletter;

use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\PostRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Domain\Media\Repository\MediaRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\Campaign;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shop\Entity\Coupon;
use ValeApp\Domain\Shop\Repository\CouponRepositoryInterface;

final class NewsletterService
{
    public function __construct(
        private readonly MailInterface $mail,
        private readonly CampaignRepositoryInterface $campaigns,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly CouponRepositoryInterface $coupons,
        private readonly PostRepositoryInterface $posts,
        private readonly CategoryRepositoryInterface $categories,
        private readonly TagRepositoryInterface $tags,
        private readonly MediaRepositoryInterface $media,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Check and process recurring campaigns.
     * Returns the number of recurring instances triggered and sent.
     */
    public function processRecurring(): int
    {
        $recurringTemplates = $this->campaigns->findActiveRecurring();
        $triggered          = 0;
        $now                = new \DateTimeImmutable();

        foreach ($recurringTemplates as $template) {
            if (!$this->isRecurringDue($template, $now)) {
                continue;
            }

            // Check for new posts since last run if trigger count > 0
            $minPosts = $template->getRecurringPostCount() ?? 0;
            if ($minPosts > 0) {
                $lastRun       = $template->getLastRecurringRunAt() ?? $template->getCreatedAt() ?? new \DateTimeImmutable('-10 years');
                $newPostsCount = $this->countNewPosts($template, $lastRun);

                if ($newPostsCount < $minPosts) {
                    continue;
                }
            }

            // Trigger! Clone and send.
            $instance = new Campaign($template->getSubject(), $template->getBodyHtml());
            $instance->setSubjectB($template->getSubjectB());
            $instance->setBodyHtmlB($template->getBodyHtmlB());
            $instance->setFromName($template->getFromName());
            $instance->setFromEmail($template->getFromEmail());
            $instance->setAbTestEnabled($template->isAbTestEnabled());
            $instance->setVariationBWeight($template->getVariationBWeight());
            $instance->setTagFilter($template->getTagFilter());
            $instance->setSourceFormFilter($template->getSourceFormFilter());
            $instance->setMinOrdersFilter($template->getMinOrdersFilter());
            $instance->setMaxOrdersFilter($template->getMaxOrdersFilter());
            $instance->setMinOpenRateFilter($template->getMinOpenRateFilter());
            $instance->setMaxOpenRateFilter($template->getMaxOpenRateFilter());
            $instance->setMinClickRateFilter($template->getMinClickRateFilter());
            $instance->setMaxClickRateFilter($template->getMaxClickRateFilter());
            $instance->setTrackingEnabled($template->getTrackingEnabled());
            $instance->setStatus('queued');
            $instance->setScheduledAt($now);

            $this->campaigns->save($instance);
            $this->sendCampaign($instance);

            $template->setLastRecurringRunAt($now);
            $this->campaigns->save($template);
            $triggered++;
        }

        return $triggered;
    }

    private function isRecurringDue(Campaign $c, \DateTimeImmutable $now): bool
    {
        $lastRun       = $c->getLastRecurringRunAt();
        $interval      = $c->getRecurringInterval();
        $scheduledTime = $c->getRecurringTime() ?? '09:00';

        if ($lastRun !== null) {
            $lastRunDay = $lastRun->format('Y-m-d');
            $today      = $now->format('Y-m-d');

            if ($interval === 'daily' && $lastRunDay === $today) {
                return false;
            }
            if ($interval === 'weekly' && $lastRun->format('W-Y') === $now->format('W-Y')) {
                return false;
            }
            if ($interval === 'monthly' && $lastRun->format('m-Y') === $now->format('m-Y')) {
                return false;
            }
        }

        if ($now->format('H:i') < $scheduledTime) {
            return false;
        }

        if ($interval === 'weekly') {
            $dayOfWeek   = (int) $now->format('N');
            $allowedDays = array_filter(array_map('trim', explode(',', $c->getRecurringDay() ?? '1')));
            if (!in_array((string)$dayOfWeek, $allowedDays, true)) {
                return false;
            }
        } elseif ($interval === 'monthly') {
            $dayOfMonth  = (int) $now->format('j');
            $allowedDays = array_filter(array_map('trim', explode(',', $c->getRecurringDay() ?? '1')));
            if (!in_array((string)$dayOfMonth, $allowedDays, true)) {
                return false;
            }
        }

        return true;
    }

    private function countNewPosts(Campaign $c, \DateTimeImmutable $since): int
    {
        $filters     = ['status' => 'published'];
        $categoryIds = $c->getRecurringCategoryIds();
        if ($categoryIds) {
            $ids = array_filter(array_map('intval', explode(',', $categoryIds)), fn ($v) => $v > 0);
            if ($ids) {
                $filters['category_ids'] = array_values($ids);
            }
        }
        $tagIds = $c->getRecurringTagIds();
        if ($tagIds) {
            $ids = array_filter(array_map('intval', explode(',', $tagIds)), fn ($v) => $v > 0);
            if ($ids) {
                $filters['tag_ids'] = array_values($ids);
            }
        }
        $language = $c->getRecurringLanguage();
        if ($language) {
            $filters['language'] = $language;
        }

        $typeStr = $c->getRecurringPostTypes() ?: 'post';
        $types   = array_filter(array_map('trim', explode(',', $typeStr)));

        $totalNew = 0;
        foreach ($types as $type) {
            $filters['content_type'] = $type;
            $pagination              = new Pagination(1, 100);
            $posts                   = $this->posts->findAll($pagination, $filters);
            foreach ($posts as $post) {
                $publishedAt = $post->getPublishedAt();
                if ($publishedAt && $publishedAt > $since) {
                    $totalNew++;
                }
            }
        }
        return $totalNew;
    }

    /**
     * Queue or process a campaign in chunks.
     * Returns the number of emails dispatched in this run.
     */
    public function sendCampaign(Campaign $campaign, int $chunkLimit = 0): int
    {
        $validStatuses = ['draft', 'scheduled', 'queued', 'processing'];
        if (!in_array($campaign->getStatus(), $validStatuses, true)) {
            return 0;
        }

        $tag        = $campaign->getTagFilter();
        $formFilter = $campaign->getSourceFormFilter();
        $minOrders  = $campaign->getMinOrdersFilter();
        $maxOrders  = $campaign->getMaxOrdersFilter();
        $minOpen    = $campaign->getMinOpenRateFilter();
        $maxOpen    = $campaign->getMaxOpenRateFilter();
        $minClick   = $campaign->getMinClickRateFilter();
        $maxClick   = $campaign->getMaxClickRateFilter();

        $activeList = $this->subscribers->findActive($tag, $formFilter, $minOrders, $maxOrders, $minOpen, $maxOpen, $minClick, $maxClick);

        if (empty($activeList)) {
            $campaign->markSent();
            $this->campaigns->save($campaign);
            return 0;
        }

        $totalSubscribers = count($activeList);
        $campaign->setTotalCount($totalSubscribers);

        if ($campaign->isDraft() || $campaign->isScheduled()) {
            $campaign->setStatus('queued');
            $this->campaigns->save($campaign);
        }

        // Determine batch limit from driver or parameter
        $driverMax      = $this->mail->getMaxBatchSize();
        $maxDriverBatch = $driverMax  > 0 ? $driverMax : 100;
        $batchSize      = $chunkLimit > 0 ? min($chunkLimit, $maxDriverBatch) : $maxDriverBatch;

        // Skip subscribers already processed
        $lastSubId            = $campaign->getLastSubscriberId();
        $remainingSubscribers = [];
        foreach ($activeList as $sub) {
            if ($sub->getId() > $lastSubId) {
                $remainingSubscribers[] = $sub;
            }
        }

        if (empty($remainingSubscribers)) {
            $campaign->markSent();
            $this->campaigns->save($campaign);
            return 0;
        }

        $campaign->setStatus('processing');
        $this->campaigns->save($campaign);

        // Process chunk
        $currentChunk       = array_slice($remainingSubscribers, 0, $batchSize);
        $messages           = [];
        $lastProcessedSubId = $lastSubId;

        foreach ($currentChunk as $subscriber) {
            $tracking = $campaign->getTrackingEnabled();
            $unsubUrl = $this->appUrl . '/newsletter/unsubscribe/' . $subscriber->getToken();

            $variation = 'A';
            if ($campaign->isAbTestEnabled()) {
                $weightB   = $campaign->getVariationBWeight();
                $variation = (random_int(1, 100) <= $weightB) ? 'B' : 'A';
            }

            if ($tracking !== 0) {
                $token    = $this->campaigns->addRecipient($campaign->getId(), $subscriber->getId(), $variation);
                $openUrl  = $this->appUrl . '/t/open/' . $token . '.gif';
                $clickUrl = $this->appUrl . '/t/click/' . $token;
            } else {
                $token    = '';
                $openUrl  = '';
                $clickUrl = '';
            }

            $openPixelHtml = $campaign->hasOpenTracking() ? "<img src=\"{$openUrl}\" width=\"1\" height=\"1\" alt=\"\">" : '';

            $campaignBody    = ($variation === 'B' && $campaign->getBodyHtmlB()) ? $campaign->getBodyHtmlB() : $campaign->getBodyHtml();
            $campaignSubject = ($variation === 'B' && $campaign->getSubjectB()) ? $campaign->getSubjectB() : $campaign->getSubject();

            $body = $this->processShortcodes(
                $campaignBody,
                $subscriber,
                $unsubUrl,
                $openPixelHtml,
                $campaign->getRecurringLanguage()
            );

            if ($campaign->hasClickTracking()) {
                $body = $this->wrapLinksForTracking($body, $clickUrl, $unsubUrl);
            }

            $fromName  = $campaign->getFromName()  ?? 'Newsletter';
            $fromEmail = $campaign->getFromEmail() ?? 'newsletter@' . parse_url($this->appUrl, PHP_URL_HOST);

            $messages[] = new MailMessage(
                to: $subscriber->getEmail(),
                subject: $campaignSubject,
                htmlBody: $body,
                fromAddress: $fromEmail,
                fromName: $fromName,
                listUnsubscribeUrl: $unsubUrl,
            );

            $lastProcessedSubId = max($lastProcessedSubId, $subscriber->getId());
        }

        // Dispatch batch
        if (!empty($messages)) {
            $this->mail->sendBatch($messages);
        }

        $sentInChunk    = count($messages);
        $newProcessed   = $campaign->getProcessedCount() + $sentInChunk;
        $campaign->setProcessedCount($newProcessed);
        $campaign->setLastSubscriberId($lastProcessedSubId);

        if ($newProcessed >= $totalSubscribers || count($remainingSubscribers) <= count($currentChunk)) {
            $campaign->markSent();
        }

        $this->campaigns->save($campaign);

        return $sentInChunk;
    }

    /**
     * Replace all supported shortcodes in an email body for a specific subscriber.
     */
    private function processShortcodes(
        string $body,
        Subscriber $subscriber,
        string $unsubUrl,
        string $openPixelHtml,
        ?string $contextLanguage = null,
    ): string {
        // Static replacements
        $emailEscaped    = htmlspecialchars($subscriber->getEmail(), ENT_QUOTES, 'UTF-8');
        $unsubUrlEscaped = htmlspecialchars($unsubUrl, ENT_QUOTES, 'UTF-8');
        $unsubHeader     = '<script type="application/ld+json">{"@context":"http://schema.org","@type":"EmailMessage","potentialAction":{"@type":"UnsubscribeAction","name":"Unsubscribe","target":"' . $unsubUrlEscaped . '"}}</script>';

        if (!str_contains($body, 'UnsubscribeAction')) {
            $body .= "\n" . $unsubHeader;
        }

        $body = str_replace(
            ['[unsubscribe_url]', '[open_pixel]', '[email]'],
            [$unsubUrl, $openPixelHtml, $emailEscaped],
            $body
        );

        // [subscriber_name] (with optional |Fallback text)
        $body = preg_replace_callback(
            '/\[subscriber_name(?:\|([^\]]*))?\]/',
            static function (array $m) use ($subscriber): string {
                $name = $subscriber->getName();
                if ($name !== null && $name !== '') {
                    return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                }
                $fallback = !empty($m[1]) ? trim($m[1]) : '';
                return htmlspecialchars($fallback !== '' ? $fallback : 'Valued Customer', ENT_QUOTES, 'UTF-8');
            },
            $body
        ) ?? $body;

        // [generate_coupon attr="value" ...]
        $body = preg_replace_callback(
            '/\[generate_coupon([^\]]*)\]/',
            function (array $m) use ($subscriber): string {
                $attrStr = $m[1];
                $attrs   = [];
                preg_match_all('/([\w-]+)="([^"]*)"/', $attrStr, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) {
                    $attrs[$p[1]] = $p[2];
                }

                $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $attrs['prefix'] ?? 'CAMP'));
                if ($prefix === '') {
                    $prefix = 'CAMP';
                }

                $code     = $prefix . strtoupper(substr(md5($subscriber->getId() . microtime(true) . random_int(0, 99999)), 0, 8));
                $attempts = 0;
                while ($this->coupons->findByCode($code) !== null && ++$attempts < 10) {
                    $code = $prefix . strtoupper(substr(md5(uniqid('', true)), 0, 8));
                }

                $type   = isset($attrs['discount_percent']) ? 'percent' : 'fixed_cart';
                $amount = (float)($attrs['discount_percent'] ?? $attrs['discount_fixed'] ?? 0);

                $coupon = new Coupon($code, $type, $amount);
                $coupon->setEnabled(true);
                if (!empty($attrs['max_uses'])) {
                    $coupon->setMaxUses((int)$attrs['max_uses']);
                }
                if (!empty($attrs['max_uses_per_email'])) {
                    $coupon->setMaxUsesPerEmail((int)$attrs['max_uses_per_email']);
                } elseif (!empty($attrs['max_uses_per_user'])) {
                    $coupon->setMaxUsesPerEmail((int)$attrs['max_uses_per_user']);
                }
                if (!empty($attrs['expires_days'])) {
                    $coupon->setExpiresAt(new \DateTimeImmutable('+' . (int)$attrs['expires_days'] . ' days'));
                }
                if (!empty($attrs['starts_at'])) {
                    try {
                        $coupon->setStartsAt(new \DateTimeImmutable($attrs['starts_at']));
                    } catch (\Exception) {
                        // invalid date — leave null (starts immediately)
                    }
                }
                if (!empty($attrs['for-email'])) {
                    if (empty($attrs['max_uses'])) {
                        $coupon->setMaxUses(1);
                    }
                    $coupon->setAllowedEmails([$attrs['for-email']]);
                }
                if (!empty($attrs['only_users'])) {
                    $coupon->setOnlyUsers(true);
                }

                $this->coupons->save($coupon);
                return $code;
            },
            $body
        ) ?? $body;

        // [latest_posts count="5" category="slug" tag="slug" type="post"]
        $body = preg_replace_callback(
            '/\[latest_posts([^\]]*)\]/',
            function (array $m) use ($contextLanguage): string {
                $attrStr = $m[1];
                $attrs   = [];
                preg_match_all('/([\w-]+)="([^"]*)"/', $attrStr, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) {
                    $attrs[$p[1]] = $p[2];
                }

                $count    = (int) ($attrs['count'] ?? 5);
                $catSlug  = $attrs['category'] ?? null;
                $tagSlug  = $attrs['tag']      ?? null;
                $type     = $attrs['type']     ?? 'post';
                $language = $attrs['lang']     ?? $contextLanguage;

                $pagination = new Pagination(1, $count);
                $filters    = ['content_type' => $type, 'status' => 'published'];
                if ($language) {
                    $filters['language'] = $language;
                }

                if ($catSlug) {
                    $cat = $this->categories->findBySlug($catSlug, $type, $language);
                    if ($cat) {
                        $filters['category_id'] = $cat->getId();
                    }
                }
                if ($tagSlug) {
                    $slugs  = array_filter(array_map('trim', explode(',', $tagSlug)));
                    $tagIds = [];
                    foreach ($slugs as $s) {
                        $t = $this->tags->findBySlug($s, $type, $language);
                        if ($t) {
                            $tagIds[] = $t->getId();
                        }
                    }
                    if ($tagIds) {
                        $filters['tag_ids'] = $tagIds;
                    }
                }

                $posts = $this->posts->findAll($pagination, $filters);

                if (empty($posts)) {
                    return '';
                }

                $html = '<style>
@media (prefers-color-scheme: dark) {
  .latest-posts { color: #e0e0e0 !important; }
  .latest-posts h3 a { color: #ffffff !important; }
  .latest-posts p { color: #b0b0b0 !important; }
  .latest-posts .post-item { border-bottom-color: #333 !important; }
}
</style>';
                $html .= '<div class="latest-posts" style="font-family: sans-serif; color: #333;">';
                foreach ($posts as $post) {
                    $imgUrl = '';
                    if ($post->getFeaturedImageId()) {
                        $media = $this->media->findById($post->getFeaturedImageId());
                        if ($media) {
                            $imgUrl = $this->appUrl . '/storage/media/' . $media->getFilename();
                        }
                    }

                    $postUrl = site_url(($type === 'post' ? '/blog/' : '/') . $post->getSlug(), [], $language ? ['lang' => $language] : []);

                    $html .= '<div class="post-item" style="display: flex; gap: 20px; margin-bottom: 25px; border-bottom: 1px solid #eee; padding-bottom: 20px;">';
                    if ($imgUrl) {
                        $html .= sprintf(
                            '<div class="post-image" style="flex: 0 0 150px;"><a href="%s"><img src="%s" alt="%s" style="width: 100%%; height: 100px; object-fit: cover; border-radius: 8px;"></a></div>',
                            $postUrl,
                            $imgUrl,
                            htmlspecialchars($post->getTitle(), ENT_QUOTES, 'UTF-8')
                        );
                    }
                    $html .= '<div class="post-content" style="flex: 1;">';
                    $html .= sprintf(
                        '<h3 style="margin: 0 0 10px 0; font-size: 18px;"><a href="%s" style="color: #1a1a1a; text-decoration: none;">%s</a></h3>',
                        $postUrl,
                        htmlspecialchars($post->getTitle(), ENT_QUOTES, 'UTF-8')
                    );
                    if ($post->getExcerpt()) {
                        $html .= sprintf(
                            '<p style="margin: 0; font-size: 14px; color: #666; line-height: 1.5;">%s</p>',
                            htmlspecialchars($post->getExcerpt(), ENT_QUOTES, 'UTF-8')
                        );
                    }
                    $html .= '</div></div>';
                }
                $html .= '</div>';

                return $html;
            },
            $body
        ) ?? $body;

        return $body;
    }

    private function wrapLinksForTracking(string $html, string $trackClickBase, string $unsubUrl): string
    {
        return preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            static function (array $m) use ($trackClickBase, $unsubUrl): string {
                if (str_starts_with($m[1], $unsubUrl)) {
                    return $m[0];
                }
                $encoded = urlencode($m[1]);
                return 'href="' . $trackClickBase . '?url=' . $encoded . '"';
            },
            $html
        ) ?? $html;
    }
}
