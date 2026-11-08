<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\ExchangeRate;

use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Fetches, caches and converts exchange rates.
 *
 * Providers are defined in config/reference-data.php (services.exchange_rate).
 *   frankfurter       — free, no key, blended daily rates (220+ currencies, no quotas)
 *   openexchangerates — free tier, API key, 1,000 req/month (USD base on free)
 *   exchangerateapi   — free tier, API key, 1,500 req/month, any base
 */
final class ExchangeRateService
{
    private const CACHE_KEY    = 'fx_rates_cache';
    private const CACHE_TS_KEY = 'fx_rates_updated_at';
    private const CACHE_BASE   = 'fx_rates_base';

    /** @var array<int, array{value?: string, api_url_template?: string}>|null */
    private ?array $providerCatalog = null;

    public function __construct(
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    /**
     * Convert $amount from $from currency to $to currency.
     * Returns original amount unchanged when no rates are cached or provider is 'none'.
     */
    public function convert(float $amount, string $from, string $to): float
    {
        $rate = $this->getRate($from, $to);
        return round($amount * $rate, 2);
    }

    /**
     * Returns the multiplier to convert 1 unit of $from into $to.
     * Returns 1.0 when conversion is not possible.
     */
    public function getRate(string $from, string $to): float
    {
        $from = strtoupper($from);
        $to   = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $provider = $this->settings->get('fx_provider', 'none') ?? 'none';
        if ($provider === 'none') {
            return 1.0;
        }

        $rates     = $this->getCachedRates();
        $cacheBase = strtoupper($this->settings->get(self::CACHE_BASE, '') ?? '');

        if (empty($rates) || $cacheBase === '') {
            return 1.0;
        }

        // Rates are stored as: [currencyCode => rateRelativeToBase]
        // e.g. base=EUR: ['USD'=>1.08, 'TRY'=>35.2, 'EUR'=>1.0]
        $rateFrom = $from === $cacheBase ? 1.0 : (float) ($rates[$from] ?? 0.0);
        $rateTo   = $to   === $cacheBase ? 1.0 : (float) ($rates[$to]   ?? 0.0);

        if ($rateFrom <= 0.0 || $rateTo <= 0.0) {
            return 1.0;
        }

        // Cross-rate: from->base->to
        return round($rateTo / $rateFrom, 8);
    }

    /**
     * Fetch fresh rates from the configured provider and store in DB.
     *
     * @param array<string, mixed> $overrides Optional unsaved form values
     *        (fx_provider, fx_api_key, fx_cache_hours, currency). When present
     *        they are persisted first so the refresh works without an explicit
     *        "Save" click.
     *
     * @throws \RuntimeException on any failure
     */
    public function fetchAndCache(array $overrides = []): void
    {
        // Persist any unsaved form values first so "Refresh Rates Now" works
        // without requiring a separate Save click.
        if (isset($overrides['fx_provider'])) {
            $provider = trim((string) $overrides['fx_provider']);
            if (in_array($provider, ['none', 'frankfurter', 'openexchangerates', 'exchangerateapi'], true)) {
                $this->settings->set('fx_provider', $provider, 'ecommerce');
            }
        }
        if (isset($overrides['fx_api_key']) && trim((string) $overrides['fx_api_key']) !== '') {
            $this->settings->set('fx_api_key', trim((string) $overrides['fx_api_key']), 'ecommerce');
        }
        if (isset($overrides['fx_cache_hours'])) {
            $hours = (int) $overrides['fx_cache_hours'];
            if ($hours >= 1 && $hours <= 168) {
                $this->settings->set('fx_cache_hours', (string) $hours, 'ecommerce');
            }
        }
        if (isset($overrides['currency'])) {
            $currency = strtoupper(trim((string) $overrides['currency']));
            if ($currency !== '' && strlen($currency) === 3) {
                $this->settings->set('currency', $currency, 'ecommerce');
            }
        }

        $provider     = $this->settings->get('fx_provider', 'none') ?? 'none';
        $baseCurrency = strtoupper($this->settings->get('currency', 'USD') ?? 'USD');

        $rates = match ($provider) {
            'frankfurter'       => $this->fetchFrankfurter($baseCurrency),
            'openexchangerates' => $this->fetchOpenExchangeRates($baseCurrency),
            'exchangerateapi'   => $this->fetchExchangeRateApi($baseCurrency),
            default             => throw new \RuntimeException('No exchange rate provider configured.'),
        };

        // Ensure base always has rate 1.0
        $rates[$baseCurrency] = 1.0;

        $this->settings->set(self::CACHE_KEY, (string) json_encode($rates), 'ecommerce');
        $this->settings->set(self::CACHE_TS_KEY, (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'ecommerce');
        $this->settings->set(self::CACHE_BASE, $baseCurrency, 'ecommerce');
    }

    /** @return array<string, float> */
    public function getCachedRates(): array
    {
        $json = $this->settings->get(self::CACHE_KEY, '') ?? '';
        if ($json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getUpdatedAt(): ?string
    {
        $val = $this->settings->get(self::CACHE_TS_KEY);
        return ($val !== null && $val !== '') ? $val : null;
    }

    public function getBaseCurrency(): string
    {
        $base = strtoupper((string) ($this->settings->get(self::CACHE_BASE, '') ?? ''));
        if ($base !== '') {
            return $base;
        }
        return strtoupper((string) ($this->settings->get('currency', 'USD') ?? 'USD'));
    }

    public function isCacheStale(): bool
    {
        $ts = $this->getUpdatedAt();
        if ($ts === null) {
            return true;
        }
        $ttlHours  = max(1, (int) ($this->settings->get('fx_cache_hours', '24') ?? 24));
        $updatedAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ts);
        if ($updatedAt === false) {
            return true;
        }
        return new \DateTimeImmutable() > $updatedAt->modify("+{$ttlHours} hours");
    }

    /**
     * API URL template for a provider from the services.exchange_rate catalog.
     * {base} and {key} placeholders are substituted by the caller.
     *
     * @throws \RuntimeException when the catalog has no template for the provider
     */
    private function apiTemplate(string $provider): string
    {
        if ($this->providerCatalog === null) {
            $data                   = require dirname(__DIR__, 3) . '/config/reference-data.php';
            $list                   = is_array($data['services']['exchange_rate'] ?? null) ? $data['services']['exchange_rate'] : [];
            $this->providerCatalog  = $list;
        }

        foreach ($this->providerCatalog as $entry) {
            if (($entry['value'] ?? '') === $provider && !empty($entry['api_url_template'])) {
                return (string) $entry['api_url_template'];
            }
        }

        throw new \RuntimeException(
            "Missing api_url_template for exchange rate provider '{$provider}' in config/reference-data.php (services.exchange_rate)."
        );
    }

    // ── Private providers ─────────────────────────────────────────────

    /**
     * Frankfurter — free, no key, blended daily rates (220+ currencies, no quotas).
     * @return array<string, float>
     */
    private function fetchFrankfurter(string $base): array
    {
        $url  = str_replace('{base}', urlencode($base), $this->apiTemplate('frankfurter'));
        $json = $this->httpGet($url);
        $data = json_decode($json, true);

        if (!isset($data['rates']) || !is_array($data['rates'])) {
            throw new \RuntimeException('Frankfurter.app: unexpected response — ' . substr($json, 0, 200));
        }

        $rates = [];
        foreach ($data['rates'] as $code => $rate) {
            $rates[strtoupper($code)] = (float) $rate;
        }
        return $rates;
    }

    /**
     * Open Exchange Rates — free tier: USD base only, API key required.
     * We cross-rate to any requested base.
     * @return array<string, float>
     */
    private function fetchOpenExchangeRates(string $base): array
    {
        $key = $this->settings->get('fx_api_key', '') ?? '';
        if ($key === '') {
            throw new \RuntimeException('Open Exchange Rates: API key is not configured.');
        }

        $url  = str_replace('{key}', urlencode($key), $this->apiTemplate('openexchangerates'));
        $json = $this->httpGet($url);
        $data = json_decode($json, true);

        if (!isset($data['rates']) || !is_array($data['rates'])) {
            $err = $data['description'] ?? ($data['message'] ?? 'unknown error');
            throw new \RuntimeException('Open Exchange Rates: ' . $err);
        }

        // Response is always USD-based on free plan; cross-rate to $base
        $usdRates = [];
        foreach ($data['rates'] as $code => $rate) {
            $usdRates[strtoupper($code)] = (float) $rate;
        }
        $usdRates['USD'] = 1.0;

        $base = strtoupper($base);
        if ($base === 'USD') {
            return $usdRates;
        }

        $baseInUsd = $usdRates[$base] ?? 0.0;
        if ($baseInUsd <= 0.0) {
            throw new \RuntimeException("Open Exchange Rates: base currency '{$base}' not found in response.");
        }

        $result = [];
        foreach ($usdRates as $code => $rate) {
            $result[$code] = $rate / $baseInUsd;
        }
        return $result;
    }

    /**
     * ExchangeRate-API v6 — free tier, API key, 1500 req/month, any base.
     * @return array<string, float>
     */
    private function fetchExchangeRateApi(string $base): array
    {
        $key = $this->settings->get('fx_api_key', '') ?? '';
        if ($key === '') {
            throw new \RuntimeException('ExchangeRate-API: API key is not configured.');
        }

        $url  = str_replace(['{key}', '{base}'], [urlencode($key), urlencode($base)], $this->apiTemplate('exchangerateapi'));
        $json = $this->httpGet($url);
        $data = json_decode($json, true);

        if (($data['result'] ?? '') !== 'success' || !isset($data['conversion_rates'])) {
            $err = $data['error-type'] ?? 'unknown error';
            throw new \RuntimeException("ExchangeRate-API: {$err}");
        }

        $result = [];
        foreach ($data['conversion_rates'] as $code => $rate) {
            $result[strtoupper($code)] = (float) $rate;
        }
        return $result;
    }

    private function httpGet(string $url): string
    {
        $ctx  = stream_context_create([
            'http' => [
                'timeout'       => 10,
                'ignore_errors' => true,
                'user_agent'    => 'Vale-FX/1.0',
                'method'        => 'GET',
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException("HTTP request failed: {$url}");
        }
        return $body;
    }
}
