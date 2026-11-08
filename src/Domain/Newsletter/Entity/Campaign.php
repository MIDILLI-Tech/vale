<?php

declare(strict_types=1);

namespace ValeApp\Domain\Newsletter\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class Campaign extends BaseEntity
{
    public const int TRACKING_NONE  = 0;
    public const int TRACKING_OPEN  = 1;
    public const int TRACKING_CLICK = 2;
    public const int TRACKING_ALL   = 3;

    private string $subject;
    private ?string $subjectB = null;
    private string $bodyHtml;
    private ?string $bodyHtmlB                      = null;
    private ?string $fromName                       = null;
    private ?string $fromEmail                      = null;
    private string $status                          = 'draft'; // draft|queued|processing|sent|scheduled
    private bool $abTestEnabled                     = false;
    private int $variationBWeight                   = 50;
    private ?string $tagFilter                      = null;
    private ?int $sourceFormFilter                  = null;
    private ?int $minOrdersFilter                   = null;
    private ?int $maxOrdersFilter                   = null;
    private ?int $minOpenRateFilter                 = null;
    private ?int $maxOpenRateFilter                 = null;
    private ?int $minClickRateFilter                = null;
    private ?int $maxClickRateFilter                = null;
    private ?\DateTimeImmutable $scheduledAt        = null;
    private ?\DateTimeImmutable $sentAt             = null;
    private int $opensCount                         = 0;
    private int $opensCountB                        = 0;
    private int $clicksCount                        = 0;
    private int $clicksCountB                       = 0;
    private int $recipientsCount                    = 0;
    private int $recipientsCountA                   = 0;
    private int $recipientsCountB                   = 0;
    private int $processedCount                     = 0;
    private int $totalCount                         = 0;
    private int $lastSubscriberId                   = 0;
    private int $trackingEnabled                    = self::TRACKING_ALL;
    private bool $isRecurring                       = false;
    private ?string $recurringInterval              = null;
    private ?string $recurringDay                   = null;
    private ?string $recurringTime                  = null;
    private ?int $recurringPostCount                = null;
    private ?string $recurringPostTypes             = null;
    private ?int $recurringCategoryId               = null;
    private ?int $recurringTagId                    = null;
    private ?string $recurringCategoryIds           = null;
    private ?string $recurringTagIds                = null;
    private ?string $recurringLanguage              = null;
    private ?\DateTimeImmutable $lastRecurringRunAt = null;

    public function __construct(string $subject, string $bodyHtml)
    {
        $this->subject  = $subject;
        $this->bodyHtml = $bodyHtml;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }
    public function getSubjectB(): ?string
    {
        return $this->subjectB;
    }
    public function getBodyHtml(): string
    {
        return $this->bodyHtml;
    }
    public function getBodyHtmlB(): ?string
    {
        return $this->bodyHtmlB;
    }
    public function getFromName(): ?string
    {
        return $this->fromName;
    }
    public function getFromEmail(): ?string
    {
        return $this->fromEmail;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function isAbTestEnabled(): bool
    {
        return $this->abTestEnabled;
    }
    public function getVariationBWeight(): int
    {
        return $this->variationBWeight;
    }
    public function getTagFilter(): ?string
    {
        return $this->tagFilter;
    }
    public function getSourceFormFilter(): ?int
    {
        return $this->sourceFormFilter;
    }
    public function getMinOrdersFilter(): ?int
    {
        return $this->minOrdersFilter;
    }
    public function getMaxOrdersFilter(): ?int
    {
        return $this->maxOrdersFilter;
    }
    public function getMinOpenRateFilter(): ?int
    {
        return $this->minOpenRateFilter;
    }
    public function getMaxOpenRateFilter(): ?int
    {
        return $this->maxOpenRateFilter;
    }
    public function getMinClickRateFilter(): ?int
    {
        return $this->minClickRateFilter;
    }
    public function getMaxClickRateFilter(): ?int
    {
        return $this->maxClickRateFilter;
    }
    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }
    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }
    public function getOpensCount(): int
    {
        return $this->opensCount;
    }
    public function getOpensCountB(): int
    {
        return $this->opensCountB;
    }
    public function getClicksCount(): int
    {
        return $this->clicksCount;
    }
    public function getClicksCountB(): int
    {
        return $this->clicksCountB;
    }
    public function getRecipientsCount(): int
    {
        return $this->recipientsCount;
    }
    public function getRecipientsCountA(): int
    {
        return $this->recipientsCountA;
    }
    public function getRecipientsCountB(): int
    {
        return $this->recipientsCountB;
    }
    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }
    public function getTotalCount(): int
    {
        return $this->totalCount;
    }
    public function getLastSubscriberId(): int
    {
        return $this->lastSubscriberId;
    }
    public function getTrackingEnabled(): int
    {
        return $this->trackingEnabled;
    }
    public function hasOpenTracking(): bool
    {
        return (bool)($this->trackingEnabled & self::TRACKING_OPEN);
    }
    public function hasClickTracking(): bool
    {
        return (bool)($this->trackingEnabled & self::TRACKING_CLICK);
    }
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }
    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }
    public function isSent(): bool
    {
        return $this->status === 'sent';
    }
    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    public function setSubject(string $subject): void
    {
        $this->subject = $subject;
    }
    public function setSubjectB(?string $subject): void
    {
        $this->subjectB = $subject;
    }
    public function setBodyHtml(string $html): void
    {
        $this->bodyHtml = $html;
    }
    public function setBodyHtmlB(?string $html): void
    {
        $this->bodyHtmlB = $html;
    }
    public function setFromName(?string $name): void
    {
        $this->fromName = $name;
    }
    public function setFromEmail(?string $email): void
    {
        $this->fromEmail = $email;
    }
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
    public function setAbTestEnabled(bool $v): void
    {
        $this->abTestEnabled = $v;
    }
    public function setVariationBWeight(int $weight): void
    {
        $this->variationBWeight = $weight;
    }
    public function setTagFilter(?string $tag): void
    {
        $this->tagFilter = $tag;
    }
    public function setSourceFormFilter(?int $formId): void
    {
        $this->sourceFormFilter = $formId;
    }
    public function setMinOrdersFilter(?int $min): void
    {
        $this->minOrdersFilter = $min;
    }
    public function setMaxOrdersFilter(?int $max): void
    {
        $this->maxOrdersFilter = $max;
    }
    public function setMinOpenRateFilter(?int $min): void
    {
        $this->minOpenRateFilter = $min;
    }
    public function setMaxOpenRateFilter(?int $max): void
    {
        $this->maxOpenRateFilter = $max;
    }
    public function setMinClickRateFilter(?int $min): void
    {
        $this->minClickRateFilter = $min;
    }
    public function setMaxClickRateFilter(?int $max): void
    {
        $this->maxClickRateFilter = $max;
    }
    public function setScheduledAt(?\DateTimeImmutable $at): void
    {
        $this->scheduledAt = $at;
    }
    public function setSentAt(?\DateTimeImmutable $at): void
    {
        $this->sentAt = $at;
    }
    public function setOpensCount(int $count): void
    {
        $this->opensCount = $count;
    }
    public function setOpensCountB(int $count): void
    {
        $this->opensCountB = $count;
    }
    public function setClicksCount(int $count): void
    {
        $this->clicksCount = $count;
    }
    public function setClicksCountB(int $count): void
    {
        $this->clicksCountB = $count;
    }
    public function setProcessedCount(int $count): void
    {
        $this->processedCount = $count;
    }
    public function setTotalCount(int $count): void
    {
        $this->totalCount = $count;
    }
    public function setLastSubscriberId(int $id): void
    {
        $this->lastSubscriberId = $id;
    }
    public function setTrackingEnabled(int $v): void
    {
        $this->trackingEnabled = $v;
    }

    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    public function setRecurring(bool $v): void
    {
        $this->isRecurring = $v;
    }

    public function getRecurringInterval(): ?string
    {
        return $this->recurringInterval;
    }

    public function setRecurringInterval(?string $v): void
    {
        $this->recurringInterval = $v;
    }

    public function getRecurringDay(): ?string
    {
        return $this->recurringDay;
    }

    public function setRecurringDay(?string $v): void
    {
        $this->recurringDay = $v;
    }

    public function getRecurringTime(): ?string
    {
        return $this->recurringTime;
    }

    public function setRecurringTime(?string $v): void
    {
        $this->recurringTime = $v;
    }

    public function getRecurringPostCount(): ?int
    {
        return $this->recurringPostCount;
    }

    public function setRecurringPostCount(?int $v): void
    {
        $this->recurringPostCount = $v;
    }

    public function getRecurringPostTypes(): ?string
    {
        return $this->recurringPostTypes;
    }

    public function setRecurringPostTypes(?string $v): void
    {
        $this->recurringPostTypes = $v;
    }

    public function getRecurringCategoryId(): ?int
    {
        return $this->recurringCategoryId;
    }

    public function setRecurringCategoryId(?int $v): void
    {
        $this->recurringCategoryId = $v;
    }

    public function getRecurringTagId(): ?int
    {
        return $this->recurringTagId;
    }

    public function setRecurringTagId(?int $v): void
    {
        $this->recurringTagId = $v;
    }

    public function getRecurringCategoryIds(): ?string
    {
        return $this->recurringCategoryIds;
    }

    public function setRecurringCategoryIds(?string $v): void
    {
        $this->recurringCategoryIds = $v;
    }

    public function getRecurringTagIds(): ?string
    {
        return $this->recurringTagIds;
    }

    public function setRecurringTagIds(?string $v): void
    {
        $this->recurringTagIds = $v;
    }

    public function getRecurringLanguage(): ?string
    {
        return $this->recurringLanguage;
    }

    public function setRecurringLanguage(?string $v): void
    {
        $this->recurringLanguage = $v;
    }

    public function getLastRecurringRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRecurringRunAt;
    }

    public function setLastRecurringRunAt(?\DateTimeImmutable $at): void
    {
        $this->lastRecurringRunAt = $at;
    }

    public function markSent(): void
    {
        $this->status = 'sent';
        $this->sentAt = new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $campaign                       = new self($row['subject'], $row['body_html']);
        $campaign->id                   = (int) $row['id'];
        $campaign->subjectB             = $row['subject_b']   ?? null;
        $campaign->bodyHtmlB            = $row['body_html_b'] ?? null;
        $campaign->fromName             = $row['from_name']   ?? null;
        $campaign->fromEmail            = $row['from_email']  ?? null;
        $campaign->status               = $row['status']      ?? 'draft';
        $campaign->abTestEnabled        = (bool) ($row['ab_test_enabled'] ?? false);
        $campaign->variationBWeight     = (int) ($row['variation_b_weight'] ?? 50);
        $campaign->tagFilter            = $row['tag_filter'] ?? null;
        $campaign->sourceFormFilter     = isset($row['source_form_filter']) ? (int) $row['source_form_filter'] : null;
        $campaign->minOrdersFilter      = isset($row['min_orders_filter']) ? (int) $row['min_orders_filter'] : null;
        $campaign->maxOrdersFilter      = isset($row['max_orders_filter']) ? (int) $row['max_orders_filter'] : null;
        $campaign->minOpenRateFilter    = isset($row['min_open_rate_filter']) ? (int) $row['min_open_rate_filter'] : null;
        $campaign->maxOpenRateFilter    = isset($row['max_open_rate_filter']) ? (int) $row['max_open_rate_filter'] : null;
        $campaign->minClickRateFilter   = isset($row['min_click_rate_filter']) ? (int) $row['min_click_rate_filter'] : null;
        $campaign->maxClickRateFilter   = isset($row['max_click_rate_filter']) ? (int) $row['max_click_rate_filter'] : null;
        $campaign->opensCount           = (int) ($row['opens_count'] ?? 0);
        $campaign->opensCountB          = (int) ($row['opens_count_b'] ?? 0);
        $campaign->clicksCount          = (int) ($row['clicks_count'] ?? 0);
        $campaign->clicksCountB         = (int) ($row['clicks_count_b'] ?? 0);
        $campaign->recipientsCount      = (int) ($row['recipients_count'] ?? 0);
        $campaign->recipientsCountA     = (int) ($row['recipients_count_a'] ?? 0);
        $campaign->recipientsCountB     = (int) ($row['recipients_count_b'] ?? 0);
        $campaign->processedCount       = (int) ($row['processed_count'] ?? 0);
        $campaign->totalCount           = (int) ($row['total_count'] ?? 0);
        $campaign->lastSubscriberId     = (int) ($row['last_subscriber_id'] ?? 0);
        $campaign->trackingEnabled      = (int) ($row['tracking_enabled'] ?? self::TRACKING_ALL);
        $campaign->isRecurring          = (bool) ($row['is_recurring'] ?? false);
        $campaign->recurringInterval    = $row['recurring_interval'] ?? null;
        $campaign->recurringDay         = $row['recurring_day']      ?? null;
        $campaign->recurringTime        = $row['recurring_time']     ?? null;
        $campaign->recurringPostCount   = isset($row['recurring_post_count']) ? (int) $row['recurring_post_count'] : null;
        $campaign->recurringPostTypes   = $row['recurring_post_types'] ?? null;
        $campaign->recurringCategoryId  = isset($row['recurring_category_id']) ? (int) $row['recurring_category_id'] : null;
        $campaign->recurringTagId       = isset($row['recurring_tag_id']) ? (int) $row['recurring_tag_id'] : null;
        $campaign->recurringCategoryIds = $row['recurring_category_ids'] ?? null;
        $campaign->recurringTagIds      = $row['recurring_tag_ids']      ?? null;
        $campaign->recurringLanguage    = $row['recurring_language']     ?? null;
        if (!empty($row['last_recurring_run_at'])) {
            $campaign->lastRecurringRunAt = new \DateTimeImmutable($row['last_recurring_run_at']);
        }
        if (!empty($row['scheduled_at'])) {
            $campaign->scheduledAt = new \DateTimeImmutable($row['scheduled_at']);
        }
        if (!empty($row['sent_at'])) {
            $campaign->sentAt = new \DateTimeImmutable($row['sent_at']);
        }
        if (!empty($row['created_at'])) {
            $campaign->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        if (!empty($row['updated_at'])) {
            $campaign->updatedAt = new \DateTimeImmutable($row['updated_at']);
        }
        return $campaign;
    }
}
