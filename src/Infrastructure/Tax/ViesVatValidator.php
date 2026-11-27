<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Tax;

use Psr\SimpleCache\CacheInterface;
use ValeApp\Domain\Shop\Port\VatNumberValidatorInterface;
use ValeApp\Domain\Shop\ValueObject\VatNumber;
use ValeApp\Domain\Shop\ValueObject\VatValidation;
use ValeApp\Infrastructure\Http\SafeRemoteFetcher;

/**
 * Validates EU VAT numbers against the European Commission VIES service.
 *
 * Valid results are cached for a day, invalid ones for an hour. A timeout or
 * service outage returns "unavailable" so checkout can fall back to charging
 * VAT instead of silently granting an exemption.
 */
final class ViesVatValidator implements VatNumberValidatorInterface
{
    public const DEFAULT_ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/%s/vat/%s';

    public function __construct(
        private readonly SafeRemoteFetcher $fetcher,
        private readonly CacheInterface $cache,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
    }

    public function validate(VatNumber $vatNumber): VatValidation
    {
        if (!$vatNumber->isEu()) {
            return VatValidation::invalid();
        }

        $key    = 'vies_' . $vatNumber->formatted();
        $cached = $this->cache->get($key);
        if (is_array($cached) && isset($cached['status'])) {
            return match ($cached['status']) {
                VatValidation::VALID   => VatValidation::valid($cached['trader_name'] ?? null),
                VatValidation::INVALID => VatValidation::invalid(),
                default                => VatValidation::unavailable(),
            };
        }

        try {
            $url  = sprintf(
                $this->endpoint !== '' ? $this->endpoint : self::DEFAULT_ENDPOINT,
                rawurlencode($vatNumber->country()),
                rawurlencode($vatNumber->number()),
            );
            $response = $this->fetcher->open($url, 'Vale CMS VAT validation', 8);
            $body     = stream_get_contents($response['stream']);
            if (is_resource($response['stream'])) {
                fclose($response['stream']);
            }

            $data = is_string($body) ? json_decode($body, true) : null;
            if (!is_array($data) || !array_key_exists('isValid', $data)) {
                return VatValidation::unavailable();
            }

            if ((bool) $data['isValid'] === true) {
                $result = VatValidation::valid(isset($data['name']) ? trim((string) $data['name']) : null);
            } else {
                $result = VatValidation::invalid();
            }

            $this->cache->set(
                $key,
                ['status' => $result->status(), 'trader_name' => $result->traderName()],
                $result->isValid() ? 86400 : 3600,
            );

            return $result;
        } catch (\Throwable) {
            return VatValidation::unavailable();
        }
    }
}
