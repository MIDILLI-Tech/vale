<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use ValeApp\App\Controllers\Admin\PaymentController;

final class PaymentControllerTest extends TestCase
{
    public function testEmptyInputKeepsAutomaticMode(): void
    {
        self::assertSame('', PaymentController::normalizePaymentMethodTypes(''));
        self::assertSame('', PaymentController::normalizePaymentMethodTypes('   '));
    }

    public function testCardOnlyIsPreserved(): void
    {
        self::assertSame('card', PaymentController::normalizePaymentMethodTypes('card'));
    }

    public function testCommaSeparatedListIsLowercasedAndTrimmed(): void
    {
        self::assertSame(
            'card,ideal,klarna',
            PaymentController::normalizePaymentMethodTypes(' Card , ideal ,KLARNA ')
        );
    }

    public function testSpaceSeparatedListIsAccepted(): void
    {
        self::assertSame('card,ideal', PaymentController::normalizePaymentMethodTypes('card ideal'));
    }

    public function testDuplicatesAreRemoved(): void
    {
        self::assertSame('card,ideal', PaymentController::normalizePaymentMethodTypes('card,card,ideal,card'));
    }

    public function testInvalidTokensAreDropped(): void
    {
        self::assertSame('card,sepa_debit', PaymentController::normalizePaymentMethodTypes('card, invalid-type, sepa_debit, <script>'));
    }

    public function testPurelyInvalidInputFallsBackToAutomatic(): void
    {
        self::assertSame('', PaymentController::normalizePaymentMethodTypes('!!!'));
    }

    public function testCatalogLabelIsUsedForKnownMethod(): void
    {
        self::assertSame('iDEAL', PaymentController::stripeMethodLabel('ideal', ['ideal' => 'iDEAL']));
    }

    public function testUnknownMethodFallsBackToHumanisedLabel(): void
    {
        self::assertSame('Us Bank Account', PaymentController::stripeMethodLabel('us_bank_account'));
        self::assertSame('Blik', PaymentController::stripeMethodLabel('blik', ['card' => 'Card']));
    }

    public function testSaveUncheckedSandboxPersistsZero(): void
    {
        $savedSettings = [];
        $settingsClass = \ValeApp\Infrastructure\Persistence\DbalSettingsRepository::class;
        $settings      = $this->createStub($settingsClass);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = '') => $savedSettings[$key] ?? $default
        );
        $settings->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$savedSettings): void {
                $savedSettings[$key] = (string) $value;
            }
        );

        $twig    = $this->createStub(\Slim\Views\Twig::class);
        $session = $this->createStub(\ValeApp\Infrastructure\Session\SessionManager::class);

        $controller = new PaymentController($twig, $settings, $session);

        // 1. Save PayPal with sandbox = 1
        $req1 = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $req1->method('getParsedBody')->willReturn([
            'provider'      => 'paypal',
            'client_id'     => 'AX123',
            'client_secret' => 'EX456',
            'sandbox'       => '1',
        ]);
        $controller->save($req1, new \Slim\Psr7\Response());
        self::assertSame('1', $savedSettings['paypal_sandbox']);

        // 2. Save PayPal with unchecked sandbox ('0')
        $req2 = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $req2->method('getParsedBody')->willReturn([
            'provider'      => 'paypal',
            'client_id'     => 'AX123',
            'client_secret' => 'EX456',
            'sandbox'       => '0',
        ]);
        $controller->save($req2, new \Slim\Psr7\Response());
        self::assertSame('0', $savedSettings['paypal_sandbox']);

        // 3. Save Mollie with unchecked sandbox (key omitted)
        $req3 = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $req3->method('getParsedBody')->willReturn([
            'provider' => 'mollie',
            'api_key'  => 'live_abc123',
            'label'    => 'Credit Card',
        ]);
        $controller->save($req3, new \Slim\Psr7\Response());
        self::assertSame('0', $savedSettings['mollie_sandbox']);

        // 4. Save Checkout.com with unchecked sandbox (key omitted)
        $req4 = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $req4->method('getParsedBody')->willReturn([
            'provider'   => 'checkoutcom',
            'secret_key' => 'sk_live_abc123',
            'label'      => 'Card',
        ]);
        $controller->save($req4, new \Slim\Psr7\Response());
        self::assertSame('0', $savedSettings['checkoutcom_sandbox']);
    }

    public function testSaveRecordsOpenCardForTouchedProvider(): void
    {
        $settings = $this->createStub(\ValeApp\Infrastructure\Persistence\DbalSettingsRepository::class);
        $settings->method('get')->willReturn('');
        $twig = $this->createStub(\Slim\Views\Twig::class);

        $opened  = [];
        $session = $this->createStub(\ValeApp\Infrastructure\Session\SessionManager::class);
        $session->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$opened): void {
                $opened[$key] = $value;
            }
        );

        $controller = new PaymentController($twig, $settings, $session);

        $req = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $req->method('getParsedBody')->willReturn([
            'provider'      => 'paypal',
            'client_id'     => 'AX123',
            'client_secret' => 'EX456',
            'sandbox'       => '1',
        ]);
        $controller->save($req, new \Slim\Psr7\Response());

        self::assertSame('paypal', $opened['payment_open_card'] ?? null);
    }

    public function testToggleRecordsOpenCardForTouchedProvider(): void
    {
        $settings = $this->createStub(\ValeApp\Infrastructure\Persistence\DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = '') => $key === 'mollie_tested_ok' ? '1' : $default
        );
        $twig = $this->createStub(\Slim\Views\Twig::class);

        $opened  = [];
        $session = $this->createStub(\ValeApp\Infrastructure\Session\SessionManager::class);
        $session->method('set')->willReturnCallback(
            static function (string $key, mixed $value) use (&$opened): void {
                $opened[$key] = $value;
            }
        );

        $controller = new PaymentController($twig, $settings, $session);

        $req = $this->createStub(\Psr\Http\Message\ServerRequestInterface::class);
        $req->method('getParsedBody')->willReturn([
            'provider' => 'mollie',
            'enabled'  => '1',
        ]);
        $controller->toggle($req, new \Slim\Psr7\Response());

        self::assertSame('mollie', $opened['payment_open_card'] ?? null);
    }
}
