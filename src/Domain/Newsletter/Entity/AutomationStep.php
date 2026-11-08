<?php

declare(strict_types=1);

namespace ValeApp\Domain\Newsletter\Entity;

final class AutomationStep implements \JsonSerializable
{
    private ?int $id = null;
    private int $sequenceId;
    private int $sortOrder;
    private int $delayMinutes;
    private bool $abTestEnabled                  = false;
    private int $variationBWeight                = 50;
    private ?int $minOrdersFilter                = null;
    private ?int $maxOrdersFilter                = null;
    private ?int $minOpenRateFilter              = null;
    private ?int $maxOpenRateFilter              = null;
    private ?int $minClickRateFilter             = null;
    private ?int $maxClickRateFilter             = null;
    private ?float $minTotalSpendFilter          = null;
    private ?float $maxTotalSpendFilter          = null;
    private ?int $minDaysSinceLastPurchaseFilter = null;
    private ?int $maxDaysSinceLastPurchaseFilter = null;
    private string $subject;
    private ?string $subjectB = null;
    private string $bodyHtml;
    private ?string $bodyHtmlB             = null;
    private int $opensCount                = 0;
    private int $clicksCount               = 0;
    private int $opensCountB               = 0;
    private int $clicksCountB              = 0;
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct(
        int $sequenceId,
        int $sortOrder,
        int $delayMinutes,
        string $subject,
        string $bodyHtml,
    ) {
        $this->sequenceId   = $sequenceId;
        $this->sortOrder    = $sortOrder;
        $this->delayMinutes = $delayMinutes;
        $this->subject      = $subject;
        $this->bodyHtml     = $bodyHtml;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getSequenceId(): int
    {
        return $this->sequenceId;
    }
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
    public function getDelayMinutes(): int
    {
        return $this->delayMinutes;
    }
    public function isAbTestEnabled(): bool
    {
        return $this->abTestEnabled;
    }
    public function getVariationBWeight(): int
    {
        return $this->variationBWeight;
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
    public function getOpensCount(): int
    {
        return $this->opensCount;
    }
    public function getClicksCount(): int
    {
        return $this->clicksCount;
    }
    public function getOpensCountB(): int
    {
        return $this->opensCountB;
    }
    public function getClicksCountB(): int
    {
        return $this->clicksCountB;
    }
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }
    public function setSortOrder(int $n): void
    {
        $this->sortOrder = $n;
    }
    public function setDelayMinutes(int $n): void
    {
        $this->delayMinutes = $n;
    }
    public function setAbTestEnabled(bool $v): void
    {
        $this->abTestEnabled = $v;
    }
    public function setVariationBWeight(int $w): void
    {
        $this->variationBWeight = $w;
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
    public function getMinTotalSpendFilter(): ?float
    {
        return $this->minTotalSpendFilter;
    }
    public function getMaxTotalSpendFilter(): ?float
    {
        return $this->maxTotalSpendFilter;
    }
    public function getMinDaysSinceLastPurchaseFilter(): ?int
    {
        return $this->minDaysSinceLastPurchaseFilter;
    }
    public function getMaxDaysSinceLastPurchaseFilter(): ?int
    {
        return $this->maxDaysSinceLastPurchaseFilter;
    }
    public function setMinTotalSpendFilter(?float $min): void
    {
        $this->minTotalSpendFilter = $min;
    }
    public function setMaxTotalSpendFilter(?float $max): void
    {
        $this->maxTotalSpendFilter = $max;
    }
    public function setMinDaysSinceLastPurchaseFilter(?int $min): void
    {
        $this->minDaysSinceLastPurchaseFilter = $min;
    }
    public function setMaxDaysSinceLastPurchaseFilter(?int $max): void
    {
        $this->maxDaysSinceLastPurchaseFilter = $max;
    }
    public function setSubject(string $s): void
    {
        $this->subject = $s;
    }
    public function setSubjectB(?string $s): void
    {
        $this->subjectB = $s;
    }
    public function setBodyHtml(string $h): void
    {
        $this->bodyHtml = $h;
    }
    public function setBodyHtmlB(?string $h): void
    {
        $this->bodyHtmlB = $h;
    }
    public function setOpensCount(int $n): void
    {
        $this->opensCount = $n;
    }
    public function setClicksCount(int $n): void
    {
        $this->clicksCount = $n;
    }
    public function setOpensCountB(int $n): void
    {
        $this->opensCountB = $n;
    }
    public function setClicksCountB(int $n): void
    {
        $this->clicksCountB = $n;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): mixed
    {
        return [
            'id'                                  => $this->id,
            'sequence_id'                         => $this->sequenceId,
            'sort_order'                          => $this->sortOrder,
            'delay_minutes'                       => $this->delayMinutes,
            'ab_test_enabled'                     => $this->abTestEnabled,
            'variation_b_weight'                  => $this->variationBWeight,
            'min_orders_filter'                   => $this->minOrdersFilter,
            'max_orders_filter'                   => $this->maxOrdersFilter,
            'min_open_rate_filter'                => $this->minOpenRateFilter,
            'max_open_rate_filter'                => $this->maxOpenRateFilter,
            'min_click_rate_filter'               => $this->minClickRateFilter,
            'max_click_rate_filter'               => $this->maxClickRateFilter,
            'min_total_spend_filter'              => $this->minTotalSpendFilter,
            'max_total_spend_filter'              => $this->maxTotalSpendFilter,
            'min_days_since_last_purchase_filter' => $this->minDaysSinceLastPurchaseFilter,
            'max_days_since_last_purchase_filter' => $this->maxDaysSinceLastPurchaseFilter,
            'subject'                             => $this->subject,
            'subject_b'                           => $this->subjectB,
            'body_html'                           => $this->bodyHtml,
            'body_html_b'                         => $this->bodyHtmlB,
            'opens_count'                         => $this->opensCount,
            'clicks_count'                        => $this->clicksCount,
            'opens_count_b'                       => $this->opensCountB,
            'clicks_count_b'                      => $this->clicksCountB,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $step = new self(
            sequenceId:   (int) $row['sequence_id'],
            sortOrder:    (int) ($row['sort_order'] ?? 0),
            delayMinutes: (int) ($row['delay_minutes'] ?? 0),
            subject:      $row['subject'],
            bodyHtml:     $row['body_html'],
        );
        $step->abTestEnabled                  = (bool) ($row['ab_test_enabled'] ?? false);
        $step->variationBWeight               = (int) ($row['variation_b_weight'] ?? 50);
        $step->minOrdersFilter                = isset($row['min_orders_filter']) ? (int) $row['min_orders_filter'] : null;
        $step->maxOrdersFilter                = isset($row['max_orders_filter']) ? (int) $row['max_orders_filter'] : null;
        $step->minOpenRateFilter              = isset($row['min_open_rate_filter']) ? (int) $row['min_open_rate_filter'] : null;
        $step->maxOpenRateFilter              = isset($row['max_open_rate_filter']) ? (int) $row['max_open_rate_filter'] : null;
        $step->minClickRateFilter             = isset($row['min_click_rate_filter']) ? (int) $row['min_click_rate_filter'] : null;
        $step->maxClickRateFilter             = isset($row['max_click_rate_filter']) ? (int) $row['max_click_rate_filter'] : null;
        $step->minTotalSpendFilter            = isset($row['min_total_spend_filter']) ? (float) $row['min_total_spend_filter'] : null;
        $step->maxTotalSpendFilter            = isset($row['max_total_spend_filter']) ? (float) $row['max_total_spend_filter'] : null;
        $step->minDaysSinceLastPurchaseFilter = isset($row['min_days_since_last_purchase_filter']) ? (int) $row['min_days_since_last_purchase_filter'] : null;
        $step->maxDaysSinceLastPurchaseFilter = isset($row['max_days_since_last_purchase_filter']) ? (int) $row['max_days_since_last_purchase_filter'] : null;
        $step->subjectB                       = $row['subject_b']   ?? null;
        $step->bodyHtmlB                      = $row['body_html_b'] ?? null;
        $step->opensCount                     = (int) ($row['opens_count'] ?? 0);
        $step->clicksCount                    = (int) ($row['clicks_count'] ?? 0);
        $step->opensCountB                    = (int) ($row['opens_count_b'] ?? 0);
        $step->clicksCountB                   = (int) ($row['clicks_count_b'] ?? 0);
        $step->id                             = (int) $row['id'];
        if (!empty($row['created_at'])) {
            $step->createdAt = new \DateTimeImmutable($row['created_at']);
        }
        return $step;
    }
}
