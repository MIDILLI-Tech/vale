<?php

declare(strict_types=1);

namespace ValeApp\Application\Newsletter;

use Psr\Log\LoggerInterface;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Domain\Newsletter\Entity\AutomationEnrollment;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shop\Repository\OrderRepositoryInterface;

final class AutomationService
{
    public function __construct(
        private readonly AutomationRepositoryInterface $automations,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly MailInterface $mail,
        private readonly OrderRepositoryInterface $orders,
        private readonly string $appUrl,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Number of automation emails currently due for sending.
     */
    public function countDueEnrollments(): int
    {
        return $this->automations->countDueEnrollments();
    }

    public function recordOpen(string $token): void
    {
        $this->automations->recordOpen($token);
    }

    public function recordClick(string $token): void
    {
        $this->automations->recordClick($token);
    }

    /**
     * Enroll a newly confirmed subscriber into all matching active sequences.
     */
    public function enrollSubscriber(Subscriber $subscriber): void
    {
        $sequences = $this->automations->findAllSequences();

        foreach ($sequences as $sequence) {
            if (!$sequence->isActive()) {
                continue;
            }

            // Only auto-enroll in sequences triggered by subscribe event
            if ($sequence->getTriggerType() !== 'subscribe') {
                continue;
            }

            // Check trigger_form_id filter
            if (
                $sequence->getTriggerFormId()    !== null
                && $sequence->getTriggerFormId() !== $subscriber->getSourceFormId()
            ) {
                continue;
            }

            // Check trigger_tag filter (multi-tag with any/all match)
            $triggerTags = $sequence->getTriggerTags();
            if (!empty($triggerTags)) {
                $subscriberTags = $subscriber->getTags();
                if ($sequence->getTriggerTagMatch() === 'all') {
                    // Subscriber must have ALL of the sequence tags
                    if (!empty(array_diff($triggerTags, $subscriberTags))) {
                        continue;
                    }
                } else {
                    // Subscriber must have ANY of the sequence tags (default)
                    if (empty(array_intersect($triggerTags, $subscriberTags))) {
                        continue;
                    }
                }
            }

            // Find first step to determine initial send time
            $steps = $this->automations->findStepsBySequenceId($sequence->getId());
            if (empty($steps)) {
                continue;
            }

            $firstStep  = $steps[0];
            $nextSendAt = (new \DateTimeImmutable())->modify('+' . $firstStep->getDelayMinutes() . ' minutes');

            $this->automations->enroll($sequence->getId(), $subscriber->getId(), $nextSendAt);

            $this->logger?->info('AutomationService: enrolled subscriber', [
                'subscriber_id' => $subscriber->getId(),
                'sequence_id'   => $sequence->getId(),
                'next_send_at'  => $nextSendAt->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Enroll a subscriber into sequences triggered by a specific tag assignment.
     */
    public function enrollSubscriberByTag(Subscriber $subscriber, string $tag): void
    {
        $sequences = $this->automations->findAllSequences();

        foreach ($sequences as $sequence) {
            if (!$sequence->isActive()) {
                continue;
            }

            if ($sequence->getTriggerType() !== 'tag_assigned') {
                continue;
            }

            // Check trigger_tag matches the assigned tag
            $triggerTag = $sequence->getTriggerTag();
            if ($triggerTag === null || $triggerTag === '') {
                continue;
            }

            // Compare the trigger tag against the tag being assigned
            $triggerTags = array_map('trim', explode(',', $triggerTag));
            if (!in_array($tag, $triggerTags, true)) {
                continue;
            }

            // Find first step to determine initial send time
            $steps = $this->automations->findStepsBySequenceId($sequence->getId());
            if (empty($steps)) {
                continue;
            }

            $firstStep  = $steps[0];
            $nextSendAt = (new \DateTimeImmutable())->modify('+' . $firstStep->getDelayMinutes() . ' minutes');

            $this->automations->enroll($sequence->getId(), $subscriber->getId(), $nextSendAt);

            $this->logger?->info('AutomationService: enrolled subscriber by tag', [
                'subscriber_id' => $subscriber->getId(),
                'sequence_id'   => $sequence->getId(),
                'tag'           => $tag,
                'next_send_at'  => $nextSendAt->format('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Process up to $limit due enrollments. Returns the number actually processed.
     */
    public function processDue(int $limit = 10): int
    {
        $enrollments = $this->automations->findDueEnrollments($limit);
        $processed   = 0;

        foreach ($enrollments as $enrollment) {
            $subscriber = $this->subscribers->findById($enrollment->getSubscriberId());
            if ($subscriber === null || !$subscriber->isActive()) {
                // Subscriber gone or unsubscribed — cancel
                $enrollment->cancel();
                $this->automations->updateEnrollment($enrollment);
                continue;
            }

            $steps = $this->automations->findStepsBySequenceId($enrollment->getSequenceId());
            if (empty($steps)) {
                $enrollment->complete();
                $this->automations->updateEnrollment($enrollment);
                continue;
            }

            $currentStepIndex = $enrollment->getNextStep();
            if (!isset($steps[$currentStepIndex])) {
                // No more steps
                $enrollment->complete();
                $this->automations->updateEnrollment($enrollment);
                continue;
            }

            $step = $steps[$currentStepIndex];

            $sequence      = $this->automations->findSequenceById($enrollment->getSequenceId());
            $openTracking  = $sequence?->hasOpenTracking()  ?? true;
            $clickTracking = $sequence?->hasClickTracking() ?? true;

            // Apply segmentation filters
            $hasOrderFilter = $step->getMinOrdersFilter()               !== null
                || $step->getMaxOrdersFilter()                          !== null;
            $hasSpendFilter = $step->getMinTotalSpendFilter()           !== null
                || $step->getMaxTotalSpendFilter()                      !== null;
            $hasDaysFilter = $step->getMinDaysSinceLastPurchaseFilter() !== null
                || $step->getMaxDaysSinceLastPurchaseFilter()           !== null;
            $hasRateFilter = $step->getMinOpenRateFilter()              !== null
                || $step->getMaxOpenRateFilter()                        !== null
                || $step->getMinClickRateFilter()                       !== null
                || $step->getMaxClickRateFilter()                       !== null;

            if ($hasOrderFilter || $hasSpendFilter || $hasDaysFilter || $hasRateFilter) {
                $userId = $subscriber->getUserId();

                // Order count
                if ($hasOrderFilter) {
                    $orderCount = ($userId !== null) ? $this->orders->countByUserId($userId) : 0;
                    if ($step->getMinOrdersFilter() !== null && $orderCount < $step->getMinOrdersFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }
                    if ($step->getMaxOrdersFilter() !== null && $orderCount > $step->getMaxOrdersFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }
                }

                // Total spend
                if ($hasSpendFilter && $userId !== null) {
                    $totalSpend = $this->orders->getTotalSpendByUserId($userId);
                    if ($step->getMinTotalSpendFilter() !== null && $totalSpend < $step->getMinTotalSpendFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }
                    if ($step->getMaxTotalSpendFilter() !== null && $totalSpend > $step->getMaxTotalSpendFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }
                }

                // Days since last purchase
                if ($hasDaysFilter && $userId !== null) {
                    $lastOrderDate         = $this->orders->getLastOrderDateByUserId($userId);
                    $daysSinceLastPurchase = $lastOrderDate !== null
                        ? (int) $lastOrderDate->diff(new \DateTimeImmutable())->days
                        : 999999;
                    if ($step->getMinDaysSinceLastPurchaseFilter() !== null && $daysSinceLastPurchase < $step->getMinDaysSinceLastPurchaseFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }
                    if ($step->getMaxDaysSinceLastPurchaseFilter() !== null && $daysSinceLastPurchase > $step->getMaxDaysSinceLastPurchaseFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }
                }

                // Rates filtering
                if ($hasRateFilter) {
                    $stats = $this->automations->getSubscriberEngagementStats($subscriber->getId());

                    if ($step->getMinOpenRateFilter() !== null && $stats['open_rate'] < $step->getMinOpenRateFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }
                    if ($step->getMaxOpenRateFilter() !== null && $stats['open_rate'] > $step->getMaxOpenRateFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }

                    if ($step->getMinClickRateFilter() !== null && $stats['click_rate'] < $step->getMinClickRateFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }
                    if ($step->getMaxClickRateFilter() !== null && $stats['click_rate'] > $step->getMaxClickRateFilter()) {
                        $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);
                        $this->automations->updateEnrollment($enrollment);
                        continue;
                    }
                }
            }

            // Decide variation
            $variation = 'A';
            if ($step->isAbTestEnabled()) {
                $weightB   = $step->getVariationBWeight();
                $variation = (random_int(1, 100) <= $weightB) ? 'B' : 'A';
            }
            $enrollment->setLastVariation($variation);

            $token = bin2hex(random_bytes(32));
            $enrollment->setTrackingToken($token);

            // Build personalised body
            $unsubUrl = $this->appUrl . '/newsletter/unsubscribe/' . $subscriber->getToken();
            $openUrl  = $this->appUrl . '/t/open-auto/' . $token . '.gif';
            $clickUrl = $this->appUrl . '/t/click-auto/' . $token;

            $bodyHtml    = ($variation === 'B' && $step->getBodyHtmlB()) ? $step->getBodyHtmlB() : $step->getBodyHtml();
            $subjectText = ($variation === 'B' && $step->getSubjectB()) ? $step->getSubjectB() : $step->getSubject();

            $openPixelHtml = $openTracking ? "<img src=\"{$openUrl}\" width=\"1\" height=\"1\" alt=\"\">" : '';
            $body          = $this->personalise($bodyHtml, $subscriber, $unsubUrl, $openPixelHtml);
            if ($clickTracking) {
                $body = $this->wrapLinksForTracking($body, $clickUrl, $unsubUrl);
            }
            $subject       = $this->personaliseSubject($subjectText, $subscriber);

            try {
                $this->mail->send(new MailMessage(
                    to:      $subscriber->getEmail(),
                    subject: $subject,
                    htmlBody: $body,
                    listUnsubscribeUrl: $unsubUrl,
                ));

                $this->logger?->info('AutomationService: sent step email', [
                    'enrollment_id' => $enrollment->getId(),
                    'subscriber'    => $subscriber->getEmail(),
                    'step_index'    => $currentStepIndex,
                    'subject'       => $subject,
                ]);
            } catch (\Throwable $e) {
                $this->logger?->error('AutomationService: failed to send', [
                    'enrollment_id' => $enrollment->getId(),
                    'error'         => $e->getMessage(),
                ]);
                // Don't advance — retry next run
                continue;
            }

            // Increment counters
            $field = ($variation === 'B') ? 'opens_count_b' : 'opens_count'; // Note: Automation doesn't typically have tracking tokens like campaigns yet, but we'll increment send count conceptually or actual open if we add tracking
            // For now, let's just use the fields in the DB for "sent" count if we want, or wait for tracking implementation.
            // Actually, the schema I made has opens_count and clicks_count.
            // In processDue, we are SENDING. We should increment "sends" if we had that, but let's assume tracking will happen via recordOpen/Click later.
            // Wait, the recordOpen/Click in CampaignRepository uses tracking tokens.
            // Automation steps in this project don't seem to have tracking tokens in recipients table (because they use enrollments table).

            // Advance or complete
            $this->advanceOrComplete($enrollment, $steps, $currentStepIndex);

            $this->automations->updateEnrollment($enrollment);
            $processed++;
        }

        return $processed;
    }

    // ── Private helpers ───────────────────────────────────────────

    private function advanceOrComplete(AutomationEnrollment $enrollment, array $steps, int $currentStepIndex): void
    {
        $nextStepIndex = $currentStepIndex + 1;
        if (isset($steps[$nextStepIndex])) {
            $enrollment->advance($steps[$nextStepIndex]);
        } else {
            $enrollment->complete();
        }
    }

    private function personalise(string $body, Subscriber $subscriber, string $unsubUrl, string $openPixelHtml = ''): string
    {
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

        return $body;
    }

    private function personaliseSubject(string $subject, Subscriber $subscriber): string
    {
        $name = $subscriber->getName() ?? 'Valued Customer';
        return str_replace(
            ['[email]', '[subscriber_name]'],
            [$subscriber->getEmail(), $name],
            $subject
        );
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
