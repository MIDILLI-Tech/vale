#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate an Ed25519 keypair for signing Vale release manifests.
 *
 * Usage:
 *   php scripts/generate-keypair.php
 *
 * Output:
 *   Private key (keep secret, use to sign releases) → UPDATE_PRIVATE_KEY_ED25519
 *   Public key  (put in .env on each installation)  → UPDATE_PUBLIC_KEY_ED25519
 *
 * Sign a manifest hash:
 *   php scripts/sign-release.php <sha256> <private_key_b64>
 */

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "Error: the sodium extension is not loaded.\n");
    exit(1);
}

$keypair    = sodium_crypto_sign_keypair();
$secretKey  = sodium_crypto_sign_secretkey($keypair);
$publicKey  = sodium_crypto_sign_publickey($keypair);

$secretB64 = base64_encode($secretKey);
$publicB64 = base64_encode($publicKey);

echo "Ed25519 Keypair generated\n";
echo "=========================\n\n";
echo "PRIVATE KEY (keep secret — never commit or share):\n";
echo "UPDATE_PRIVATE_KEY_ED25519={$secretB64}\n\n";
echo "PUBLIC KEY (add to .env on every installation):\n";
echo "UPDATE_PUBLIC_KEY_ED25519={$publicB64}\n\n";
echo "To sign a release SHA-256:\n";
echo "  php scripts/sign-release.php ed25519 <sha256_hex> <private_key_b64>\n";
