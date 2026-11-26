<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Consent;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Consent\ConsentService;
use ValeApp\Application\Consent\ConsentTextAdvisor;
use ValeApp\Domain\Consent\Repository\ConsentRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

final class ConsentServiceTest extends TestCase
{
    /**
     * @param array<string, string> $settings
     */
    private function service(
        array $settings,
        ?ConsentRepositoryInterface $repository = null,
        bool $withAdvisor = false,
    ): ConsentService {
        $repository ??= $this->createStub(ConsentRepositoryInterface::class);

        $settingsRepository = $this->createStub(SettingsRepositoryInterface::class);
        $settingsRepository->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $settings[$key] ?? $default,
        );

        /** @var array{cookie_name:string, cookie_lifetime:int, categories:array<string,array{required:bool}>} $config */
        $config = require dirname(__DIR__, 4) . '/config/consent.php';

        $advisor = $withAdvisor ? new ConsentTextAdvisor($settingsRepository) : null;

        return new ConsentService($settingsRepository, $repository, $config, $advisor);
    }

    private function cookie(string $version, array $categories, string $id = 'abc123'): string
    {
        return base64_encode((string) json_encode([
            'v'  => $version,
            'c'  => $categories,
            'id' => $id,
        ]));
    }

    public function testEverythingIsAllowedWhileModuleIsOff(): void
    {
        $service = $this->service(['consent_enabled' => '0']);

        self::assertFalse($service->enabled());
        self::assertTrue($service->isAllowed('marketing'));
        self::assertTrue($service->isAllowed('analytics'));
    }

    public function testOptionalCategoriesAreBlockedBeforeADecision(): void
    {
        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '1']);

        $state = $service->resolve(null);
        $service->setState($state);

        self::assertFalse($state->decided);
        self::assertTrue($service->isAllowed('necessary'));
        self::assertFalse($service->isAllowed('analytics'));
        self::assertFalse($service->isAllowed('marketing'));
    }

    public function testAcceptedCategoriesAreReadBackFromTheCookie(): void
    {
        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '1']);

        $state = $service->resolve($this->cookie('1', ['analytics']));
        $service->setState($state);

        self::assertTrue($state->decided);
        self::assertSame('abc123', $state->consentId);
        self::assertTrue($service->isAllowed('analytics'));
        self::assertFalse($service->isAllowed('marketing'));
    }

    public function testANewPolicyVersionInvalidatesTheDecision(): void
    {
        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '2']);

        $state = $service->resolve($this->cookie('1', ['analytics']));
        $service->setState($state);

        self::assertFalse($state->decided);
        self::assertFalse($service->isAllowed('analytics'));
    }

    public function testUnknownCategoriesInTheCookieAreIgnored(): void
    {
        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '1']);

        $state = $service->resolve($this->cookie('1', ['analytics', 'bogus']));
        $service->setState($state);

        self::assertSame(['necessary', 'analytics'], $state->categories);
        self::assertFalse($service->isAllowed('bogus'));
    }

    public function testACategorySwitchedOffByTheOperatorNeverRenders(): void
    {
        $service = $this->service([
            'consent_enabled'    => '1',
            'consent_version'    => '1',
            'consent_categories' => 'functional,analytics',
        ]);

        $state = $service->resolve($this->cookie('1', ['analytics', 'marketing']));
        $service->setState($state);

        self::assertTrue($service->isAllowed('analytics'));
        self::assertFalse($service->isAllowed('marketing'));
    }

    public function testSavingAlwaysKeepsNecessaryAndRecordsTheDecision(): void
    {
        $repository = $this->createMock(ConsentRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('record')
            ->with(
                self::isString(),
                'grant',
                ['necessary', 'analytics'],
                '1',
                null,
            );

        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '1'], $repository);

        $state = $service->save(['analytics', 'bogus']);

        self::assertTrue($state->decided);
        self::assertSame(['necessary', 'analytics'], $state->categories);
        self::assertTrue($service->isAllowed('analytics'));
    }

    public function testRevokingKeepsOnlyNecessaryAndLogsItsOwnAction(): void
    {
        $repository = $this->createMock(ConsentRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('record')
            ->with(
                self::isString(),
                'revoke',
                ['necessary'],
                '1',
                null,
            );

        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '1'], $repository);

        $state = $service->revoke();

        self::assertTrue($state->decided);
        self::assertSame(['necessary'], $state->categories);
        self::assertFalse($service->isAllowed('marketing'));
    }

    public function testACategoryWithNothingAssignedIsNotOffered(): void
    {
        $service = $this->service([
            'consent_enabled'    => '1',
            'consent_categories' => 'functional,analytics,marketing',
        ], null, true);

        self::assertSame([], $service->optionalCategories());
    }

    public function testACategoryCarryingAnAssignedScriptIsOffered(): void
    {
        $service = $this->service([
            'consent_enabled'                => '1',
            'consent_categories'             => 'functional,analytics,marketing',
            'custom_footer_scripts'          => '<script src="https://example.test/a.js"></script>',
            'custom_footer_scripts_category' => 'analytics',
        ], null, true);

        self::assertSame(['analytics'], $service->optionalCategories());
    }

    public function testAdvertisingCodeOffersTheMarketingCategory(): void
    {
        $service = $this->service([
            'consent_enabled'       => '1',
            'consent_categories'    => 'functional,analytics,marketing',
            'advertisement_enabled' => '1',
            'advertisement_code'    => '<ins class="adsbygoogle"></ins>',
        ], null, true);

        self::assertSame(['marketing'], $service->optionalCategories());
    }

    public function testTheCacheVariantIsSharedByEveryVisitorInTheSameState(): void
    {
        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '1']);

        $first  = $service->cacheVariant($this->cookie('1', ['analytics'], 'id-one'));
        $second = $service->cacheVariant($this->cookie('1', ['analytics'], 'id-two'));

        self::assertSame('1|1|analytics', $first);
        self::assertSame($first, $second);
        self::assertStringNotContainsString('id-one', $first);
    }

    public function testTheCacheVariantSeparatesUndecidedDecidedAndVersionedStates(): void
    {
        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '1']);

        self::assertSame('1|0|', $service->cacheVariant(null));
        self::assertSame('1|1|analytics', $service->cacheVariant($this->cookie('1', ['analytics'])));

        $nextVersion = $this->service(['consent_enabled' => '1', 'consent_version' => '2']);

        self::assertSame('2|0|', $nextVersion->cacheVariant($this->cookie('1', ['analytics'])));
    }

    public function testGlobalPrivacyControlBlocksMarketingEvenAfterAcceptAll(): void
    {
        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '1']);
        $service->setState($service->resolve($this->cookie('1', ['analytics', 'marketing'])));

        self::assertTrue($service->isAllowed('marketing'));

        $service->setGlobalPrivacyControl(true);

        self::assertFalse($service->isAllowed('marketing'));
        self::assertTrue($service->isAllowed('analytics'));
        self::assertTrue($service->isAllowed('necessary'));
    }

    public function testCacheVariantSeparatesGpcVisitors(): void
    {
        $service = $this->service(['consent_enabled' => '1', 'consent_version' => '1']);
        $cookie  = $this->cookie('1', ['marketing']);

        self::assertSame('1|1|marketing', $service->cacheVariant($cookie, false));
        self::assertSame('1|1|marketing|gpc', $service->cacheVariant($cookie, true));
    }
}
