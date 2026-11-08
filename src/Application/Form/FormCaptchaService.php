<?php

declare(strict_types=1);

namespace ValeApp\Application\Form;

use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Guards the captcha providers a form can use (hCaptcha, reCAPTCHA, Turnstile).
 *
 * A provider only works when both its public site key and its secret key are
 * configured in Settings → Privacy. Selecting a provider without the keys used
 * to produce a form that rendered for visitors but rejected every submission,
 * so the setup is now validated on save and the form is withheld from the
 * frontend until the keys exist.
 */
final class FormCaptchaService
{
    private const PROVIDER_KEYS = [
        'hcaptcha'  => ['site' => 'hcaptcha_site_key', 'secret' => 'hcaptcha_secret_key'],
        'recaptcha' => ['site' => 'recaptcha_site_key', 'secret' => 'recaptcha_secret_key'],
        'turnstile' => ['site' => 'turnstile_site_key', 'secret' => 'turnstile_secret_key'],
    ];

    private const PROVIDER_LABELS = [
        'hcaptcha'  => 'hCaptcha',
        'recaptcha' => 'Google reCAPTCHA',
        'turnstile' => 'Cloudflare Turnstile',
    ];

    /** Settings keys already reported during this request, to keep logs sane. */
    private static array $reported = [];

    public function __construct(
        private readonly FormRepositoryInterface $forms,
        private readonly DbalSettingsRepository $settings,
    ) {
    }

    /**
     * Settings keys that are missing for the form's selected captcha provider.
     * An empty list means the captcha either needs no provider or is configured.
     *
     * @return list<string>
     */
    public function missingKeys(Form $form): array
    {
        return $this->missingKeysForProvider($form->getCaptchaType());
    }

    /**
     * Settings keys missing for a captcha provider name (e.g. 'turnstile').
     * Unknown types and key-less captchas (none, math, honeypot, disabled)
     * need nothing, so the list is empty.
     *
     * @return list<string>
     */
    public function missingKeysForProvider(string $type): array
    {
        $keys = self::PROVIDER_KEYS[$type] ?? null;
        if ($keys === null) {
            return [];
        }

        $missing = [];
        foreach ($keys as $key) {
            if (trim((string) $this->settings->get($key, '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    public function isMisconfigured(Form $form): bool
    {
        return $this->missingKeys($form) !== [];
    }

    public function isProviderMisconfigured(string $type): bool
    {
        return $this->missingKeysForProvider($type) !== [];
    }

    /**
     * Public site key for the form's captcha provider, or null when the
     * provider needs none (or is not configured — callers reject those forms).
     */
    public function siteKey(Form $form): ?string
    {
        return $this->siteKeyForProvider($form->getCaptchaType());
    }

    /**
     * Public site key for a captcha provider name, or null when the provider
     * needs none or its key is missing.
     */
    public function siteKeyForProvider(string $type): ?string
    {
        $key = self::PROVIDER_KEYS[$type]['site'] ?? null;
        if ($key === null) {
            return null;
        }

        $value = trim((string) $this->settings->get($key, ''));

        return $value === '' ? null : $value;
    }

    /**
     * Enabled forms whose captcha cannot work because the provider keys are
     * missing. These forms are not rendered on the frontend.
     *
     * @return Form[]
     */
    public function findUnavailableForms(): array
    {
        if (($this->settings->get('form_builder_enabled', '1') ?? '1') !== '1') {
            return [];
        }

        return array_values(array_filter(
            $this->forms->findAll(),
            fn (Form $form): bool => $form->isEnabled() && $this->isMisconfigured($form),
        ));
    }

    public function providerLabel(Form $form): string
    {
        return self::PROVIDER_LABELS[$form->getCaptchaType()] ?? $form->getCaptchaType();
    }

    /**
     * Writes a single log entry per misconfigured form per request — the
     * frontend hides the form, so without this the failure is silent.
     */
    public function logUnavailable(Form $form): void
    {
        $missing = $this->missingKeys($form);
        if ($missing === []) {
            return;
        }

        $formId = (string) $form->getId();
        if (isset(self::$reported[$formId])) {
            return;
        }
        self::$reported[$formId] = true;

        error_log(sprintf(
            '[Form] "%s" (slug: %s, id: %s) is hidden because captcha provider %s is not configured: missing %s.',
            $form->getName(),
            (string) $form->getSlug(),
            $formId !== '' ? $formId : 'new',
            $this->providerLabel($form),
            implode(', ', $missing),
        ));
    }
}
