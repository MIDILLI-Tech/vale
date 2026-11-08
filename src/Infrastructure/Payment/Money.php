<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Payment;

/**
 * Shared money math for gateways that operate on minor currency units.
 *
 * Decimal helpers (add/sub/mul/div/round2) operate on decimal strings and
 * prefer ext-bcmath when available, falling back to float math otherwise —
 * so bcmath is an optimization, never a hard requirement.
 */
final class Money
{
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG',
        'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public static function isZeroDecimal(string $currency): bool
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true);
    }

    public static function usesBcMath(): bool
    {
        return function_exists('bcadd') && function_exists('bcsub')
                                        && function_exists('bcmul') && function_exists('bcdiv')
                                        && function_exists('bccomp');
    }

    /**
     * Normalize a decimal-ish value to a plain decimal string (no exponent,
     * no thousands separators) so bcmath and float fallback agree on input.
     */
    public static function toDecimalString(float|string $amount): string
    {
        if (is_string($amount)) {
            $s = trim($amount);
        } else {
            // Shortest round-trip representation: 500.6 stays "500.6",
            // avoiding sprintf('%F') dust like "500.5999999999".
            $s = (string) $amount;
            if (is_float($amount) && !is_finite($amount)) {
                return '0';
            }
        }
        if ($s === '' || !is_numeric($s)) {
            return '0';
        }
        // Expand scientific notation (e.g. 1.0E-7) to a plain decimal string.
        if (stripos($s, 'e') !== false) {
            $s = sprintf('%.12F', (float) $s);
        }
        // Strip a leading plus sign; bcmath rejects it.
        if (str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }

        return $s;
    }

    /** Decimal addition, result as string. */
    public static function add(float|string $a, float|string $b, int $scale = 6): string
    {
        $a = self::toDecimalString($a);
        $b = self::toDecimalString($b);
        if (self::usesBcMath()) {
            return bcadd($a, $b, $scale);
        }

        return (string) ((float) $a + (float) $b);
    }

    /** Decimal subtraction, result as string. */
    public static function sub(float|string $a, float|string $b, int $scale = 6): string
    {
        $a = self::toDecimalString($a);
        $b = self::toDecimalString($b);
        if (self::usesBcMath()) {
            return bcsub($a, $b, $scale);
        }

        return (string) ((float) $a - (float) $b);
    }

    /** Decimal multiplication, result as string. */
    public static function mul(float|string $a, float|string $b, int $scale = 6): string
    {
        $a = self::toDecimalString($a);
        $b = self::toDecimalString($b);
        if (self::usesBcMath()) {
            return bcmul($a, $b, $scale);
        }

        return (string) ((float) $a * (float) $b);
    }

    /** Decimal division, result as string ('0' on division by zero). */
    public static function div(float|string $a, float|string $b, int $scale = 8): string
    {
        $a = self::toDecimalString($a);
        $b = self::toDecimalString($b);
        if ((float) $b === 0.0) {
            return '0';
        }
        if (self::usesBcMath()) {
            return bcdiv($a, $b, $scale);
        }

        return (string) ((float) $a / (float) $b);
    }

    /**
     * Round a decimal string to 2 decimals (half away from zero),
     * returned as a string like "12.35".
     */
    public static function round2(float|string $amount): string
    {
        $s = self::toDecimalString($amount);
        if (self::usesBcMath()) {
            // Round half away from zero at cent precision:
            // shift by 100, round to int, shift back. bcadd scale=0
            // truncates toward zero, so add sign-aware 0.5 first.
            $negative = str_starts_with(ltrim($s), '-');
            $abs      = $negative ? substr(ltrim($s), 1) : $s;
            $shifted  = bcmul($abs, '100', 4);
            $cents    = bcadd($shifted, '0.5', 0);
            $sign     = $negative ? '-' : '';
            if (bccomp($cents, '0', 0) === 0) {
                return '0.00';
            }
            $absCents = ltrim($cents, '-');
            $len      = strlen($absCents);
            $int      = $len > 2 ? substr($absCents, 0, $len - 2) : '0';
            $frac     = str_pad($len > 2 ? substr($absCents, $len - 2) : $absCents, 2, '0', STR_PAD_LEFT);

            return $sign . $int . '.' . $frac;
        }

        return number_format(round((float) $s, 2), 2, '.', '');
    }

    /** Convert a decimal string (or float) to float. */
    public static function toFloat(float|string $amount): float
    {
        return (float) self::toDecimalString($amount);
    }

    /** Compare two decimals: -1, 0, 1 (like bccomp). */
    public static function comp(float|string $a, float|string $b, int $scale = 6): int
    {
        $a = self::toDecimalString($a);
        $b = self::toDecimalString($b);
        if (self::usesBcMath()) {
            return bccomp($a, $b, $scale);
        }

        $fa = (float) $a;
        $fb = (float) $b;

        return $fa < $fb ? -1 : ($fa > $fb ? 1 : 0);
    }

    public static function toMinorUnits(float $amount, string $currency): int
    {
        if (self::isZeroDecimal($currency)) {
            // Round to whole units first (bc path), then cast.
            $rounded = self::usesBcMath()
                ? (int) bcadd(bcmul(self::toDecimalString($amount), '1', 4), '0.5', 0)
                : (int) round($amount);

            return $rounded;
        }

        return (int) self::toFloat(self::round2(self::mul(self::round2($amount), '100')));
    }

    /**
     * API string amount: no decimals for zero-decimal currencies (JPY),
     * two decimals otherwise.
     */
    public static function formatForApi(float $amount, string $currency): string
    {
        $amount = max(0.0, $amount);

        if (self::isZeroDecimal($currency)) {
            $rounded = self::usesBcMath()
                ? bcadd(bcmul(self::toDecimalString($amount), '1', 4), '0.5', 0)
                : (string) round($amount);

            return ltrim($rounded, '+');
        }

        return self::round2($amount);
    }
}
