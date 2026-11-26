<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Consent;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Consent\CaptchaConsentGate;

final class CaptchaConsentGateTest extends TestCase
{
    protected function tearDown(): void
    {
        CaptchaConsentGate::configure(null);
    }

    public function testConfiguredProviderIsUsedWhileGatingIsOff(): void
    {
        CaptchaConsentGate::configure(null);

        self::assertFalse(CaptchaConsentGate::isGating());
        self::assertSame('turnstile', CaptchaConsentGate::effectiveType('turnstile'));
        self::assertFalse(CaptchaConsentGate::isFallback('turnstile'));
    }

    public function testDeclinedVisitorGetsTheMathChallenge(): void
    {
        CaptchaConsentGate::configure(false);

        self::assertTrue(CaptchaConsentGate::isGating());
        self::assertFalse(CaptchaConsentGate::providerAllowed());
        self::assertSame('math', CaptchaConsentGate::effectiveType('hcaptcha'));
        self::assertSame('math', CaptchaConsentGate::effectiveType('recaptcha'));
        self::assertSame('math', CaptchaConsentGate::effectiveType('turnstile'));
        self::assertTrue(CaptchaConsentGate::isFallback('recaptcha'));
    }

    public function testAcceptedFunctionalCategoryKeepsTheProvider(): void
    {
        CaptchaConsentGate::configure(true);

        self::assertSame('hcaptcha', CaptchaConsentGate::effectiveType('hcaptcha'));
        self::assertFalse(CaptchaConsentGate::isFallback('hcaptcha'));
    }

    public function testLocalCaptchaTypesAreNeverRewritten(): void
    {
        CaptchaConsentGate::configure(false);

        self::assertSame('math', CaptchaConsentGate::effectiveType('math'));
        self::assertSame('honeypot', CaptchaConsentGate::effectiveType('honeypot'));
        self::assertSame('none', CaptchaConsentGate::effectiveType('none'));
        self::assertFalse(CaptchaConsentGate::isFallback('math'));
    }
}
