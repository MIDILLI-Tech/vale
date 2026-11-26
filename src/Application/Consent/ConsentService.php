<?php

declare(strict_types=1);

namespace ValeApp\Application\Consent;

use ValeApp\Domain\Consent\Repository\ConsentRepositoryInterface;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * Resolves, records and applies the visitor's consent decision.
 *
 * The decision lives in a first-party cookie so the server can gate script
 * output before the response is rendered — no client-side patching required.
 */
final class ConsentService
{
    private ConsentState $state;

    /**
     * Global Privacy Control signal (Sec-GPC header). When honoured, it counts
     * as an opt-out of sale/sharing, so marketing stays blocked.
     */
    private bool $globalPrivacyControl = false;

    /**
     * @param array{cookie_name:string, cookie_lifetime:int, categories:array<string,array{required:bool}>} $config
     */
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly ConsentRepositoryInterface $repository,
        private readonly array $config,
        private readonly ?ConsentTextAdvisor $advisor = null,
    ) {
        $this->state = ConsentState::undecided($this->version());
    }

    public function enabled(): bool
    {
        return ($this->settings->get('consent_enabled', '0') ?? '0') === '1';
    }

    public function version(): string
    {
        $version = trim((string) ($this->settings->get('consent_version', '1') ?? '1'));

        return $version === '' ? '1' : $version;
    }

    public function cookieName(): string
    {
        return (string) ($this->config['cookie_name'] ?? 'vl_consent');
    }

    public function cookieLifetime(): int
    {
        return (int) ($this->config['cookie_lifetime'] ?? 15552000);
    }

    /**
     * Categories offered to the visitor, in catalog order.
     *
     * An unset setting means "not configured yet" and allows every optional
     * category; an explicitly empty setting allows none. A category that has
     * no service assigned to it would ask the visitor to consent to something
     * that does not exist, so it is never offered.
     *
     * @return list<string>
     */
    public function optionalCategories(): array
    {
        $available  = $this->catalogOptionalCategories();
        $raw        = $this->settings->get('consent_categories');
        $configured = $raw === null
            ? $available
            : array_filter(array_map(
                static fn (string $item): string => strtolower(trim($item)),
                explode(',', $raw),
            ));

        $offered = array_values(array_intersect($available, $configured));
        $inUse   = $this->advisor?->activeCategories();

        return $inUse === null ? $offered : array_values(array_intersect($offered, $inUse));
    }

    /**
     * @return list<string>
     */
    public function activeCategories(): array
    {
        return array_merge(['necessary'], $this->optionalCategories());
    }

    public function state(): ConsentState
    {
        return $this->state;
    }

    public function setGlobalPrivacyControl(bool $enabled): void
    {
        $this->globalPrivacyControl = $enabled;
    }

    public function globalPrivacyControl(): bool
    {
        return $this->globalPrivacyControl;
    }

    public function setState(ConsentState $state): void
    {
        $this->state = $state;
    }

    public function resolve(?string $rawCookie): ConsentState
    {
        if (!$this->enabled() || $rawCookie === null || trim($rawCookie) === '') {
            return ConsentState::undecided($this->version(), $this->activeCategories());
        }

        $decoded = base64_decode(trim($rawCookie), true);
        if ($decoded === false) {
            return ConsentState::undecided($this->version(), $this->activeCategories());
        }

        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return ConsentState::undecided($this->version(), $this->activeCategories());
        }

        // A new policy version invalidates earlier decisions.
        if ((string) ($data['v'] ?? '') !== $this->version()) {
            return ConsentState::undecided($this->version(), $this->activeCategories());
        }

        $chosen = array_values(array_intersect(
            $this->optionalCategories(),
            array_map('strval', (array) ($data['c'] ?? [])),
        ));

        $consentId = trim((string) ($data['id'] ?? ''));

        return new ConsentState(
            $consentId === '' ? null : $consentId,
            $this->version(),
            array_merge(['necessary'], $chosen),
            true,
        );
    }

    /**
     * Cache key component for the page cache.
     *
     * The rendered HTML only depends on the policy version, whether a decision
     * was made and which optional categories were accepted — never on the
     * visitor's random consent id. That keeps the number of cached variants
     * bounded (2^n + 1 instead of one per visitor).
     */
    public function cacheVariant(?string $rawCookie, ?bool $globalPrivacyControl = null): string
    {
        $gpc   = $globalPrivacyControl ?? $this->globalPrivacyControl;
        $state = $this->resolve($rawCookie);

        $accepted = $state->decided
            ? array_values(array_intersect($state->categories, $this->optionalCategories()))
            : [];

        return $this->version()
            . '|' . ($state->decided ? '1' : '0')
            . '|' . implode(',', $accepted)
            . ($gpc ? '|gpc' : '');
    }

    /**
     * Whether output assigned to the category may be rendered.
     *
     * A category the operator has switched off never renders, and a category
     * the visitor has not accepted never renders.
     */
    public function isAllowed(string $category): bool
    {
        $category = strtolower(trim($category));

        if ($category === '' || $category === 'necessary') {
            return true;
        }

        if (!$this->enabled()) {
            return true;
        }

        // GPC is a standing opt-out of sale/sharing: it wins over an earlier
        // "accept all" and needs no banner interaction.
        if ($this->globalPrivacyControl && $category === 'marketing') {
            return false;
        }

        if (!in_array($category, $this->optionalCategories(), true)) {
            return false;
        }

        return $this->state->decided && $this->state->allows($category);
    }

    /**
     * Store a choice made in the banner.
     *
     * @param list<string> $selected Optional categories the visitor accepted.
     */
    public function save(array $selected, ?int $userId = null, string $action = 'grant'): ConsentState
    {
        $chosen = [];
        foreach ($selected as $category) {
            $category = strtolower(trim((string) $category));
            if ($category !== '' && in_array($category, $this->optionalCategories(), true)) {
                $chosen[$category] = true;
            }
        }

        $consentId  = $this->state->consentId ?? bin2hex(random_bytes(16));
        $categories = array_merge(['necessary'], array_keys($chosen));
        $action     = $this->state->decided && $action === 'grant' ? 'update' : $action;

        $this->repository->record($consentId, $action, $categories, $this->version(), $userId);

        $state = new ConsentState($consentId, $this->version(), $categories, true);
        $this->writeCookie($state);
        $this->state = $state;

        return $state;
    }

    /**
     * Withdraw every optional category. Recorded as its own action so the log
     * can distinguish a withdrawal from a first-time rejection.
     */
    public function revoke(?int $userId = null): ConsentState
    {
        return $this->save([], $userId, 'revoke');
    }

    private function writeCookie(ConsentState $state): void
    {
        $optional = array_values(array_intersect($state->categories, $this->optionalCategories()));
        $value    = base64_encode((string) json_encode([
            'v'  => $state->version,
            'c'  => $optional,
            'id' => $state->consentId,
        ]));

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';

        setcookie($this->cookieName(), $value, [
            'expires'  => time() + $this->cookieLifetime(),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $_COOKIE[$this->cookieName()] = $value;
    }

    /**
     * @return list<string>
     */
    private function catalogOptionalCategories(): array
    {
        $categories = [];

        foreach ((array) ($this->config['categories'] ?? []) as $name => $definition) {
            if (($definition['required'] ?? false) === true) {
                continue;
            }
            $categories[] = (string) $name;
        }

        return $categories;
    }
}
