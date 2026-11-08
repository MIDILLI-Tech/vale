<?php

declare(strict_types=1);

namespace ValeApp\Domain\Analytics\Entity;

use ValeApp\Domain\Shared\Entity\BaseEntity;

class Pageview extends BaseEntity
{
    public function __construct(
        private string $pageUrl,
        private string $visitorId,
        private ?string $pageTitle = null,
        private ?string $referrer = null,
        private ?string $referrerDomain = null,
        private ?string $country = null,
        private ?string $city = null,
        private ?string $language = null,
        private ?string $browser = null,
        private ?string $deviceType = null,
        private int $statusCode = 200,
        private ?string $utmSource = null,
        private ?string $utmMedium = null,
        private ?string $utmCampaign = null,
        private ?string $utmTerm = null,
        private ?string $utmContent = null,
        private ?string $pagePath = null,
    ) {
    }

    public function getPageUrl(): string
    {
        return $this->pageUrl;
    }
    public function getPageTitle(): ?string
    {
        return $this->pageTitle;
    }
    public function getReferrer(): ?string
    {
        return $this->referrer;
    }
    public function getReferrerDomain(): ?string
    {
        return $this->referrerDomain;
    }
    public function getCountry(): ?string
    {
        return $this->country;
    }
    public function getCity(): ?string
    {
        return $this->city;
    }
    public function getLanguage(): ?string
    {
        return $this->language;
    }
    public function getBrowser(): ?string
    {
        return $this->browser;
    }
    public function getDeviceType(): ?string
    {
        return $this->deviceType;
    }
    public function getVisitorId(): string
    {
        return $this->visitorId;
    }
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    public function getUtmSource(): ?string
    {
        return $this->utmSource;
    }
    public function getUtmMedium(): ?string
    {
        return $this->utmMedium;
    }
    public function getUtmCampaign(): ?string
    {
        return $this->utmCampaign;
    }
    public function getUtmTerm(): ?string
    {
        return $this->utmTerm;
    }
    public function getUtmContent(): ?string
    {
        return $this->utmContent;
    }
    public function getPagePath(): ?string
    {
        return $this->pagePath;
    }

    public function setPageTitle(?string $title): void
    {
        $this->pageTitle = $title;
    }
    public function setReferrer(?string $url): void
    {
        $this->referrer = $url;
    }
    public function setLanguage(?string $language): void
    {
        $this->language = $language;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $pv = new self(
            $row['page_url'],
            $row['visitor_id'],
            $row['page_title']      ?? null,
            $row['referrer']        ?? null,
            $row['referrer_domain'] ?? null,
            $row['country']         ?? null,
            $row['city']            ?? null,
            $row['language']        ?? null,
            $row['browser']         ?? null,
            $row['device_type']     ?? null,
            (int) ($row['status_code'] ?? 200),
            $row['utm_source']      ?? null,
            $row['utm_medium']      ?? null,
            $row['utm_campaign']    ?? null,
            $row['utm_term']        ?? null,
            $row['utm_content']     ?? null,
            $row['page_path']       ?? null,
        );

        $pv->id = (int) $row['id'];
        if (!empty($row['created_at'])) {
            $pv->createdAt = new \DateTimeImmutable($row['created_at']);
        }

        return $pv;
    }
}
