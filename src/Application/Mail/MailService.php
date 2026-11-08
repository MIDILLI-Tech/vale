<?php

declare(strict_types=1);

namespace ValeApp\Application\Mail;

use Slim\Views\Twig;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

/**
 * Application-level mail service.
 *
 * Renders Twig mail templates and dispatches via the injected MailInterface.
 */
final class MailService
{
    public function __construct(
        private readonly MailInterface $mailer,
        private readonly Twig $twig,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Translator $translator,
        private readonly ThemeManager $themeManager,
    ) {
    }

    /**
     * Send a transactional email using a Twig template.
     * Checks notification settings before sending (skips if disabled).
     * Checks for a custom DB template first, falls back to file-based template.
     *
     * @param 'customer'|'admin'                                       $recipientType Whether this goes to the customer or admin (checked against notification settings)
     * @param array<string, mixed>                                     $context
     * @param array<int, array{name: string, content: string, mime: string}> $attachments
     */
    public function sendTemplate(
        string $to,
        string $subject,
        string $template,
        array $context = [],
        ?string $fromAddress = null,
        ?string $fromName = null,
        array $attachments = [],
        ?string $locale = null,
        string $recipientType = 'customer',
    ): void {
        $cleanTemplate = str_replace('mail/', '', $template);
        $settingKey    = 'mail_notification_' . $cleanTemplate . '_' . $recipientType;
        if ($this->settings->get($settingKey, '1') !== '1') {
            return;
        }

        // Add common variables to all mail templates
        $siteName = $this->settings->get('site_name', 'Vale');
        $context  = array_merge([
            'site_name'       => $siteName,
            'vl_email_colors' => $this->getEmailColorContext(),
        ], $this->getEmailFlatColors(), $context);

        $originalLocale = $this->translator->getLocale();
        if ($locale) {
            $this->translator->setLocale($locale);
            $this->twig->getEnvironment()->addGlobal('locale', $locale);
        }

        try {
            $custom = $this->settings->get('mail_template_' . $template, '');
            if ($custom !== '' && $custom !== null) {
                $html = $this->twig->getEnvironment()->createTemplate($custom)->render($context);
            } else {
                $html = $this->twig->getEnvironment()->render($template . '.twig', $context);
            }
        } finally {
            if ($locale) {
                $this->translator->setLocale($originalLocale);
                $this->twig->getEnvironment()->addGlobal('locale', $originalLocale);
            }
        }

        $text = strip_tags(
            preg_replace(
                '/<script\b[^>]*>.*?<\/script>/is',
                '',
                preg_replace(
                    '/<style\b[^>]*>.*?<\/style>/is',
                    '',
                    preg_replace('/<br\s*\/?>/i', "\n", $html)
                )
            )
        );

        $message = new MailMessage(
            to:          $to,
            subject:     $subject,
            htmlBody:    $html,
            textBody:    $text,
            fromAddress: $fromAddress,
            fromName:    $fromName,
            attachments: $attachments,
        );

        $this->mailer->send($message);
    }

    /**
     * Send a pre-built MailMessage directly.
     */
    public function send(MailMessage $message): void
    {
        $this->mailer->send($message);
    }

    public function getTranslator(): Translator
    {
        return $this->translator;
    }

    /**
     * Flat email color variables for Twig templates (email-client safe:
     * rendered as literal hex, no CSS custom properties).
     *
     * Dynamically maps every theme color variable to an `email_*` key,
     * so new colors added to theme.json are automatically available.
     *
     * @return array<string, string>
     */
    public function getEmailFlatColors(): array
    {
        $colors = $this->getEmailColorContext();
        $flat   = [];

        foreach ($colors as $key => $value) {
            $flatKey          = 'email_' . ltrim(str_replace('--theme-', '', (string) $key), '-');
            $flat[$flatKey]   = $value;
        }

        return $flat;
    }
    /**
     * Get email color context — either from active theme or custom mail colors.
     */
    public function getEmailColorContext(): array
    {
        $useThemeColors = $this->settings->get('mail_use_theme_colors', '1') === '1';

        if ($useThemeColors) {
            $themeSlug    = $this->settings->get('active_theme', 'default');
            $savedRaw     = $this->settings->get('theme_' . $themeSlug . '_colors', '');
            $savedColors  = $savedRaw !== '' ? json_decode($savedRaw, true) : null;
            if (!is_array($savedColors)) {
                $savedColors = [];
            }
            $resolved = $this->themeManager->resolveColorVariables($themeSlug, $savedColors);
            return array_map(fn (array $item) => $item['value'] ?? '', $resolved);
        }

        // Custom email colors — dynamically mapped from theme color_variables schema
        $themeSlug = $this->settings->get('active_theme', 'default');
        $schema    = $this->themeManager->getColorVariablesSchema($themeSlug);
        $context   = [];

        foreach ($schema as $varName => $spec) {
            if (!is_string($varName) || !str_starts_with($varName, '--')) {
                continue;
            }
            $settingKey        = 'mail_color_' . ltrim(str_replace('--theme-', '', $varName), '-');
            $context[$varName] = $this->settings->get($settingKey, (string) ($spec['default'] ?? '#000000'));
        }

        return $context;
    }
}
