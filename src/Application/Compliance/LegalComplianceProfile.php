<?php

declare(strict_types=1);

namespace ValeApp\Application\Compliance;

/**
 * Legal checklist and consent audit for the country a store operates in.
 *
 * All regions, country assignments and audit rules come from the
 * `legal_compliance` reference catalog. The result is advisory: it reports
 * whether a document is configured and which integrations load before the
 * visitor chooses, never that the store is legally compliant.
 */
final class LegalComplianceProfile
{
    public const STATUS_CONFIGURED   = 'configured';
    public const STATUS_PARTIAL      = 'partial';
    public const STATUS_MISSING      = 'missing';
    public const STATUS_UNAVAILABLE  = 'unavailable';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    /** @var array<string, mixed> */
    private array $catalog;

    /**
     * @param array<string, mixed> $catalog
     */
    public function __construct(array $catalog)
    {
        $this->catalog = $catalog;
    }

    public function regionFor(?string $country): string
    {
        $country = strtoupper(trim((string) $country));
        $regions = (array) ($this->catalog['regions'] ?? []);
        $map     = (array) ($this->catalog['countries'] ?? []);

        if ($country !== '' && isset($map[$country], $regions[$map[$country]])) {
            return (string) $map[$country];
        }

        $fallback = (string) ($this->catalog['default_region'] ?? 'other');

        return isset($regions[$fallback]) ? $fallback : (string) (array_key_first($regions) ?? 'other');
    }

    /**
     * @return array<string, array{label: string, consent_model: string, items: list<string>}>
     */
    public function regions(): array
    {
        $regions = [];

        foreach ((array) ($this->catalog['regions'] ?? []) as $key => $region) {
            $region        = (array) $region;
            $regions[$key] = [
                'label'         => (string) ($region['label'] ?? $key),
                'consent_model' => (string) ($region['consent_model'] ?? 'opt-in'),
                'items'         => array_keys((array) ($region['levels'] ?? [])),
            ];
        }

        return $regions;
    }

    /**
     * @param list<string> $countryCodes
     * @return array<string, string>
     */
    public function countryRegions(array $countryCodes): array
    {
        $map = [];

        foreach ($countryCodes as $code) {
            $map[$code] = $this->regionFor($code);
        }

        return $map;
    }

    /**
     * @return array{key: string, label: string, consent_model: string, items: list<string>}
     */
    public function summary(?string $country): array
    {
        $regions = $this->regions();
        $key     = $this->regionFor($country);

        return ['key' => $key] + ($regions[$key] ?? ['label' => '', 'consent_model' => 'opt-in', 'items' => []]);
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<array{key: string, level: string, status: string, acknowledged: bool}>
     */
    public function checklist(?string $country, array $settings): array
    {
        $region = $this->regionFor($country);
        $levels = (array) ($this->catalog['regions'][$region]['levels'] ?? []);
        $acks   = $this->acknowledgements($settings);
        $items  = [];

        foreach ($levels as $key => $level) {
            $definition     = (array) ($this->catalog['items'][$key] ?? []);
            $acknowledgedAt = $acks[(string) $key] ?? null;
            $items[]        = [
                'key'          => (string) $key,
                'level'        => (string) $level,
                'status'       => $this->status($definition, $settings, $acknowledgedAt),
                'acknowledged' => $acknowledgedAt !== null,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<array{id: string, severity: string, message: string, link: string, link_text: string}>
     */
    public function findings(?string $country, array $settings): array
    {
        $findings = [];
        $missing  = [];

        foreach ($this->checklist($country, $settings) as $item) {
            if ($item['level'] !== 'required') {
                continue;
            }
            if (!in_array($item['status'], [self::STATUS_MISSING, self::STATUS_UNAVAILABLE], true)) {
                continue;
            }
            $missing[] = $this->label($item['key']);
        }

        if ($missing !== []) {
            $findings[] = [
                'id'        => 'missing_documents',
                'severity'  => 'warning',
                'message'   => __('Compliance steps missing: :items.', [':items' => implode(', ', $missing)]),
                'link'      => '/admin/settings?tab=ecommerce&subtab=legal',
                'link_text' => __('Legal & Compliance'),
            ];
        }

        $region = $this->regionFor($country);

        foreach ((array) ($this->catalog['audits'] ?? []) as $id => $rule) {
            $rule = (array) $rule;
            if (!in_array($region, (array) ($rule['regions'] ?? []), true)) {
                continue;
            }
            if (!$this->matches((array) ($rule['when'] ?? []), $settings)) {
                continue;
            }

            $findings[] = [
                'id'        => (string) $id,
                'severity'  => (string) ($rule['severity'] ?? 'warning'),
                'message'   => $this->message((string) $id),
                'link'      => (string) ($rule['link'] ?? '/admin/settings'),
                'link_text' => __((string) ($rule['link_text'] ?? 'Settings')),
            ];
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    private function status(array $definition, array $settings, ?string $acknowledgedAt): string
    {
        $acknowledged = $acknowledgedAt !== null && $acknowledgedAt !== '';

        if (($definition['available'] ?? true) === false) {
            return $acknowledged ? self::STATUS_ACKNOWLEDGED : self::STATUS_UNAVAILABLE;
        }

        $setting = (string) ($definition['setting'] ?? '');
        if ($setting !== '' && $this->isOn($settings[$setting] ?? '')) {
            return self::STATUS_CONFIGURED;
        }

        $fallback = (string) ($definition['fallback_setting'] ?? '');
        if ($fallback !== '' && $this->isOn($settings[$fallback] ?? '')) {
            return self::STATUS_PARTIAL;
        }

        return $acknowledged ? self::STATUS_ACKNOWLEDGED : self::STATUS_MISSING;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    private function acknowledgements(array $settings): array
    {
        $raw = $settings['legal_acknowledgements'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $acks = [];
        foreach ($decoded as $key => $date) {
            if (is_string($key) && is_string($date) && $date !== '') {
                $acks[$key] = $date;
            }
        }

        return $acks;
    }

    private function isOn(mixed $value): bool
    {
        $value = trim((string) $value);

        return $value !== '' && $value !== '0';
    }

    /**
     * @param list<array<string, mixed>> $conditions
     * @param array<string, mixed>       $settings
     */
    private function matches(array $conditions, array $settings): bool
    {
        foreach ($conditions as $condition) {
            if (isset($condition['any'])) {
                $matched = false;
                foreach ((array) $condition['any'] as $alternative) {
                    if ($this->matches([(array) $alternative], $settings)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
                continue;
            }

            $key = (string) ($condition['setting'] ?? '');
            if ($key === '') {
                continue;
            }

            $value = trim((string) ($settings[$key] ?? ''));

            if (isset($condition['is']) && $value !== (string) $condition['is']) {
                return false;
            }
            if (isset($condition['not']) && $value === (string) $condition['not']) {
                return false;
            }
            if (isset($condition['in']) && !in_array($value, array_map('strval', (array) $condition['in']), true)) {
                return false;
            }
            if (isset($condition['not_in']) && in_array($value, array_map('strval', (array) $condition['not_in']), true)) {
                return false;
            }
            if (array_key_exists('non_empty', $condition) && ($value !== '') !== (bool) $condition['non_empty']) {
                return false;
            }
        }

        return true;
    }

    private function label(string $key): string
    {
        return match ($key) {
            'imprint'    => __('Imprint'),
            'privacy'    => __('Privacy Policy'),
            'terms'      => __('Terms & Conditions'),
            'returns'    => __('Return Policy'),
            'withdrawal' => __('Withdrawal (Widerruf)'),
            'consent'    => __('Cookie Consent'),
            'us_optout'  => __('Do Not Sell or Share (US)'),
            default      => $key,
        };
    }

    private function message(string $id): string
    {
        return match ($id) {
            'consent_banner_off'     => __('The cookie consent banner is off, so optional services load without a recorded choice. Turn it on, or remove the services that need consent.'),
            'captcha_before_consent' => __('Third-party spam protection (reCAPTCHA, hCaptcha, Turnstile) loads before the visitor chooses. Use the honeypot or math fallback, or accept the risk.'),
            'third_party_ads'        => __('Advertising code renders on every page before the visitor chooses. Gate it with a consent category, or remove it.'),
            'ungated_custom_scripts' => __('Custom header or footer scripts have no consent category, so they load before the visitor chooses. Assign a category under Advanced → Custom Scripts.'),
            'us_optout_off'          => __('The consent banner is on but the US Do Not Sell or Share control is off, so Global Privacy Control signals are ignored.'),
            default                  => __('Review this setting for consent compliance.'),
        };
    }
}
