<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Payment;

/**
 * Resolves payment provider API base URLs from the `payment_api`
 * catalog in config/reference-data.php.
 */
final class PaymentApiUrls
{
    /** @var array<string, array<string, string>> */
    private array $catalog;

    private static ?self $fileInstance = null;

    /** @param array<string, mixed> $catalog */
    public function __construct(array $catalog = [])
    {
        $this->catalog = is_array($catalog) ? $catalog : [];
    }

    public static function fromFile(): self
    {
        if (self::$fileInstance === null) {
            $data = require dirname(__DIR__, 3) . '/config/reference-data.php';

            self::$fileInstance = new self(is_array($data['payment_api'] ?? null) ? $data['payment_api'] : []);
        }

        return self::$fileInstance;
    }

    public function stripeBase(): string
    {
        return $this->require('stripe', 'api_base', 'stripeBase()');
    }

    public function paypalBase(bool $sandbox): string
    {
        return $this->require('paypal', $sandbox ? 'api_base_sandbox' : 'api_base_live', 'paypalBase()');
    }

    public function mollieBase(): string
    {
        return $this->require('mollie', 'api_base', 'mollieBase()');
    }

    public function checkoutComBase(bool $sandbox): string
    {
        return $this->require('checkoutcom', $sandbox ? 'api_base_sandbox' : 'api_base_live', 'checkoutComBase()');
    }

    public function twoCheckoutApiBase(): string
    {
        return $this->require('twocheckout', 'api_base', 'twoCheckoutApiBase()');
    }

    public function twoCheckoutUrl(): string
    {
        return $this->require('twocheckout', 'checkout_url', 'twoCheckoutUrl()');
    }

    /**
     * Reads a payment API base URL from the catalog. A missing entry is a
     * configuration error — we fail fast instead of silently falling back to a
     * hardcoded default, so reference-data.php stays the single source of truth.
     */
    private function require(string $provider, string $key, string $caller): string
    {
        $url = $this->catalog[$provider][$key] ?? '';

        if (!is_string($url) || $url === '') {
            throw new \RuntimeException(
                "Missing payment API URL for '{$provider}.{$key}' in config/reference-data.php (payment_api) — called from {$caller}."
            );
        }

        return $url;
    }
}
