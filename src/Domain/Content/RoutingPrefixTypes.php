<?php

declare(strict_types=1);

namespace ValeApp\Domain\Content;

/**
 * Single source of truth for configurable routing prefix types.
 *
 * CONTENT_TYPES are rewritten by site_url() for localized URLs; AUTH_TYPES
 * are resolved generically via locale-stripping in LocaleMiddleware.
 */
final class RoutingPrefixTypes
{
    public const array CONTENT_TYPES = [
        'blog', 'category', 'tag', 'shop', 'cart', 'checkout', 'membership', 'account', 'page',
    ];

    public const array AUTH_TYPES = [
        'login', 'register', 'forgot_password', 'reset_password', 'verify_email', 'logout',
    ];

    /** @var list<string> */
    public const array ALL_TYPES = [...self::CONTENT_TYPES, ...self::AUTH_TYPES];

    /**
     * Default public path per type. Must mirror the fallback defaults in
     * routes/web.php — RoutingValidator falls back to these when a setting
     * was never saved (notably the hyphenated auth routes).
     *
     * @var array<string, string>
     */
    public const array DEFAULT_PREFIXES = [
        'blog'            => '/blog', 'category' => '/category', 'tag' => '/tag',
        'shop'            => '/shop', 'cart' => '/cart', 'checkout' => '/checkout',
        'membership'      => '/membership', 'account' => '/account', 'page' => '',
        'login'           => '/login', 'register' => '/register',
        'forgot_password' => '/forgot-password', 'reset_password' => '/reset-password',
        'verify_email'    => '/verify-email', 'logout' => '/logout',
    ];

    public static function settingKey(string $type): string
    {
        return 'routing_' . $type . '_prefix';
    }
}
