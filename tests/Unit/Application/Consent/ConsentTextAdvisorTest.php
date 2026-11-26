<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Consent;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Consent\ConsentTextAdvisor;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class ConsentTextAdvisorTest extends TestCase
{
    /**
     * @param array<string, string> $settings
     */
    private function advisor(array $settings): ConsentTextAdvisor
    {
        $repository = $this->createStub(SettingsRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $settings[$key] ?? $default,
        );

        return new ConsentTextAdvisor($repository);
    }

    public function testOnlyNecessaryIsClaimedWhenNothingElseIsActive(): void
    {
        $advisor = $this->advisor([]);

        self::assertFalse($advisor->hasThirdPartyServices());
        self::assertSame(
            ['We use technically necessary cookies to run this site.'],
            $advisor->bannerSentences(),
        );
        self::assertSame(['We use only technically necessary cookies.'], $advisor->noticeSentences());
    }

    public function testCaptchaProviderAddsItsOwnSentence(): void
    {
        $advisor = $this->advisor(['register_captcha_type' => 'hcaptcha']);

        self::assertTrue($advisor->usesCaptcha());
        self::assertSame(
            [
                'We use technically necessary cookies to run this site.',
                'Spam protection services on forms load before you choose.',
                'You can change or withdraw your choice at any time.',
            ],
            $advisor->bannerSentences(),
        );
    }

    public function testLocalCaptchaTypesAreNotReportedAsThirdParty(): void
    {
        $advisor = $this->advisor([
            'login_captcha_type'    => 'honeypot',
            'register_captcha_type' => 'math',
        ]);

        self::assertFalse($advisor->usesCaptcha());
        self::assertFalse($advisor->hasThirdPartyServices());
    }

    public function testAdvertisingImpliesTheMarketingSentence(): void
    {
        $advisor = $this->advisor([
            'advertisement_enabled' => '1',
            'advertisement_code'    => '<ins class="ads"></ins>',
        ]);

        self::assertSame(['marketing'], $advisor->activeCategories());
        self::assertContains(
            'Marketing and advertising services are loaded only with your consent.',
            $advisor->bannerSentences(),
        );
        self::assertContains('Advertising services are active on this site.', $advisor->noticeSentences());
    }

    public function testScriptsUseTheirAssignedCategory(): void
    {
        $advisor = $this->advisor([
            'custom_footer_scripts'          => '<script src="https://example.test/a.js"></script>',
            'custom_footer_scripts_category' => 'analytics',
        ]);

        self::assertSame(['analytics'], $advisor->scriptCategories());
        self::assertContains(
            'Analytics services are loaded only with your consent.',
            $advisor->bannerSentences(),
        );
        self::assertNotContains(
            'Marketing and advertising services are loaded only with your consent.',
            $advisor->bannerSentences(),
        );
    }

    public function testUnclassifiedScriptsAreStillDisclosed(): void
    {
        $advisor = $this->advisor([
            'custom_header_scripts' => '<script src="https://example.test/a.js"></script>',
        ]);

        self::assertTrue($advisor->hasUnclassifiedScripts());
        self::assertSame([], $advisor->activeCategories());
        self::assertContains(
            'Additional third-party services are loaded on this site.',
            $advisor->bannerSentences(),
        );
    }

    public function testAnEmptyScriptBlockIsNotReported(): void
    {
        $advisor = $this->advisor([
            'custom_header_scripts'          => '   ',
            'custom_header_scripts_category' => 'marketing',
        ]);

        self::assertSame([], $advisor->scriptCategories());
        self::assertFalse($advisor->hasThirdPartyServices());
    }
}
