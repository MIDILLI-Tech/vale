<?php

declare(strict_types=1);

namespace ValeApp\Application\Update;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Verifies that an update manifest was signed by the Vale release key.
 *
 * At least one valid signature is required. Ed25519 (libsodium) is tried first;
 * GPG is attempted as a fallback when the gnupg extension or gpg binary is present.
 *
 * The message that was signed is:
 *   "sha256:{manifest['sha256']}"   (or the explicit `signature_message` field)
 *
 * When signatures are required, manifests without signatures are rejected, and so
 * are signed manifests when no public key is configured. When signatures are not
 * required, an unverifiable manifest is accepted and reported as `none`.
 * Verification succeeds when at least one configured method validates (Ed25519 or GPG).
 */
final class SignatureVerifier
{
    public function __construct(
        private readonly string $ed25519PublicKeyB64 = '',
        private readonly string $gpgPublicKeyArmored = '',
        private readonly bool $requireSignature = true,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Verify the manifest signatures.
     *
     * @param  array<string,mixed> $manifest
     * @return string Verification method used: "ed25519" or "gpg".
     * @throws \RuntimeException when signatures are present but none is valid.
     */
    public function verify(array $manifest): string
    {
        $signatures = $manifest['signatures'] ?? [];
        if (empty($signatures)) {
            if ($this->requireSignature) {
                throw new \RuntimeException(
                    'Update manifest is not signed. This installation requires a valid Ed25519 or GPG signature.'
                );
            }
            return 'none';
        }

        $message = $manifest['signature_message']
            ?? 'sha256:' . ($manifest['sha256'] ?? '');

        if (!$this->hasAnyConfiguredPublicKey()) {
            if ($this->requireSignature) {
                throw new \RuntimeException(
                    'No update public key configured. Set UPDATE_PUBLIC_KEY_ED25519 or UPDATE_PUBLIC_KEY_GPG in .env.'
                );
            }

            // Signature checks are optional and nothing is configured to verify
            // against, so let the manifest through unverified rather than blocking
            // an operator who deliberately turned the requirement off.
            $this->logger->warning(
                '[Update] Manifest is signed but no update public key is configured — signature not checked'
            );

            return 'none';
        }

        // ── Ed25519 (always available via libsodium in PHP 7.2+) ─────────
        $ed25519Ok = $this->verifyEd25519($message, (string) ($signatures['ed25519'] ?? ''));

        if ($ed25519Ok) {
            $this->logger->info('[Update] Ed25519 signature OK');
            return 'ed25519';
        }

        // ── GPG fallback ─────────────────────────────────────────────────
        if (!empty($signatures['gpg'])) {
            $gpgOk = $this->verifyGpg($message, (string) $signatures['gpg']);
            if ($gpgOk) {
                $this->logger->info('[Update] GPG signature OK');
                return 'gpg';
            }
        }

        throw new \RuntimeException(
            'Update signature verification failed. The manifest signature does not match the configured public key.'
        );
    }

    public function isSignatureRequired(): bool
    {
        return $this->requireSignature;
    }

    // ── Ed25519 ───────────────────────────────────────────────────────────

    private function verifyEd25519(string $message, string $signatureB64): bool
    {
        if ($signatureB64 === '' || $this->ed25519PublicKeyB64 === '') {
            return false;
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $this->logger->warning('[Update] sodium extension unavailable — Ed25519 check skipped');
            return false;
        }

        try {
            $sig = base64_decode($signatureB64, true);
            $key = base64_decode($this->ed25519PublicKeyB64, true);

            if ($sig === false || $key === false) {
                $this->logger->warning('[Update] Ed25519: base64 decode failed');
                return false;
            }

            $valid = sodium_crypto_sign_verify_detached($sig, $message, $key);
            if (!$valid) {
                $this->logger->warning('[Update] Ed25519 signature INVALID');
            }
            return $valid;
        } catch (\Throwable $e) {
            $this->logger->warning('[Update] Ed25519 verify error: ' . $e->getMessage());
            return false;
        }
    }

    // ── GPG ───────────────────────────────────────────────────────────────

    private function verifyGpg(string $message, string $signatureB64): bool
    {
        if ($this->gpgPublicKeyArmored === '') {
            return false;
        }

        $sig = base64_decode($signatureB64, true);
        if ($sig === false) {
            return false;
        }

        // Path 1: gnupg PHP extension
        if (extension_loaded('gnupg')) {
            return $this->verifyGpgExtension($message, $sig);
        }

        // Path 2: gpg binary in PATH
        if ($this->gpgBinaryExists()) {
            return $this->verifyGpgBinary($message, $sig);
        }

        $this->logger->warning('[Update] GPG: neither gnupg extension nor gpg binary available — GPG check skipped');
        return false;
    }

    private function verifyGpgExtension(string $message, string $signature): bool
    {
        try {
            $gpg = new \gnupg();
            $gpg->seterrormode(\gnupg::ERROR_EXCEPTION);

            // Write key to a temp file and import
            $keyFile = tempnam(sys_get_temp_dir(), 'lpgpg');
            if ($keyFile === false) {
                return false;
            }
            file_put_contents($keyFile, $this->gpgPublicKeyArmored);
            $gpg->import(file_get_contents($keyFile));
            unlink($keyFile);

            $sigFile = tempnam(sys_get_temp_dir(), 'lpsig');
            if ($sigFile === false) {
                return false;
            }
            file_put_contents($sigFile, $signature);

            $info = $gpg->verify($message, false, $sigFile);
            unlink($sigFile);

            if (!is_array($info) || empty($info)) {
                $this->logger->warning('[Update] GPG extension: no verification info returned');
                return false;
            }

            $valid = (bool) ($info[0]['validity'] ?? false);
            if (!$valid) {
                $this->logger->warning('[Update] GPG extension signature INVALID');
            }
            return $valid;
        } catch (\Throwable $e) {
            $this->logger->warning('[Update] GPG extension error: ' . $e->getMessage());
            return false;
        }
    }

    private function verifyGpgBinary(string $message, string $signature): bool
    {
        $homeDir = sys_get_temp_dir() . '/vl-gpg-' . uniqid();
        @mkdir($homeDir, 0700, true);

        try {
            // Import public key
            $keyFile = $homeDir . '/pubkey.asc';
            file_put_contents($keyFile, $this->gpgPublicKeyArmored);
            exec(escapeshellcmd("gpg --homedir " . escapeshellarg($homeDir) . " --batch --import " . escapeshellarg($keyFile)) . " 2>/dev/null");

            // Write message and signature to temp files
            $msgFile = $homeDir . '/message.txt';
            $sigFile = $homeDir . '/message.txt.sig';
            file_put_contents($msgFile, $message);
            file_put_contents($sigFile, $signature);

            // Verify
            $cmd = "gpg --homedir " . escapeshellarg($homeDir) . " --batch --verify " . escapeshellarg($sigFile) . " " . escapeshellarg($msgFile) . " 2>/dev/null";
            exec($cmd, $out, $exitCode);

            $valid = ($exitCode === 0);
            if (!$valid) {
                $this->logger->warning('[Update] GPG binary signature INVALID');
            }
            return $valid;
        } catch (\Throwable $e) {
            $this->logger->warning('[Update] GPG binary error: ' . $e->getMessage());
            return false;
        } finally {
            // Cleanup temp gpg homedir
            if (is_dir($homeDir)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($homeDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($homeDir);
            }
        }
    }

    private function gpgBinaryExists(): bool
    {
        static $result = null;
        if ($result !== null) {
            return $result;
        }
        exec('which gpg 2>/dev/null', $out, $exit);
        if ($exit !== 0) {
            exec('where gpg 2>NUL', $out, $exit); // Windows
        }
        return $result = ($exit === 0);
    }

    private function hasAnyConfiguredPublicKey(): bool
    {
        return $this->ed25519PublicKeyB64 !== '' || $this->gpgPublicKeyArmored !== '';
    }
}
