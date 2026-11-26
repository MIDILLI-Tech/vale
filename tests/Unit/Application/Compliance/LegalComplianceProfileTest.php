<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Compliance;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Compliance\LegalComplianceProfile;

final class LegalComplianceProfileTest extends TestCase
{
    private function catalog(): array
    {
        return [
            'items' => [
                'imprint'    => ['setting' => 'policy_page_imprint'],
                'privacy'    => ['setting' => 'policy_page_privacy'],
                'withdrawal' => ['available' => false],
                'consent'    => ['setting' => 'consent_enabled', 'fallback_setting' => 'cookie_warning_enabled'],
                'us_optout'  => ['setting' => 'consent_us_optout_enabled'],
            ],
            'regions' => [
                'de' => [
                    'label'         => 'Germany',
                    'consent_model' => 'opt-in',
                    'levels'        => [
                        'imprint'    => 'required',
                        'privacy'    => 'required',
                        'withdrawal' => 'required',
                        'consent'    => 'required',
                    ],
                ],
                'us' => [
                    'label'         => 'United States',
                    'consent_model' => 'opt-out',
                    'levels'        => [
                        'privacy'   => 'recommended',
                        'us_optout' => 'required',
                    ],
                ],
                'other' => [
                    'label'         => 'Other',
                    'consent_model' => 'opt-in',
                    'levels'        => ['privacy' => 'recommended'],
                ],
            ],
            'countries'      => ['DE' => 'de', 'US' => 'us'],
            'default_region' => 'other',
            'audits'         => [
                'banner_off'  => [
                    'regions'   => ['de'],
                    'severity'  => 'warning',
                    'when'      => [['setting' => 'consent_enabled', 'not' => '1']],
                    'link'      => '/admin/settings?tab=privacy&subtab=consent',
                    'link_text' => 'Cookie Consent',
                ],
                'ads_ungated' => [
                    'regions'   => ['de'],
                    'severity'  => 'warning',
                    'when'      => [
                        ['setting' => 'ads', 'is' => '1'],
                        ['setting' => 'ads_gated', 'not' => '1'],
                    ],
                    'link'      => '/admin/settings?tab=content&subtab=ads',
                    'link_text' => 'Advertisement',
                ],
                'captcha'     => [
                    'regions'   => ['de'],
                    'severity'  => 'warning',
                    'when'      => [
                        ['any' => [
                            ['setting' => 'captcha_a', 'in' => ['recaptcha']],
                            ['setting' => 'captcha_b', 'in' => ['hcaptcha']],
                        ]],
                        ['setting' => 'captcha_gate', 'not' => '1'],
                    ],
                    'link'      => '/admin/settings?tab=privacy&subtab=consent',
                    'link_text' => 'Cookie Consent',
                ],
                'us_only'     => [
                    'regions'   => ['us'],
                    'severity'  => 'info',
                    'when'      => [['setting' => 'consent_enabled', 'is' => '1']],
                    'link'      => '/admin/settings?tab=privacy&subtab=consent',
                    'link_text' => 'Cookie Consent',
                ],
            ],
        ];
    }

    private function profile(): LegalComplianceProfile
    {
        return new LegalComplianceProfile($this->catalog());
    }

    public function testRegionIsResolvedFromTheCountryMap(): void
    {
        $profile = $this->profile();

        self::assertSame('de', $profile->regionFor('de'));
        self::assertSame('us', $profile->regionFor(' US '));
        self::assertSame('other', $profile->regionFor('XX'));
        self::assertSame('other', $profile->regionFor(''));
        self::assertSame('Germany', $profile->summary('DE')['label']);
        self::assertSame('opt-out', $profile->summary('US')['consent_model']);
    }

    public function testCountryRegionsCoversEveryCode(): void
    {
        self::assertSame(
            ['DE' => 'de', 'FR' => 'other', 'US' => 'us'],
            $this->profile()->countryRegions(['DE', 'FR', 'US']),
        );
    }

    public function testChecklistReportsConfiguredMissingPartialAndUnavailable(): void
    {
        $checklist = $this->profile()->checklist('DE', [
            'policy_page_privacy'    => 'privacy',
            'consent_enabled'        => '0',
            'cookie_warning_enabled' => '1',
        ]);

        $statuses = [];
        foreach ($checklist as $item) {
            $statuses[$item['key']] = $item['status'];
        }

        self::assertSame(LegalComplianceProfile::STATUS_CONFIGURED, $statuses['privacy']);
        self::assertSame(LegalComplianceProfile::STATUS_MISSING, $statuses['imprint']);
        self::assertSame(LegalComplianceProfile::STATUS_UNAVAILABLE, $statuses['withdrawal']);
        self::assertSame(LegalComplianceProfile::STATUS_PARTIAL, $statuses['consent']);
    }

    public function testAcknowledgementSilencesAnItemWithoutClaimingItIsConfigured(): void
    {
        $settings = [
            'legal_acknowledgements' => json_encode(['imprint' => '2026-11-26', 'withdrawal' => '2026-11-26']),
        ];

        $checklist = $this->profile()->checklist('DE', $settings);
        $rows      = [];
        foreach ($checklist as $item) {
            $rows[$item['key']] = $item;
        }

        self::assertSame(LegalComplianceProfile::STATUS_ACKNOWLEDGED, $rows['imprint']['status']);
        self::assertTrue($rows['imprint']['acknowledged']);
        self::assertSame(LegalComplianceProfile::STATUS_ACKNOWLEDGED, $rows['withdrawal']['status']);

        $ids = array_column($this->profile()->findings('DE', $settings), 'id');
        self::assertContains('missing_documents', $ids);
        self::assertNotContains('imprint', $ids);
    }

    public function testMissingRequiredDocumentsAreReportedOnce(): void
    {
        $findings = $this->profile()->findings('DE', ['consent_enabled' => '1']);
        $missing  = array_values(array_filter(
            $findings,
            static fn (array $finding): bool => $finding['id'] === 'missing_documents',
        ));

        self::assertCount(1, $missing);
        self::assertSame('warning', $missing[0]['severity']);
        self::assertSame('/admin/settings?tab=ecommerce&subtab=legal', $missing[0]['link']);
    }

    public function testAuditsAreLimitedToTheirRegions(): void
    {
        $ids = array_column($this->profile()->findings('US', ['consent_enabled' => '1']), 'id');

        self::assertContains('us_only', $ids);
        self::assertNotContains('banner_off', $ids);
    }

    public function testAuditConditionsSupportAnyAndRequireAll(): void
    {
        $profile = $this->profile();

        $hit = array_column($profile->findings('DE', [
            'consent_enabled' => '1',
            'captcha_b'       => 'hcaptcha',
            'captcha_gate'    => '0',
        ]), 'id');
        self::assertContains('captcha', $hit);

        $gated = array_column($profile->findings('DE', [
            'consent_enabled' => '1',
            'captcha_b'       => 'hcaptcha',
            'captcha_gate'    => '1',
        ]), 'id');
        self::assertNotContains('captcha', $gated);

        $covered = array_column($profile->findings('DE', [
            'consent_enabled' => '1',
            'ads'             => '1',
            'ads_gated'       => '1',
        ]), 'id');
        self::assertNotContains('ads_ungated', $covered);
    }

    public function testBannerOffFindingCarriesItsSettingsLink(): void
    {
        $findings = $this->profile()->findings('DE', ['consent_enabled' => '0']);
        $banner   = array_values(array_filter(
            $findings,
            static fn (array $finding): bool => $finding['id'] === 'banner_off',
        ));

        self::assertCount(1, $banner);
        self::assertSame('/admin/settings?tab=privacy&subtab=consent', $banner[0]['link']);
        self::assertSame('warning', $banner[0]['severity']);
    }
}
