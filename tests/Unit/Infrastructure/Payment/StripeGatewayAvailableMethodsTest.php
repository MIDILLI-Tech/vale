<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Payment;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Payment\StripeGateway;

final class StripeGatewayAvailableMethodsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function configuration(): array
    {
        return [
            'id'                 => 'pmc_1',
            'object'             => 'payment_method_configuration',
            'name'               => 'Default',
            'active'             => true,
            'is_default'         => true,
            'livemode'           => false,
            'display_preference' => ['preference' => 'on'],
            'card'               => ['available' => true, 'display_preference' => ['preference' => 'on']],
            'ideal'              => ['available' => true, 'display_preference' => ['preference' => 'on']],
            'klarna'             => ['available' => false, 'display_preference' => ['preference' => 'off']],
            'blik'               => ['available' => true, 'display_preference' => ['preference' => 'on']],
        ];
    }

    public function testOnlyAvailableMethodsAreReturnedSorted(): void
    {
        self::assertSame(
            ['blik', 'card', 'ideal'],
            StripeGateway::extractAvailableMethods($this->configuration())
        );
    }

    public function testWalletAndCardBrandEntriesAreExcluded(): void
    {
        $config                     = $this->configuration();
        $config['apple_pay']        = ['available' => true];
        $config['google_pay']       = ['available' => true];
        $config['cartes_bancaires'] = ['available' => true];

        self::assertSame(
            ['blik', 'card', 'ideal'],
            StripeGateway::extractAvailableMethods($config)
        );
    }

    public function testScalarAndMetadataKeysAreIgnored(): void
    {
        $config         = $this->configuration();
        $config['url']  = '/v1/payment_method_configurations';

        self::assertSame(
            ['blik', 'card', 'ideal'],
            StripeGateway::extractAvailableMethods($config)
        );
    }

    public function testUnknownRegionSpecificMethodsArePassedThrough(): void
    {
        self::assertSame(
            ['affirm', 'bancontact', 'us_bank_account'],
            StripeGateway::extractAvailableMethods([
                'us_bank_account' => ['available' => true],
                'affirm'          => ['available' => true],
                'bancontact'      => ['available' => true],
            ])
        );
    }

    public function testMethodWithoutAvailableKeyIsIgnored(): void
    {
        self::assertSame([], StripeGateway::extractAvailableMethods([
            'card' => ['display_preference' => ['preference' => 'on']],
        ]));
    }

    public function testEmptyConfigurationReturnsEmptyList(): void
    {
        self::assertSame([], StripeGateway::extractAvailableMethods([]));
    }
}
