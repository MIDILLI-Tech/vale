---
name: vale-mail-driver
description: "Adds a new mail transport driver to Vale by asking for the provider (API-based or SMTP relay) and its config keys, then generating: an adapter class in src/Infrastructure/Mail/ implementing MailInterface (send/sendBatch + driverName/getMaxBatchSize), a match() arm in the MailInterface DI factory in config/services.php with the api_url from reference-data mail_drivers, admin settings option and key storage notes, and translation strings. USE FOR: 'add mail provider', new email sending integration, transactional email API adapter. DO NOT USE FOR: mail template content/placeholders (mail templates live in admin), newsletter campaign logic, webhook integrations."
license: AGPL-3.0
metadata:
  project: vale/vale
  reference: config/services.php (MailInterface factory)
---

# Add a Mail Driver

Vale's mail layer: `Domain\Mail\Port\MailInterface` with `send(MailMessage)` + `sendBatch(MailMessage[])` + `getMaxBatchSize(): int` + `driverName(): string`. The concrete adapter is chosen at runtime by the `mail_driver` setting inside the `MailInterface::class` factory in `config/services.php` (~line 1116). Existing adapters to mimic: `PHPMailerAdapter`, `PhpMailAdapter`, `ResendAdapter`, `MailgunAdapter`, `SendGridAdapter`, `UnosendAdapter`, `LogMailAdapter`. Optional logging is applied via the `FileLoggingMailDecorator` wrapper. Newsletter campaigns are dispatched in cron-driven chunks via `sendBatch`, so every adapter must implement it.

## Phase 1 — Ask (required before writing any code)

1. Provider name + slug (slug becomes the `mail_driver` value, e.g. `brevomail`)?
2. Transport: HTTP API (most providers) or SMTP relay (then prefer configuring built-in PHPMailerAdapter instead — say so if SMTP)?
3. Config keys needed? (typical: `{slug}_api_key`, `{slug}_from_name` overrides?) Note keys are stored encrypted-capable settings rows read from `DbalSettingsRepository`.
4. Sending endpoint details: base URL, auth header style, payload format, response success/error shape?

## Phase 2 — Generate

### Step 1 — Adapter: `src/Infrastructure/Mail/BrevoMailAdapter.php`

```php
<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

final class BrevoMailAdapter implements MailInterface
{
    private const DEFAULT_API_URL = 'https://api.brevo.com/v3';

    public function __construct(
        private readonly DbalSettingsRepository $settings,
        ?string $apiBaseUrl = null,
    ) {
        $this->apiBaseUrl = rtrim($apiBaseUrl ?? self::DEFAULT_API_URL, '/');
    }

    private string $apiBaseUrl;

    public function driverName(): string
    {
        return 'brevo';
    }

    public function getMaxBatchSize(): int
    {
        $custom = (int) $this->settings->get('mail_batch_size', '100');
        return $custom > 0 ? min($custom, 100) : 100;
    }

    public function send(MailMessage $message): void
    {
        $this->sendBatch([$message]);
    }

    /**
     * @param MailMessage[] $messages
     */
    public function sendBatch(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $apiKey = $this->settings->get('brevo_api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException('Brevo API key is not configured');
        }

        $batchEnabled = $this->settings->get('mail_batch_enabled', '1') !== '0';

        // Providers without a native batch endpoint: loop single sends.
        // (Resend/Mailgun/SendGrid use their native batch endpoints here instead.
        // Native batch is only safe when every message shares subject, body,
        // from, reply-to AND list-unsubscribe — personalized campaigns must
        // fall back to single sends, otherwise recipients get each other's
        // unsubscribe links.)
        foreach ($messages as $message) {
            $this->sendSingleMessage($message);
        }
    }

    private function sendSingleMessage(MailMessage $message): void
    {
        $apiKey = $this->settings->get('brevo_api_key', '');

        $payload = [
            'sender' => [
                'name'  => $message->getFromName(),
                'email' => $message->getFromEmail(),
            ],
            'to'      => [['email' => $message->getToEmail(), 'name' => $message->getToName()]],
            'subject' => $message->getSubject(),
            'htmlContent' => $message->getHtmlBody(),
            //textContent when plain-text body exists
        ];

        $http = \ValeApp\Infrastructure\Payment\HttpTransport::request(
            'POST',
            $this->apiBaseUrl . '/smtp/email',
            ['api-key: ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            15,
        );

        if (!$http['ok']) {
            throw new \RuntimeException('Brevo send failed: ' . ($http['error'] ?: ('HTTP ' . $http['status'])));
        }
    }
}
```

Adapt to the provider's real payload/auth. Conventions:
- Constructor takes `DbalSettingsRepository` plus optional `?string $apiBaseUrl = null` (falls back to a `DEFAULT_API_URL` class constant); read your keys lazily inside `sendBatch()` so admin changes apply immediately.
- Never hard-code the API base URL at call sites — the factory injects it from `config/reference-data.php` (`mail_drivers.{slug}.api_url`, single source of truth).
- Throw `\RuntimeException` on any failure — MailService handles logging upstream.
- Check `MailMessage` for available getters (from/to/replyTo, html/text, attachments) before assuming names.
- Reuse `HttpTransport::request` for HTTP calls; do not hand-roll curl.

### Step 2 — Register in the factory: `config/services.php`

Add a `mail_drivers` entry first (`config/reference-data.php`):

```php
'brevo' => [
    'name'        => 'Brevo',
    'signup_url'  => 'https://www.brevo.com/',
    'api_key_url' => 'https://app.brevo.com/settings/keys/api-keys',
    'api_url'     => 'https://api.brevo.com/v3',
],
```

(No `docs_url` — not needed for mail drivers.)

Then add a match arm (the factory already loads `mail_drivers` and exposes an `$apiUrl($slug, $fallback)` helper):

```php
$inner = match ($driver) {
    'smtp'     => new PHPMailerAdapter($settings),
    // ... existing ...
    'brevo'    => new BrevoMailAdapter($settings, $apiUrl('brevo', 'https://api.brevo.com/v3')),
    default    => new LogMailAdapter($logDir),
};
```

The `FileLoggingMailDecorator` wrapping (`mail_log_enabled = '1'`, non-log drivers) applies automatically — no change needed.

Also add the `use ValeApp\Infrastructure\Mail\BrevoMailAdapter;` import.

### Step 3 — Admin visibility

- The mail-driver dropdown lives in the admin Mail settings view (`resources/views/admin/settings/_mail_setup.twig` — locate by grepping for `mail_driver`). Add the option `<option value="brevo">Brevo</option>` plus an API-key input block following the SendGrid/Resend pattern, **with Tailwind `dark:` variants** on all colors (AGENTS.md §16).
- Link the API-key helper and driver guide to `{{ mail_drivers.brevo.api_key_url }}` / `{{ mail_drivers.brevo.signup_url }}` from reference-data — never hard-code provider URLs in Twig (`SettingsController` already passes `mail_drivers` to the view).
- Settings persistence under `/admin/settings` save is generic for `{slug}_*` keys — no controller changes required.

### Step 4 — Translations

Add admin-facing labels to `resources/lang/en.json` (+ `tr.json`, `fr.json`), or run `composer translations` afterwards. Use `{{ __('...') }}` in Twig.

## Phase 3 — Verify

- `php -l` on touched PHP files
- `composer lint:changed`
- Unit test following existing `tests/Infrastructure` mail tests if present (mock `DbalSettingsRepository`; assert payload building + exception on missing key)
- Manual smoke test: set `mail_driver=brevo` + key in admin → use a "send test email" flow or trigger password-reset mail → confirm delivery + row in mail log (if enabled)

## Checklist

- [ ] Adapter implements `send()` + `sendBatch()` + `getMaxBatchSize()` + `driverName()`, throws RuntimeException on failure
- [ ] `mail_drivers` entry added to `config/reference-data.php` (`name`/`signup_url`/`api_key_url`/`api_url`, no `docs_url`)
- [ ] Factory match arm added with `$apiUrl()` injection, imports sorted (composer fix will enforce)
- [ ] Admin dropdown + key input with dark: variants, URLs via `mail_drivers` (no hard-coded provider URLs)
- [ ] Settings keys documented (`{slug}_api_key`)
- [ ] Lang entries added
- [ ] Docs reminder: update `vale-docs/en/latest/admin/settings/mail.md` driver list + batch table if limits differ
