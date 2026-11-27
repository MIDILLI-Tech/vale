<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Shop;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Shop\B2bCheckoutService;
use ValeApp\Application\Shop\ReverseChargePolicy;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Domain\Shop\Port\VatNumberValidatorInterface;
use ValeApp\Domain\Shop\ValueObject\VatValidation;

final class B2bCheckoutServiceTest extends TestCase
{
    private VatNumberValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = $this->createStub(VatNumberValidatorInterface::class);
    }

    public function testValidEuNumberQualifiesForCrossBorderReverseCharge(): void
    {
        $this->validator->method('validate')->willReturn(VatValidation::valid('ACME SAS'));
        $service = $this->makeService('both', 'DE');

        $result = $service->validate('FRAB123456789', 'FR');

        self::assertNull($result['error']);
        self::assertNull($result['warning']);
        self::assertNotNull($result['validation']);
        self::assertTrue($service->reverseChargeApplies($result['validation'], 'FR'));
    }

    public function testDomesticBusinessKeepsVat(): void
    {
        $this->validator->method('validate')->willReturn(VatValidation::valid());
        $service = $this->makeService('both', 'DE');

        $result = $service->validate('DE123456789', 'DE');

        self::assertNull($result['error']);
        self::assertFalse($service->reverseChargeApplies($result['validation'], 'DE'));
    }

    public function testInvalidNumberReturnsAnErrorAndNoExemption(): void
    {
        $this->validator->method('validate')->willReturn(VatValidation::invalid());
        $service = $this->makeService('both', 'DE');

        $result = $service->validate('FRAB123456789', 'FR');

        self::assertNotNull($result['error']);
        self::assertFalse($service->reverseChargeApplies($result['validation'], 'FR'));
    }

    public function testViesOutageFallsBackToChargingVat(): void
    {
        $this->validator->method('validate')->willReturn(VatValidation::unavailable());
        $service = $this->makeService('both', 'DE');

        $result = $service->validate('FRAB123456789', 'FR');

        self::assertNull($result['error']);
        self::assertNotNull($result['warning']);
        self::assertFalse($service->reverseChargeApplies($result['validation'], 'FR'));
    }

    public function testDisabledValidationNeverGrantsReverseCharge(): void
    {
        $service = $this->makeService('both', 'DE', ['b2b_vat_validation' => '0']);

        $result = $service->validate('FRAB123456789', 'FR');

        self::assertNotNull($result['warning']);
        self::assertNull($result['validation']);
        self::assertFalse($service->reverseChargeApplies($result['validation'], 'FR'));
    }

    public function testNonEuNumberIsStoredWithoutValidation(): void
    {
        $service = $this->makeService('both', 'DE');

        $result = $service->validate('GB123456789', 'GB');

        self::assertNull($result['error']);
        self::assertNotNull($result['warning']);
        self::assertNotNull($result['vat_number']);
        self::assertFalse($service->reverseChargeApplies($result['validation'], 'GB'));
    }

    public function testMalformedNumberReturnsAnError(): void
    {
        $service = $this->makeService('both', 'DE');

        $result = $service->validate('12', 'DE');

        self::assertNotNull($result['error']);
        self::assertNull($result['vat_number']);
    }

    public function testCustomerTypeControlsAvailability(): void
    {
        self::assertFalse($this->makeService('b2c', 'DE')->isEnabled());
        self::assertTrue($this->makeService('b2b', 'DE')->isEnabled());
        self::assertTrue($this->makeService('both', 'DE')->isEnabled());
    }

    public function testCompanyNameMismatchIsFlaggedWhenViesReturnsAName(): void
    {
        $service = $this->makeService('both', 'DE');

        self::assertTrue($service->companyNameMismatch(VatValidation::valid('ACME GmbH'), 'Globex Corporation'));
    }

    public function testCompanyNameComparisonIgnoresCaseAndLegalSuffixes(): void
    {
        $service = $this->makeService('both', 'DE');

        self::assertFalse($service->companyNameMismatch(VatValidation::valid('ACME GmbH'), 'Acme'));
        self::assertFalse($service->companyNameMismatch(VatValidation::valid('Acme S.A.'), 'ACME SA'));
        self::assertFalse($service->companyNameMismatch(VatValidation::valid('Acme Trading Ltd'), 'Acme Trading'));
    }

    public function testMaskedViesNameIsNotCompared(): void
    {
        $service = $this->makeService('both', 'DE');

        self::assertFalse($service->companyNameMismatch(VatValidation::valid('---'), 'Anything'));
        self::assertFalse($service->companyNameMismatch(VatValidation::valid('N/A'), 'Anything'));
        self::assertFalse($service->companyNameMismatch(VatValidation::valid(), 'Anything'));
    }

    public function testNameMismatchDoesNotDisableReverseCharge(): void
    {
        $service    = $this->makeService('both', 'DE');
        $validation = VatValidation::valid('ACME GmbH');

        self::assertTrue($service->companyNameMismatch($validation, 'Globex Corporation'));
        self::assertTrue($service->reverseChargeApplies($validation, 'FR'));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function makeService(string $customerType, string $shopCountry, array $overrides = []): B2bCheckoutService
    {
        $values = $overrides + [
            'shop_customer_type' => $customerType,
            'shop_country'       => $shopCountry,
            'b2b_vat_validation' => '1',
        ];

        $settings = $this->createStub(SettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $values[$key] ?? $default,
        );

        return new B2bCheckoutService($this->validator, new ReverseChargePolicy(), $settings);
    }
}
