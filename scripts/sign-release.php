#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sign a release manifest message with an Ed25519 private key.
 *
 * Usage:
 *   php scripts/sign-release.php ed25519 <message> <private_key_b64>
 *   php scripts/sign-release.php gpg <message> <key_id_or_email>
 *
 * Examples:
 *   php scripts/sign-release.php ed25519 sha256:abc123... my-base64-key
 *   php scripts/sign-release.php gpg sha256:abc123... release@example.com
 */

if ($argc < 3) {
    printUsage();
    exit(1);
}

$mode = $argv[1];

if ($mode === 'ed25519') {
    if ($argc < 4) {
        printUsage();
        exit(1);
    }
    signEd25519($argv[2], $argv[3]);
} elseif ($mode === 'gpg') {
    if ($argc < 4) {
        printUsage();
        exit(1);
    }
    signGpg($argv[2], $argv[3]);
} else {
    // Legacy support for scripts/sign-release.php <message> <private_key_b64>
    if ($argc === 3 && !in_array($mode, ['ed25519', 'gpg'])) {
        signEd25519($argv[1], $argv[2]);
    } else {
        printUsage();
        exit(1);
    }
}

function signEd25519(string $message, string $secretB64): void
{
    if (!extension_loaded('sodium')) {
        fwrite(STDERR, "Error: the sodium extension is not loaded.\n");
        exit(1);
    }

    $secretKey = base64_decode($secretB64, true);
    if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fwrite(STDERR, "Error: invalid Ed25519 private key (expected " . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . "-byte base64).\n");
        exit(1);
    }

    $signature = sodium_crypto_sign_detached($message, $secretKey);
    $sigB64    = base64_encode($signature);

    echo "Mode      : Ed25519\n";
    echo "Message   : {$message}\n";
    echo "Signature : {$sigB64}\n";
    echo "\nManifest snippet:\n";
    echo json_encode(['signatures' => ['ed25519' => $sigB64], 'signature_message' => $message], JSON_PRETTY_PRINT) . "\n";
}

function signGpg(string $message, string $keyId): void
{
    // Path 1: Check for gpg binary
    $gpg = shell_exec('which gpg 2>/dev/null') ?: shell_exec('where gpg 2>NUL');
    if (!$gpg) {
        fwrite(STDERR, "Error: gpg binary not found in PATH.\n");
        exit(1);
    }

    $msgFile = tempnam(sys_get_temp_dir(), 'vlmsg');
    $sigFile = tempnam(sys_get_temp_dir(), 'vlsig');
    file_put_contents($msgFile, $message);

    $cmd = sprintf(
        "gpg --batch --detach-sign --armor --local-user %s --output %s %s 2>&1",
        escapeshellarg($keyId),
        escapeshellarg($sigFile),
        escapeshellarg($msgFile)
    );

    $output = shell_exec($cmd);
    if (!file_exists($sigFile) || filesize($sigFile) === 0) {
        fwrite(STDERR, "Error: GPG signing failed.\nOutput: $output\n");
        @unlink($msgFile);
        @unlink($sigFile);
        exit(1);
    }

    $signature = file_get_contents($sigFile);
    $sigB64    = base64_encode($signature);

    echo "Mode      : GPG\n";
    echo "Message   : {$message}\n";
    echo "Key ID    : {$keyId}\n";
    echo "Signature : {$sigB64}\n";
    echo "\nManifest snippet:\n";
    echo json_encode(['signatures' => ['gpg' => $sigB64], 'signature_message' => $message], JSON_PRETTY_PRINT) . "\n";

    @unlink($msgFile);
    @unlink($sigFile);
}

function printUsage(): void
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/sign-release.php ed25519 <message> <private_key_b64>\n");
    fwrite(STDERR, "  php scripts/sign-release.php gpg <message> <key_id_or_email>\n");
}
