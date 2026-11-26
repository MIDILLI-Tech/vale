<?php

declare(strict_types=1);

namespace ValeApp\App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;
use ValeApp\Application\Consent\CaptchaConsentGate;
use ValeApp\Application\Consent\ConsentService;
use ValeApp\Application\Consent\ConsentTextAdvisor;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * Resolves the visitor's consent decision and exposes it to the templates.
 *
 * Injected code blocks are gated by emptying their Twig value rather than by
 * editing every theme: `custom_header_scripts`, `custom_footer_scripts` and
 * `advertisement_code` are consumed in many places across the four themes, so
 * one gate here covers all of them.
 */
final class ConsentMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ConsentService $consent,
        private readonly ConsentTextAdvisor $textAdvisor,
        private readonly SettingsRepositoryInterface $settings,
        private readonly Twig $twig,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $enabled = $this->consent->enabled();
        $raw     = $_COOKIE[$this->consent->cookieName()] ?? null;

        $state = $this->consent->resolve(is_string($raw) ? $raw : null);
        $this->consent->setState($state);

        $usOptout = ($this->settings->get('consent_us_optout_enabled', '0') ?? '0') === '1';
        $gpc      = $usOptout && $this->honoursGlobalPrivacyControl($request);
        $this->consent->setGlobalPrivacyControl($gpc);

        // Bound once per request: rendering and validation both read it, so a
        // declined visitor always sees (and is checked against) the same math
        // challenge instead of a provider script.
        CaptchaConsentGate::configure(
            ($this->settings->get('consent_gate_captcha', '0') ?? '0') === '1'
                ? $this->consent->isAllowed('functional')
                : null,
        );

        $env = $this->twig->getEnvironment();
        $env->addGlobal('consent_enabled', $enabled);
        $env->addGlobal('consent_decided', $state->decided);
        $env->addGlobal('consent_state', [
            'decided'    => $state->decided,
            'categories' => $state->categories,
        ]);
        $env->addGlobal('consent_optional_categories', $this->consent->optionalCategories());
        $env->addGlobal('consent_version', $this->consent->version());
        $env->addGlobal('consent_policy_url', $this->policyUrl());
        $env->addGlobal('consent_us_optout', $usOptout);
        $env->addGlobal('consent_gpc', $gpc);

        $this->publishHonestText($env, (string) ($request->getAttribute('locale') ?? ''));

        if ($enabled) {
            $this->gateInjectedScripts($env);
        }

        return $handler->handle($request);
    }

    /**
     * The banner text is generated from what is actually switched on, so an
     * empty field can never claim "only necessary cookies" while a captcha or
     * tracking pixel is loading.
     */
    private function publishHonestText(\Twig\Environment $env, string $locale): void
    {
        $bannerText = trim((string) ($this->settings->getLocalized('consent_banner_text', $locale, '') ?? ''));
        $env->addGlobal(
            'consent_banner_text',
            $bannerText !== '' ? $bannerText : $this->translate($this->textAdvisor->bannerSentences()),
        );

        $env->addGlobal(
            'consent_banner_title',
            trim((string) ($this->settings->getLocalized('consent_banner_title', $locale, '') ?? '')),
        );

        $noticeText = trim((string) ($this->settings->getLocalized('cookie_warning_text', $locale, '') ?? ''));
        if ($noticeText === '') {
            $env->addGlobal('cookie_warning_text', $this->translate($this->textAdvisor->noticeSentences()));
        }

        $env->addGlobal('consent_generated_banner_text', $this->translate($this->textAdvisor->bannerSentences()));
    }

    private function honoursGlobalPrivacyControl(ServerRequestInterface $request): bool
    {
        return trim($request->getHeaderLine('Sec-GPC')) === '1';
    }

    /**
     * Privacy policy page configured under E-Commerce → Legal, linked from the
     * banner. Empty when no page is selected, so the link is simply omitted.
     */
    private function policyUrl(): string
    {
        $slug = trim((string) ($this->settings->get('policy_page_privacy', '') ?? ''));

        return $slug === '' ? '' : site_url('/' . ltrim($slug, '/'));
    }

    /**
     * @param list<string> $sentences Already translated by the advisor.
     */
    private function translate(array $sentences): string
    {
        return implode(' ', $sentences);
    }

    private function gateInjectedScripts(\Twig\Environment $env): void
    {
        $globals = $env->getGlobals();

        $settings = $globals['settings'] ?? null;
        if (is_array($settings)) {
            foreach (['custom_header_scripts', 'custom_footer_scripts'] as $key) {
                $category = strtolower(trim((string) ($settings[$key . '_category'] ?? '')));

                // Unclassified blocks load as before — the admin screen flags
                // them so the operator can assign a category deliberately.
                if ($category !== '' && !$this->consent->isAllowed($category)) {
                    $settings[$key] = '';
                }
            }
            $env->addGlobal('settings', $settings);
        }

        if (!$this->consent->isAllowed('marketing')) {
            $env->addGlobal('advertisement_code', '');
        }
    }
}
