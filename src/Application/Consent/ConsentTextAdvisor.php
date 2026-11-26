<?php

declare(strict_types=1);

namespace ValeApp\Application\Consent;

use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * Works out what actually loads on this site and proposes honest wording.
 *
 * The sentences are complete, translatable strings rather than fragments, so
 * the generated text keeps its grammar in every language. Translation happens
 * at render time; this class stays free of the translator.
 */
final class ConsentTextAdvisor
{
    private const CAPTCHA_PROVIDERS = ['hcaptcha', 'recaptcha', 'turnstile'];

    private const CAPTCHA_SETTING_KEYS = [
        'login_captcha_type',
        'register_captcha_type',
        'comment_captcha_type',
        'review_captcha_type',
        'newsletter_captcha_type',
    ];

    private const SCRIPT_KEYS = ['custom_header_scripts', 'custom_footer_scripts'];

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function usesCaptcha(): bool
    {
        foreach (self::CAPTCHA_SETTING_KEYS as $key) {
            $type = strtolower(trim((string) ($this->settings->get($key, 'none') ?? 'none')));

            if (in_array($type, self::CAPTCHA_PROVIDERS, true)) {
                return true;
            }
        }

        return false;
    }

    public function usesAdvertising(): bool
    {
        $enabled = ($this->settings->get('advertisement_enabled', '0') ?? '0') === '1';
        $code    = trim((string) ($this->settings->get('advertisement_code', '') ?? ''));

        return $enabled && $code !== '';
    }

    /**
     * Consent categories assigned to non-empty script blocks.
     *
     * @return list<string>
     */
    public function scriptCategories(): array
    {
        $categories = [];

        foreach (self::SCRIPT_KEYS as $key) {
            if (trim((string) ($this->settings->get($key, '') ?? '')) === '') {
                continue;
            }

            $category     = strtolower(trim((string) ($this->settings->get($key . '_category', '') ?? '')));
            $categories[] = $category === '' ? 'unclassified' : $category;
        }

        return array_values(array_unique($categories));
    }

    /**
     * Whether provider captcha scripts wait for the functional category.
     */
    public function captchaGated(): bool
    {
        return ($this->settings->get('consent_gate_captcha', '0') ?? '0') === '1';
    }

    public function hasUnclassifiedScripts(): bool
    {
        return in_array('unclassified', $this->scriptCategories(), true);
    }

    /**
     * Optional categories that actually carry something on this site.
     *
     * @return list<string>
     */
    public function activeCategories(): array
    {
        $categories = $this->scriptCategories();

        if ($this->usesAdvertising()) {
            $categories[] = 'marketing';
        }

        // A gated captcha is itself a functional service, otherwise the
        // category would never be offered and every visitor would fall back.
        if ($this->captchaGated() && $this->usesCaptcha()) {
            $categories[] = 'functional';
        }

        return array_values(array_intersect(
            ['functional', 'analytics', 'marketing'],
            $categories,
        ));
    }

    public function hasThirdPartyServices(): bool
    {
        return $this->usesCaptcha()
            || $this->usesAdvertising()
            || $this->scriptCategories() !== [];
    }

    /**
     * Sentences for the consent banner, used when the operator leaves the
     * custom text empty.
     *
     * @return list<string>
     */
    public function bannerSentences(): array
    {
        $sentences = [__('We use technically necessary cookies to run this site.')];

        if ($this->usesCaptcha()) {
            // Ungated, the challenge script renders with the form and runs
            // before the visitor has chosen anything — saying otherwise would
            // make the banner lie.
            $sentences[] = $this->captchaGated()
                ? __('Spam protection services are loaded only with your consent. A simple math question is used otherwise.')
                : __('Spam protection services on forms load before you choose.');
        }

        foreach ($this->activeCategories() as $category) {
            $sentences[] = match ($category) {
                'functional' => __('Functional services that store your preferences are loaded only with your consent.'),
                'analytics'  => __('Analytics services are loaded only with your consent.'),
                'marketing'  => __('Marketing and advertising services are loaded only with your consent.'),
                default      => '',
            };
        }

        $sentences = array_values(array_filter($sentences));

        if ($this->hasUnclassifiedScripts()) {
            $sentences[] = __('Additional third-party services are loaded on this site.');
        }

        if (count($sentences) > 1) {
            $sentences[] = __('You can change or withdraw your choice at any time.');
        }

        return $sentences;
    }

    /**
     * Sentences for the simple notice shown while the consent module is off.
     *
     * @return list<string>
     */
    public function noticeSentences(): array
    {
        if (!$this->hasThirdPartyServices()) {
            return [__('We use only technically necessary cookies.')];
        }

        $sentences = [__('This site uses third-party services that may store information on your device.')];

        if ($this->usesCaptcha()) {
            $sentences[] = __('Spam protection is active on forms.');
        }

        if ($this->usesAdvertising()) {
            $sentences[] = __('Advertising services are active on this site.');
        }

        return $sentences;
    }
}
