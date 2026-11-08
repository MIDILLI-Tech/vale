<?php

namespace ValeApp\Tests\Unit\Infrastructure\Security;

use PHPUnit\Framework\TestCase;
use ValeApp\Infrastructure\Security\Encrypter;

class EncrypterTest extends TestCase
{
    private Encrypter $encrypter;

    protected function setUp(): void
    {
        // 32 bytes hex = 64 chars
        $appKey          = bin2hex(random_bytes(32));
        $this->encrypter = new Encrypter($appKey);
    }

    public function testEncryptDecrypt()
    {
        $original  = "Secret Data";
        $encrypted = $this->encrypter->encrypt($original);

        $this->assertNotEquals($original, $encrypted);
        $this->assertStringStartsWith('encrypted::v1::', $encrypted);

        $decrypted = $this->encrypter->decrypt($encrypted);
        $this->assertEquals($original, $decrypted);
    }

    public function testDecryptPlaintextFallback()
    {
        $plain     = "Already Plain";
        $decrypted = $this->encrypter->decrypt($plain);
        $this->assertEquals($plain, $decrypted);
    }
}
