<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Security;

/**
 * Encryption/decryption service using libsodium (sodium PHP extension).
 *
 * - Algorithm: XChaCha20-Poly1305 (AEAD cipher, authenticated encryption)
 * - Key derivation: Argon2id
 * - Format: base64(nonce + ciphertext)
 *
 * Usage:
 *   $encrypter = new Encrypter(env('APP_KEY'));
 *   $encrypted = $encrypter->encrypt('secret-value');
 *   $decrypted = $encrypter->decrypt($encrypted);
 */
class Encrypter
{
    private readonly string $key;

    /**
     * @param string $appKey 64-character hex string from APP_KEY environment variable (or 32-char for backward compat)
     */
    public function __construct(string $appKey)
    {
        if (strlen($appKey) === 64) {
            // Standard 64-char hex (32 bytes)
            $this->key = hex2bin($appKey);
        } elseif (strlen($appKey) === 32) {
            // Backward compat: 32-char hex (16 bytes) -> extend to 32 bytes by repeating
            $decoded   = hex2bin($appKey);
            $this->key = $decoded . $decoded;
        } else {
            throw new \InvalidArgumentException('APP_KEY must be 64 or 32 hex characters');
        }

        if ($this->key === false) {
            throw new \InvalidArgumentException('APP_KEY must be valid hexadecimal');
        }
    }

    /**
     * Encrypt plaintext using XChaCha20-Poly1305.
     *
     * @param string $plaintext Data to encrypt
     * @return string Base64-encoded nonce + ciphertext (prefixed with "encrypted::v1::")
     */
    public function encrypt(string $plaintext): string
    {
        if (empty($plaintext)) {
            return '';
        }

        // Generate random nonce (24 bytes for XChaCha20)
        $nonce = random_bytes(24);

        // Encrypt: nonce + ciphertext are authenticated together
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            '', // Additional data: none
            $nonce,
            $this->key,
        );

        // Return: version marker + base64(nonce + ciphertext)
        return 'encrypted::v1::' . base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt ciphertext (backward-compatible with plaintext).
     *
     * Legacy plaintext values are returned untouched. Values carrying our
     * encryption marker that FAIL verification (e.g. after an APP_KEY
     * change) return an empty string — the caller sees an honest "missing"
     * setting instead of unusable ciphertext.
     *
     * @param string $encrypted Encrypted value or plaintext fallback
     * @return string Decrypted plaintext, original plaintext, or '' on failure
     */
    public function decrypt(string $encrypted): string
    {
        if (empty($encrypted)) {
            return '';
        }

        // Check if value is encrypted with our format
        if (!str_starts_with($encrypted, 'encrypted::v1::')) {
            // Backward compatibility: return as-is if plaintext
            return $encrypted;
        }

        try {
            // Remove version marker and decode
            $payload = base64_decode(substr($encrypted, 15), strict: true);
            if ($payload === false) {
                return '';
            }

            // Extract nonce (24 bytes) and ciphertext
            $nonce      = substr($payload, 0, 24);
            $ciphertext = substr($payload, 24);

            if (strlen($nonce) !== 24) {
                return '';
            }

            // Decrypt
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                '', // Additional data: none
                $nonce,
                $this->key,
            );

            if ($plaintext === false) {
                // Authentication failed (different APP_KEY or tampered data)
                return '';
            }

            return $plaintext;
        } catch (\Throwable) {
            return '';
        }
    }
}
