<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Domain\Analytics\Entity;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Analytics\Entity\Pageview;

final class PageviewTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $pv = new Pageview('/blog/hello', 'visitor-1');

        self::assertSame('/blog/hello', $pv->getPageUrl());
        self::assertSame('visitor-1', $pv->getVisitorId());
        self::assertNull($pv->getPageTitle());
        self::assertNull($pv->getReferrer());
        self::assertNull($pv->getReferrerDomain());
        self::assertNull($pv->getCountry());
        self::assertNull($pv->getCity());
        self::assertNull($pv->getLanguage());
        self::assertNull($pv->getBrowser());
        self::assertNull($pv->getDeviceType());
        self::assertSame(200, $pv->getStatusCode());
    }

    public function testConstructorAllParams(): void
    {
        $pv = new Pageview(
            '/about',
            'v-123',
            'About Us',
            'https://google.com',
            'google.com',
            'TR',
            'Istanbul',
            'tr',
            'Chrome',
            'desktop',
            301,
        );

        self::assertSame('/about', $pv->getPageUrl());
        self::assertSame('v-123', $pv->getVisitorId());
        self::assertSame('About Us', $pv->getPageTitle());
        self::assertSame('https://google.com', $pv->getReferrer());
        self::assertSame('google.com', $pv->getReferrerDomain());
        self::assertSame('TR', $pv->getCountry());
        self::assertSame('Istanbul', $pv->getCity());
        self::assertSame('tr', $pv->getLanguage());
        self::assertSame('Chrome', $pv->getBrowser());
        self::assertSame('desktop', $pv->getDeviceType());
        self::assertSame(301, $pv->getStatusCode());
    }

    public function testSetters(): void
    {
        $pv = new Pageview('/x', 'v1');

        $pv->setPageTitle('X Page');
        $pv->setReferrer('https://example.com');
        $pv->setLanguage('en');

        self::assertSame('X Page', $pv->getPageTitle());
        self::assertSame('https://example.com', $pv->getReferrer());
        self::assertSame('en', $pv->getLanguage());
    }

    public function testFromRow(): void
    {
        $pv = Pageview::fromRow([
            'id'              => '100',
            'page_url'        => '/shop',
            'visitor_id'      => 'v-abc',
            'page_title'      => 'Shop',
            'referrer'        => 'https://google.com',
            'referrer_domain' => 'google.com',
            'country'         => 'DE',
            'city'            => 'Berlin',
            'language'        => 'de',
            'browser'         => 'Firefox',
            'device_type'     => 'mobile',
            'status_code'     => '200',
            'created_at'      => '2024-07-01 12:00:00',
        ]);

        self::assertSame(100, $pv->getId());
        self::assertSame('/shop', $pv->getPageUrl());
        self::assertSame('v-abc', $pv->getVisitorId());
        self::assertSame('Shop', $pv->getPageTitle());
        self::assertSame('https://google.com', $pv->getReferrer());
        self::assertSame('google.com', $pv->getReferrerDomain());
        self::assertSame('DE', $pv->getCountry());
        self::assertSame('Berlin', $pv->getCity());
        self::assertSame('de', $pv->getLanguage());
        self::assertSame('Firefox', $pv->getBrowser());
        self::assertSame('mobile', $pv->getDeviceType());
        self::assertSame(200, $pv->getStatusCode());
        self::assertInstanceOf(\DateTimeImmutable::class, $pv->getCreatedAt());
    }

    public function testFromRowDefaults(): void
    {
        $pv = Pageview::fromRow([
            'id'         => '1',
            'page_url'   => '/x',
            'visitor_id' => 'v1',
        ]);

        self::assertNull($pv->getPageTitle());
        self::assertNull($pv->getReferrer());
        self::assertNull($pv->getCountry());
        self::assertSame(200, $pv->getStatusCode());
        self::assertNull($pv->getCreatedAt());
    }
}
