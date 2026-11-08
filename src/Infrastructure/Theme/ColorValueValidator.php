<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Theme;

/**
 * Validates theme color variable values: hex, rgb()/rgba(), hsl()/hsla(),
 * linear-gradient()/radial-gradient(). Rejects anything that could break out
 * of the injected <style> block (no braces, quotes, semicolons, tags).
 */
final class ColorValueValidator
{
    private const PATTERN = '/^#[0-9a-fA-F]{3,8}$|^(?:rgba?|hsla?)\([0-9a-zA-Z #%.,\/]+\)$|^(?:linear|radial)-gradient\([0-9a-zA-Z #%.,\/()-]+\)$/i';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, trim($value)) === 1;
    }
}
