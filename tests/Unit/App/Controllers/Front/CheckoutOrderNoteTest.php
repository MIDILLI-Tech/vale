<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\App\Controllers\Front;

use PHPUnit\Framework\TestCase;
use ValeApp\App\Controllers\Front\CheckoutController;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class CheckoutOrderNoteTest extends TestCase
{
    public function testOrderNoteIsNotRequiredByDefault(): void
    {
        $settings = $this->createStub(DbalSettingsRepository::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $default
        );

        $reflection = new \ReflectionClass(CheckoutController::class);
        $method     = $reflection->getMethod('checkoutRequiredMap');

        $controller = $reflection->newInstanceWithoutConstructor();
        $prop       = $reflection->getProperty('settings');
        $prop->setValue($controller, $settings);

        $requiredMap = $method->invoke($controller);

        $this->assertArrayHasKey('note', $requiredMap);
        $this->assertFalse($requiredMap['note'], 'Order note should default to optional (false) during checkout.');
    }
}
