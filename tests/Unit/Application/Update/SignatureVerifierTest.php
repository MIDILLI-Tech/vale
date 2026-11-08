<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Application\Update;

use PHPUnit\Framework\TestCase;
use ValeApp\Application\Update\SignatureVerifier;

final class SignatureVerifierTest extends TestCase
{
    private const SHA256 = 'a3bdf591ab6061a5f71fc56e100ccb6c2abaaf1c3015adcf0c032abe033c4a06';

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'version' => '1.2.3',
            'sha256'  => self::SHA256,
        ];
    }

    /**
     * @return array{0: string, 1: string} base64 public key, base64 secret key
     */
    private function ed25519Keypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            base64_encode(sodium_crypto_sign_publickey($keypair)),
            base64_encode(sodium_crypto_sign_secretkey($keypair)),
        ];
    }

    /**
     * @param  array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function sign(string $secretKeyB64, array $manifest): array
    {
        $message = (string) ($manifest['signature_message'] ?? 'sha256:' . $manifest['sha256']);

        $manifest['signature_message'] = $message;
        $manifest['signatures']        = [
            'ed25519' => base64_encode(
                sodium_crypto_sign_detached($message, (string) base64_decode($secretKeyB64, true))
            ),
        ];

        return $manifest;
    }

    public function testValidEd25519SignatureIsAccepted(): void
    {
        [$publicKey, $secretKey] = $this->ed25519Keypair();

        $verifier = new SignatureVerifier($publicKey, '', true);

        self::assertSame('ed25519', $verifier->verify($this->sign($secretKey, $this->manifest())));
    }

    public function testExplicitSignatureMessageIsHonoured(): void
    {
        [$publicKey, $secretKey] = $this->ed25519Keypair();

        $manifest                      = $this->manifest();
        $manifest['signature_message'] = 'vale-release-1.2.3';

        $verifier = new SignatureVerifier($publicKey, '', true);

        self::assertSame('ed25519', $verifier->verify($this->sign($secretKey, $manifest)));
    }

    public function testSignatureFromAnotherKeyIsRejected(): void
    {
        [$publicKey]             = $this->ed25519Keypair();
        [, $otherSecretKey]      = $this->ed25519Keypair();

        $verifier = new SignatureVerifier($publicKey, '', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature verification failed');

        $verifier->verify($this->sign($otherSecretKey, $this->manifest()));
    }

    public function testUnsignedManifestIsRejectedWhenSignatureRequired(): void
    {
        $verifier = new SignatureVerifier('', '', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Update manifest is not signed');

        $verifier->verify($this->manifest());
    }

    public function testUnsignedManifestIsAcceptedWhenSignatureNotRequired(): void
    {
        $verifier = new SignatureVerifier('', '', false);

        self::assertSame('none', $verifier->verify($this->manifest()));
    }

    public function testSignedManifestWithoutKeyIsRejectedWhenSignatureRequired(): void
    {
        [, $secretKey] = $this->ed25519Keypair();

        $verifier = new SignatureVerifier('', '', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No update public key configured');

        $verifier->verify($this->sign($secretKey, $this->manifest()));
    }

    /**
     * Turning the requirement off must also cover a manifest that happens to carry
     * a signature: there is no key to check it against, and refusing to install is
     * not what an operator who disabled signature checks asked for.
     */
    public function testSignedManifestWithoutKeyIsAcceptedWhenSignatureNotRequired(): void
    {
        [, $secretKey] = $this->ed25519Keypair();

        $verifier = new SignatureVerifier('', '', false);

        self::assertSame('none', $verifier->verify($this->sign($secretKey, $this->manifest())));
    }

    public function testIsSignatureRequiredReflectsConstructor(): void
    {
        self::assertTrue((new SignatureVerifier('', '', true))->isSignatureRequired());
        self::assertFalse((new SignatureVerifier('', '', false))->isSignatureRequired());
    }
}
