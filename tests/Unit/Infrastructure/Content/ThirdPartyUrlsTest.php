<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Content;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Content\CaptchaUrls;
use ValeApp\Infrastructure\Payment\PaymentApiUrls;

final class ThirdPartyUrlsTest extends TestCase
{
    public function testCaptchaVerifyAndScriptUrlsComeFromCatalog(): void
    {
        $catalog = require dirname(__DIR__, 4) . '/config/reference-data.php';
        $urls    = new CaptchaUrls($catalog['captcha_urls']);

        self::assertSame('https://api.hcaptcha.com/siteverify', $urls->verifyUrl('hcaptcha'));
        self::assertSame('https://www.google.com/recaptcha/api/siteverify', $urls->verifyUrl('recaptcha'));
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $urls->verifyUrl('turnstile'));

        self::assertSame('https://js.hcaptcha.com/1/api.js', $urls->scriptUrl('hcaptcha'));
        self::assertSame('https://www.google.com/recaptcha/api.js', $urls->scriptUrl('recaptcha'));
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/api.js', $urls->scriptUrl('turnstile'));
    }

    public function testCaptchaUrlsThrowWhenCatalogEntryMissing(): void
    {
        $urls = new CaptchaUrls([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing captcha verify_url');
        $urls->verifyUrl('hcaptcha');
    }

    public function testCaptchaScriptUrlThrowsWhenMissing(): void
    {
        $urls = new CaptchaUrls(['hcaptcha' => ['verify_url' => 'https://api.hcaptcha.com/siteverify']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing captcha script_url');
        $urls->scriptUrl('hcaptcha');
    }

    public function testPaymentApiBasesComeFromCatalog(): void
    {
        $catalog = require dirname(__DIR__, 4) . '/config/reference-data.php';
        $urls    = new PaymentApiUrls($catalog['payment_api']);

        self::assertSame('https://api.stripe.com', $urls->stripeBase());
        self::assertSame('https://api-m.sandbox.paypal.com', $urls->paypalBase(true));
        self::assertSame('https://api-m.paypal.com', $urls->paypalBase(false));
        self::assertSame('https://api.mollie.com/v2', $urls->mollieBase());
        self::assertSame('https://api.sandbox.checkout.com', $urls->checkoutComBase(true));
        self::assertSame('https://api.checkout.com', $urls->checkoutComBase(false));
        self::assertSame('https://api.2checkout.com/rest/6.0', $urls->twoCheckoutApiBase());
        self::assertSame('https://secure.2checkout.com/checkout', $urls->twoCheckoutUrl());
    }

    public function testPaymentApiUrlsThrowWhenMissing(): void
    {
        $urls = new PaymentApiUrls(['stripe' => ['api_base' => '']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing payment API URL');
        $urls->stripeBase();
    }

    public function testReferenceDataHasExchangeRateApiTemplates(): void
    {
        $catalog = require dirname(__DIR__, 4) . '/config/reference-data.php';
        $byValue = [];
        foreach ($catalog['services']['exchange_rate'] as $entry) {
            $byValue[$entry['value']] = $entry['api_url_template'] ?? null;
        }

        self::assertSame('https://api.frankfurter.app/latest?from={base}', $byValue['frankfurter']);
        self::assertSame('https://openexchangerates.org/api/latest.json?app_id={key}', $byValue['openexchangerates']);
        self::assertSame('https://v6.exchangerate-api.com/v6/{key}/latest/{base}', $byValue['exchangerateapi']);
    }
}
