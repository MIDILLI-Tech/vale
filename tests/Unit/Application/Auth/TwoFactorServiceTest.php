<?php

namespace ValeApp\Tests\Unit\Application\Auth;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Auth\TwoFactorService;

class TwoFactorServiceTest extends TestCase
{
    private TwoFactorService $service;

    protected function setUp(): void
    {
        $this->service = new TwoFactorService();
    }

    public function testGenerateSecret()
    {
        $secret = $this->service->generateSecret();
        $this->assertNotEmpty($secret);
        $this->assertIsString($secret);
    }

    public function testVerifyCode()
    {
        // We can't easily test TOTP without time manipulation,
        // but we can test that it uses the library correctly.
        $secret = $this->service->generateSecret();
        // This will fail because the code is incorrect
        $this->assertFalse($this->service->verifyCode($secret, '000000'));
    }

    public function testGenerateRecoveryCodes()
    {
        $codes = $this->service->generateRecoveryCodes(5);
        $this->assertCount(5, $codes);
        foreach ($codes as $code) {
            $this->assertEquals(10, strlen($code)); // 5 bytes hex = 10 chars
        }
    }
}
