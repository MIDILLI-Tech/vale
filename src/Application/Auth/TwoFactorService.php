<?php

declare(strict_types=1);

namespace ValeApp\Application\Auth;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use OTPHP\TOTP;

final class TwoFactorService
{
    public function generateSecret(): string
    {
        return TOTP::create()->getSecret();
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return $this->verifyCodeSlice($secret, $code) !== null;
    }

    /**
     * Verify a TOTP code (±15 s leeway) and return the consumed timeslice
     * for replay protection, or null when the code is invalid. Callers must
     * reject codes whose timeslice is not strictly newer than the last
     * consumed one.
     */
    public function verifyCodeSlice(string $secret, string $code): ?int
    {
        if ($secret === '' || $code === '') {
            return null;
        }

        $totp   = TOTP::createFromSecret($secret);
        $period = max(1, $totp->getPeriod());

        for ($offset = -$period; $offset <= $period; $offset++) {
            $ts = time() + $offset;
            if (hash_equals($totp->at($ts), $code)) {
                return intdiv($ts, $period);
            }
        }

        return null;
    }

    public function getQrCodeUri(string $secret, string $label, string $issuer): string
    {
        $totp = TOTP::createFromSecret($secret);
        $totp->setLabel($label);
        $totp->setIssuer($issuer);

        return $totp->getProvisioningUri();
    }

    public function generateQrCodeBase64(string $uri): string
    {
        $options = new QROptions([
            'outputInterface'  => QRMarkupSVG::class,
            'eccLevel'         => EccLevel::L,
            'outputBase64'     => true,
            'svgAddXmlHeader'  => false,
        ]);

        return (new QRCode($options))->render($uri);
    }

    /**
     * @return string[]
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = bin2hex(random_bytes(5));
        }
        return $codes;
    }
}
