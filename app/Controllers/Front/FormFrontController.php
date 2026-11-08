<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use ValeApp\Application\Form\FormAccessService;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Entity\FormSubmission;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\Event\EventDispatcherInterface;
use ValeApp\Domain\Shared\Exception\DomainException;
use ValeApp\Domain\Shared\ValueObject\Email;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Analytics\UserAgentParser;
use ValeApp\Infrastructure\Content\CaptchaUrls;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Security\IpAnonymizer;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class FormFrontController
{
    use CaptchaTrait;

    private ?string $corsOrigin = null;

    public function __construct(
        private readonly Twig $twig,
        private readonly FormRepositoryInterface $forms,
        private readonly MailService $mail,
        private readonly SessionManager $session,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly DbalSettingsRepository $settings,
        private readonly Connection $db,
        private readonly IpAnonymizer $ipAnonymizer,
        private readonly OutboundWebhookService $webhooks,
        private readonly EventDispatcherInterface $events,
        private readonly Translator $translator,
        private readonly UserRepositoryInterface $users,
        private readonly PlanRepositoryInterface $plans,
        private readonly FormAccessService $formAccess,
        private readonly FormCaptchaService $formCaptcha,
        private readonly ?AutomationService $automationService = null,
    ) {
    }

    private function isFallbackNoticeEnabled(): bool
    {
        return $this->settings->get('multi_language_content', '0')          === '1'
            && $this->settings->get('i18n_display_originals_fallback', '1') === '1'
            && $this->settings->get('i18n_fallback_content_notice', '1')    === '1';
    }

    /** @return array{is_fallback: bool, content_language: string, has_translation: bool} */
    private function computeFallbackFlags(string $contentLang, ?int $translationOf, string $entityLanguage, callable $findTranslations): array
    {
        $isFallback     = false;
        $hasTranslation = false;

        if ($contentLang !== '' && $this->isFallbackNoticeEnabled() && $entityLanguage !== $contentLang) {
            $originalId     = $translationOf ?: 0;
            $translations   = $findTranslations($originalId);
            foreach ($translations as $t) {
                if ($t->getLanguage() === $contentLang && $t->isEnabled()) {
                    $hasTranslation = true;
                    break;
                }
            }
            $isFallback = !$hasTranslation;
        }

        return [
            'is_fallback'      => $isFallback,
            'content_language' => $entityLanguage,
            'has_translation'  => $hasTranslation,
        ];
    }

    private function buildFormFallbackFlags(Form $form): array
    {
        $currentLocale = $this->translator->getLocale();
        return $this->computeFallbackFlags(
            $currentLocale,
            $form->getTranslationOf(),
            $form->getLanguage(),
            fn (int $originalId) => $originalId > 0 ? $this->forms->findTranslations($originalId) : []
        );
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $form = $this->forms->findBySlug($slug);
        if ($form === null || !$form->isEnabled()) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        // Multi-language resolution for standalone form view
        if ($this->settings->get('multi_language_content', '0') === '1') {
            $currentLocale = $this->translator->getLocale();
            if ($form->getLanguage() !== $currentLocale) {
                $originalId   = $form->getTranslationOf() ?? $form->getId();
                $translations = $this->forms->findTranslations($originalId);
                $matchedForm  = null;
                foreach ($translations as $t) {
                    if ($t->getLanguage() === $currentLocale && $t->isEnabled()) {
                        $matchedForm = $t;
                        break;
                    }
                }

                if ($matchedForm !== null) {
                    $form = $matchedForm;
                } else {
                    $fallback = $this->settings->get('i18n_display_originals_fallback', '1') === '1';
                    if (!$fallback) {
                        return $this->twig->render($response->withStatus(404), 'errors/404.twig');
                    }
                    if ($form->getTranslationOf() !== null) {
                        $originalForm = $this->forms->findById($originalId);
                        if ($originalForm !== null && $originalForm->isEnabled()) {
                            $form = $originalForm;
                        } else {
                            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
                        }
                    }
                }
            }
        }

        $fallbackFlags = $this->buildFormFallbackFlags($form);

        // Access Control
        $accessCheck = $this->formAccess->evaluate($form);

        // A captcha provider without its API keys makes the form unusable —
        // every submission would be rejected — so it is withheld entirely.
        if ($this->formCaptcha->isMisconfigured($form)) {
            $this->formCaptcha->logUnavailable($form);

            return $this->twig->render($response, 'forms/unavailable.twig', [
                'form'                              => $form,
                'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
                'content_language'                  => $fallbackFlags['content_language'],
                'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
            ]);
        }

        $captcha          = $this->buildFormCaptchaData($form);
        $membershipPlans  = ($this->settings->get('membership_enabled', '1') === '1') ? $this->plans->findAllActive() : [];

        return $this->twig->render($response, 'forms/show.twig', [
            'form'                              => $form,
            'success'                           => false,
            'has_access'                        => $accessCheck['has_access'],
            'require_login'                     => $accessCheck['require_login'],
            'missing_membership_tags'           => $accessCheck['missing_membership_tags'],
            'access_tags'                       => $accessCheck['access_tags'],
            'membership_plans'                  => $membershipPlans,
            'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
            'content_language'                  => $fallbackFlags['content_language'],
            'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
        ] + $captcha);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        $form = $this->forms->findBySlug($slug);
        if ($form === null || !$form->isEnabled()) {
            return $this->twig->render($response->withStatus(404), 'errors/404.twig');
        }

        // Attribute to the slug-matched form; do not re-map by visitor locale
        // (the rendered form's action always targets this form's slug).

        $accessCheck = $this->formAccess->evaluate($form);
        if (!$accessCheck['has_access']) {
            $message = $accessCheck['require_login']
                ? __('You must be logged in to submit this form.')
                : __('You do not have the required membership plan to submit this form.');
            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                return $this->jsonResponse($response, ['success' => false, 'message' => $message], 403);
            }
            return $this->twig->render($response->withStatus(403), 'errors/403.twig');
        }

        $body          = (array) $request->getParsedBody();
        $settings      = $form->getSettings();
        $fallbackFlags = $this->buildFormFallbackFlags($form);

        // Local Honeypot validation (only if captcha_type is honeypot)
        $honeypotField = $form->getHoneypotFieldName();
        if (($settings['captcha_type'] ?? '') === 'honeypot' && !empty($body[$honeypotField])) {
            return $this->twig->render($response, 'forms/show.twig', [
                'form'                              => $form,
                'success'                           => true,
                'message'                           => $form->getSuccessMessage() ?: __('Thank you! Your message has been sent.'),
                'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
                'content_language'                  => $fallbackFlags['content_language'],
                'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
            ]);
        }

        // Validate captcha
        $captchaError = $this->validateFormCaptcha($form, $body);
        if ($captchaError !== null) {
            $captcha = $this->buildFormCaptchaData($form);

            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                $payload = [
                    'success' => false,
                    'message' => $captchaError,
                ];
                if ($captcha['captcha_type'] === 'math') {
                    $payload['captcha_question'] = $captcha['captcha_question'];
                }
                $response->getBody()->write(json_encode($payload));
                return $response->withHeader('Content-Type', 'application/json');
            }

            return $this->twig->render($response, 'forms/show.twig', [
                'form'                              => $form,
                'error'                             => $captchaError,
                'success'                           => false,
                'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
                'content_language'                  => $fallbackFlags['content_language'],
                'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
            ] + $captcha);
        }

        return $this->processSubmission($request, $response, $form, $body, false);
    }

    public function submitExternal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->corsOrigin = null;
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $this->jsonResponse($response, ['success' => false, 'message' => __('Invalid slug.')], 404);
        }

        $form = $this->forms->findBySlug($slug);
        if ($form === null || !$form->isEnabled()) {
            return $this->jsonResponse($response, ['success' => false, 'message' => __('Form not found or disabled.')], 404);
        }

        $settings = $form->getSettings();
        if (empty($settings['allow_external'])) {
            return $this->jsonResponse($response, ['success' => false, 'message' => __('External submissions are not allowed for this form.')], 403);
        }

        // Authenticate optional Bearer token — valid token bypasses domain restriction
        $authenticated  = false;
        $tokenProvided  = false;
        $authHeader     = $request->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $tokenProvided = true;
            $plainToken    = trim(substr($authHeader, 7));
            if ($plainToken !== '') {
                $hashed = hash('sha256', $plainToken);
                $row    = $this->db->fetchAssociative(
                    'SELECT id, abilities FROM ' . VL_DB_PREFIX . 'api_tokens'
                    . ' WHERE token = ? AND (expires_at IS NULL OR expires_at > ?)',
                    [$hashed, date('Y-m-d H:i:s')],
                );
                if ($row !== false) {
                    $rowAbilities = trim((string) ($row['abilities'] ?? ''));
                    $hasAbility   = $rowAbilities === '*' || in_array('submit:forms', array_map('trim', explode(',', $rowAbilities)), true);
                    if (!$hasAbility) {
                        return $this->jsonResponse($response, ['success' => false, 'message' => __('Token does not have submit:forms ability.')], 403);
                    }
                    $this->db->update(VL_DB_PREFIX . 'api_tokens', ['last_used_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
                    $authenticated = true;
                }
            }
        }
        if ($tokenProvided && !$authenticated) {
            return $this->jsonResponse($response, ['success' => false, 'message' => __('Invalid or expired token.')], 401);
        }

        // Domain validation - skipped for authenticated requests
        if (!$authenticated) {
            $allowedDomains = (array) ($settings['allowed_domains'] ?? []);
            $allowedDomains = array_map('trim', $allowedDomains);
            $allowedDomains = array_filter($allowedDomains, fn ($v) => $v !== '');
            if ($allowedDomains !== []) {
                $origin  = $request->getHeaderLine('Origin');
                $referer = $request->getHeaderLine('Referer');
                $source  = $origin ?: $referer;
                if ($source === '') {
                    return $this->jsonResponse($response, ['success' => false, 'message' => __('Origin header required when domain restriction is enabled.')], 403);
                }
                if (!$this->isOriginAllowed($source, $allowedDomains)) {
                    return $this->jsonResponse($response, ['success' => false, 'message' => __('Unauthorized domain.')], 403);
                }
                $this->corsOrigin = $origin ?: null;
            } else {
                $origin = $request->getHeaderLine('Origin');
                if ($origin !== '') {
                    $this->corsOrigin = $origin;
                }
            }
        } else {
            $origin = $request->getHeaderLine('Origin');
            if ($origin !== '') {
                $this->corsOrigin = $origin;
            }
        }

        $body = (array) $request->getParsedBody();

        // Support JSON body
        if ($body === [] && str_starts_with($request->getHeaderLine('Content-Type'), 'application/json')) {
            $decoded = json_decode((string) $request->getBody(), true);
            $body    = is_array($decoded) ? $decoded : [];
        }

        // Check Access Control for external submissions
        $onlyRegistered = !empty($settings['only_registered']);
        $accessTags     = array_values(array_filter((array) ($settings['access_tags'] ?? [])));
        $submittedEmail = trim((string) ($body['fields']['email'] ?? ''));

        if ($onlyRegistered || $accessTags !== []) {
            // Check if user is registered (in users or active subscribers)
            $userFound       = false;
            $subscriberFound = null;

            if ($submittedEmail !== '' && filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
                try {
                    $userFound = $this->users->findByEmail(new Email($submittedEmail)) !== null;
                } catch (\Throwable) {
                    $userFound = false;
                }
                $subscriberFound = $this->subscribers->findByEmail($submittedEmail);
                if ($subscriberFound !== null && !$subscriberFound->isActive()) {
                    $subscriberFound = null;
                }
            }

            if ($onlyRegistered && !$authenticated) {
                if (!$userFound && $subscriberFound === null) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => __('Submissions for this form are restricted to registered users.'),
                    ], 403);
                }
            }

            if ($accessTags !== [] && $this->settings->get('membership_enabled', '1') === '1') {
                $hasTag = false;
                if ($subscriberFound !== null) {
                    $userTags = $subscriberFound->getTags();
                    $hasTag   = !empty(array_intersect($accessTags, $userTags));
                }
                if (!$hasTag) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => __('Submissions for this form require an active membership.'),
                    ], 403);
                }
            }
        }

        // Validate required fields
        $missing = [];
        foreach ($form->getFields() as $field) {
            $type = $field['type'] ?? 'text';
            $key  = $type === 'email' ? 'email' : ($field['name'] ?? '');
            if ($key !== '' && !empty($field['required'])) {
                $value = $body['fields'][$key] ?? '';
                if (trim((string) $value) === '') {
                    $missing[] = $field['label'] ?? $key;
                }
            }
        }
        if ($missing !== []) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => __('Required fields: :fields', [':fields' => implode(', ', $missing)]),
            ], 422);
        }

        // Honeypot validation
        if (!empty($settings['honeypot_enabled'])) {
            $honeypotField = $form->getHoneypotFieldName();
            if (!empty($body[$honeypotField])) {
                return $this->jsonResponse($response, ['success' => true, 'message' => $form->getSuccessMessage() ?: __('Thank you! Your message has been sent.')]);
            }
        }

        return $this->processSubmission($request, $response, $form, $body, true);
    }

    private function processSubmission(ServerRequestInterface $request, ResponseInterface $response, Form $form, array $body, bool $isExternal): ResponseInterface
    {
        // Build submission data from form fields
        $data = [];
        foreach ($form->getFields() as $field) {
            $type = $field['type'] ?? 'text';
            if ($type === 'email') {
                $data['email'] = $body['fields']['email'] ?? '';
                continue;
            }
            $key = $field['name'] ?? '';
            if ($key !== '') {
                $data[$key] = $body['fields'][$key] ?? '';
            }
        }

        $fallbackFlags = $this->buildFormFallbackFlags($form);

        // Prevent duplicate submissions when multiple submissions are disabled
        $formSettings = $form->getSettings();
        if (($formSettings['allow_multiple'] ?? true) === false) {
            $hasEmailField = false;
            foreach ($form->getFields() as $field) {
                if (($field['type'] ?? '') === 'email') {
                    $hasEmailField = true;
                    break;
                }
            }
            if ($hasEmailField) {
                $email = trim((string) ($data['email'] ?? ''));
                if ($email !== '' && $this->forms->hasSubmissionWithEmail($form->getId(), $email)) {
                    $message = __('You have already submitted this form.');
                    if ($isExternal || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                        return $this->jsonResponse($response, [
                            'success'           => false,
                            'message'           => $message,
                            'already_submitted' => true,
                        ], 422);
                    }
                    return $this->twig->render($response, 'forms/show.twig', [
                        'form'                              => $form,
                        'success'                           => false,
                        'errors'                            => [$message],
                        'already_submitted'                 => true,
                        'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
                        'content_language'                  => $fallbackFlags['content_language'],
                        'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
                    ]);
                }
            }
        }

        $serverParams = $request->getServerParams();
        $ipMode       = $this->settings->get('ip_privacy_mode', 'mask') ?? 'mask';

        // FormSubmission's constructor only takes (formId, data) — set IP/UA via
        // the setters so the values are actually persisted (see setIpAddress).
        $submission = new FormSubmission($form->getId(), $data);
        $submission->setIpAddress($this->ipAnonymizer->anonymize(vl_client_ip($request), $ipMode));
        $submission->setUserAgent($serverParams['HTTP_USER_AGENT'] ?? null);
        $this->forms->saveSubmission($submission);

        // Dispatch Webhook
        $this->webhooks->dispatch('form.submitted', [
            'form_id'       => $form->getId(),
            'form_name'     => $form->getName(),
            'form_slug'     => (string) $form->getSlug(),
            'submission_id' => $submission->getId(),
            'fields'        => $data,
            'ip_address'    => $submission->getIpAddress(),
            'user_agent'    => $submission->getUserAgent(),
            'submitted_at'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        // Notify admin (non-fatal: submission is already saved)
        if ($form->getNotifyEmail() !== null) {
            $adminLocale = $this->settings->get('locale', 'en');
            try {
                $this->mail->send(new MailMessage(
                    $form->getNotifyEmail(),
                    $this->mail->getTranslator()->get('New form submission', [], $adminLocale) . ': ' . $form->getName(),
                    $this->buildEmailBody($data),
                ));
            } catch (\Throwable $e) {
                error_log('[Form] Admin notification failed for form #' . $form->getId() . ': ' . $e->getMessage());
            }
        }

        // Newsletter subscribe
        $this->handleNewsletterSubscribe($form, $data, $serverParams, $request);

        $successMessage = $form->getSuccessMessage() ?: __('Thank you! Your message has been sent.');

        if ($isExternal || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => $successMessage,
            ]);
        }

        return $this->twig->render($response, 'forms/show.twig', [
            'form'                              => $form,
            'success'                           => true,
            'message'                           => $successMessage,
            'is_fallback_lang'                  => $fallbackFlags['is_fallback'],
            'content_language'                  => $fallbackFlags['content_language'],
            'has_translation_in_current_locale' => $fallbackFlags['has_translation'],
        ]);
    }

    public function preflightExternal(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->corsOrigin = null;
        try {
            $slug = new Slug($args['slug']);
        } catch (DomainException) {
            return $this->jsonResponse($response, ['success' => false, 'message' => __('Invalid slug.')], 404);
        }

        $form = $this->forms->findBySlug($slug);
        if ($form === null || !$form->isEnabled()) {
            return $this->jsonResponse($response, ['success' => false, 'message' => __('Form not found or disabled.')], 404);
        }

        $settings = $form->getSettings();
        if (empty($settings['allow_external'])) {
            return $this->jsonResponse($response, ['success' => false, 'message' => __('External submissions are not allowed for this form.')], 403);
        }

        $allowedDomains = (array) ($settings['allowed_domains'] ?? []);
        $allowedDomains = array_map('trim', $allowedDomains);
        $allowedDomains = array_filter($allowedDomains, fn ($v) => $v !== '');

        $origin = $request->getHeaderLine('Origin');
        if ($allowedDomains !== []) {
            if ($origin === '' || !$this->isOriginAllowed($origin, $allowedDomains)) {
                return $this->jsonResponse($response, ['success' => false, 'message' => __('Unauthorized domain.')], 403);
            }
        }

        if ($origin !== '') {
            $this->corsOrigin = $origin;
        }

        $response = $response->withStatus(204);
        if ($this->corsOrigin !== null) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $this->corsOrigin)
                ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept')
                ->withHeader('Vary', 'Origin');
        }
        return $response;
    }

    /**
     * Check if a given origin or referer URL/host matches the list of allowed domains.
     *
     * @param string   $source         Origin or Referer URL/host string
     * @param string[] $allowedDomains List of allowed domains/hosts
     */
    private function isOriginAllowed(string $source, array $allowedDomains): bool
    {
        $parts = parse_url($source);
        if (!is_array($parts) || empty($parts['host'])) {
            $parts = parse_url('http://' . $source);
        }

        $sourceHost = strtolower((string) ($parts['host'] ?? ''));
        if ($sourceHost === '') {
            return false;
        }

        $sourcePort     = isset($parts['port']) ? ':' . $parts['port'] : '';
        $sourceWithPort = $sourceHost . $sourcePort;

        foreach ($allowedDomains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '') {
                continue;
            }

            // Exact match (either host or host:port)
            if ($sourceHost === $domain || $sourceWithPort === $domain) {
                return true;
            }

            // Subdomain matching (e.g. sub.example.com matches example.com)
            $domainHost = explode(':', $domain)[0];
            if ($domainHost !== '' && str_ends_with($sourceHost, '.' . $domainHost)) {
                return true;
            }
        }

        return false;
    }

    private function jsonResponse(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data));
        $response = $response->withHeader('Content-Type', 'application/json')->withStatus($status);
        if ($this->corsOrigin !== null) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $this->corsOrigin)
                ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept')
                ->withHeader('Vary', 'Origin');
        }
        return $response;
    }

    /** @return array{captcha_type: string, captcha_question: ?string, captcha_site_key: ?string} */
    private function buildFormCaptchaData(Form $form): array
    {
        $type     = $form->getCaptchaType();
        $question = null;
        $siteKey  = null;

        if ($type === 'math') {
            [$a, $b] = [random_int(1, 9), random_int(1, 9)];
            $this->session->set('captcha_answer', $a + $b);
            $question = "{$a} + {$b}";
        } elseif ($type === 'hcaptcha') {
            $siteKey = $this->settings->get('hcaptcha_site_key', '');
        } elseif ($type === 'recaptcha') {
            $siteKey = $this->settings->get('recaptcha_site_key', '');
        } elseif ($type === 'turnstile') {
            $siteKey = $this->settings->get('turnstile_site_key', '');
        }

        return [
            'captcha_type'     => $type,
            'captcha_question' => $question,
            'captcha_site_key' => $siteKey,
        ];
    }

    private function validateFormCaptcha(Form $form, array $body): ?string
    {
        $type = $form->getCaptchaType();

        if ($type === 'math') {
            $expected = (int) $this->session->get('captcha_answer', 0);
            $given    = (int) ($body['captcha'] ?? -999);
            $this->session->remove('captcha_answer');
            if ($given !== $expected || $expected === 0) {
                return 'Incorrect answer. Please try again.';
            }
        } elseif ($type === 'hcaptcha') {
            $token  = trim($body['h-captcha-response'] ?? '');
            $secret = (string) ($this->settings->get('hcaptcha_secret_key', '') ?? '');
            $res    = $this->verifyCaptchaToken(
                CaptchaUrls::fromFile()->verifyUrl('hcaptcha'),
                $secret,
                $token,
            );
            if (!$res['success']) {
                return 'Captcha verification failed.';
            }
        } elseif ($type === 'recaptcha') {
            $token  = trim($body['g-recaptcha-response'] ?? '');
            $secret = (string) ($this->settings->get('recaptcha_secret_key', '') ?? '');
            $res    = $this->verifyCaptchaToken(
                CaptchaUrls::fromFile()->verifyUrl('recaptcha'),
                $secret,
                $token,
            );
            if (!$res['success']) {
                return 'Captcha verification failed.';
            }

            $threshold = (float) $this->settings->get('recaptcha_threshold', '0.5');
            if ($res['score'] !== null && $res['score'] < $threshold) {
                return 'Captcha verification failed (low score).';
            }
        } elseif ($type === 'turnstile') {
            $token  = trim($body['cf-turnstile-response'] ?? '');
            $secret = (string) ($this->settings->get('turnstile_secret_key', '') ?? '');
            $res    = $this->verifyCaptchaToken(
                CaptchaUrls::fromFile()->verifyUrl('turnstile'),
                $secret,
                $token,
            );
            if (!$res['success']) {
                return 'Captcha verification failed.';
            }
        }

        return null;
    }

    private function buildEmailBody(array $data): string
    {
        $rows = '';
        foreach ($data as $key => $value) {
            $k = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');
            $v = is_array($value) ? implode(', ', $value) : (string) $value;
            $v = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $rows .= "<tr><th style='text-align:left;padding:4px 8px;'>{$k}</th><td style='padding:4px 8px;'>{$v}</td></tr>";
        }
        return "<table border='1' cellpadding='4' cellspacing='0'>{$rows}</table>";
    }

    private function handleNewsletterSubscribe(Form $form, array $data, array $serverParams, ServerRequestInterface $request): void
    {
        // Find newsletter_subscribe field
        $subscribeField = null;
        $subscribeTags  = [];
        $hasEmailField  = false;
        $nameField      = null;
        foreach ($form->getFields() as $field) {
            if (($field['type'] ?? '') === 'newsletter_subscribe') {
                $subscribeField = $field['name'] ?? '';
                $subscribeTags  = $this->normalizeSubscribeTags($field['tags'] ?? []);
            }
            if (($field['type'] ?? '') === 'email') {
                $hasEmailField = true;
            }
            if (($field['type'] ?? '') === 'text' && str_contains(strtolower($field['name'] ?? ''), 'name')) {
                $nameField = $field['name'] ?? '';
            }
        }

        // Only proceed if form has the subscribe field and user checked it
        if ($subscribeField === null || empty($data[$subscribeField])) {
            return;
        }

        // Must have an email field value
        $email = $hasEmailField ? trim((string) ($data['email'] ?? '')) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Check if subscriber already exists
        $existing = $this->subscribers->findByEmail($email);
        if ($existing !== null && $existing->isActive()) {
            // Already active: merge any new auto tags (no confirmation mail).
            $added = $this->mergeSubscribeTags($existing, $subscribeTags);
            if ($added !== []) {
                $this->subscribers->save($existing);
                $this->enrollTagAutomations($existing, $added);
            }
            return;
        }

        if ($existing === null) {
            $subscriber = new Subscriber($email, bin2hex(random_bytes(16)));
            $ipMode     = $this->settings->get('ip_privacy_mode', 'mask') ?? 'mask';
            $subscriber->setIpAddress($this->ipAnonymizer->anonymize(vl_client_ip($request), $ipMode));
            $subscriber->setSourceFormId($form->getId());
            if ($nameField !== null && !empty($data[$nameField])) {
                $subscriber->setName(trim((string) $data[$nameField]));
            }
            if ($subscribeTags !== []) {
                $subscriber->setTags($subscribeTags);
            }
            $added = $subscribeTags;
        } else {
            // Existing pending/unsubscribed entry: merge auto tags, resend confirmation email.
            $subscriber = $existing;
            $added      = $this->mergeSubscribeTags($subscriber, $subscribeTags);
        }

        $this->subscribers->save($subscriber);
        $this->enrollTagAutomations($subscriber, $added);

        // Double opt-in confirmation email (non-fatal: subscriber row is already saved)
        $confirmUrl = $this->getBaseUrl() . '/newsletter/confirm/' . $subscriber->getToken();
        $locale     = $this->translator?->getLocale() ?? 'en';
        try {
            $this->mail->sendTemplate(
                to: $subscriber->getEmail(),
                subject: $this->mail->getTranslator()->get('Confirm your subscription', [], $locale),
                template: 'mail/newsletter-confirmation',
                context: [
                    'confirm_url' => $confirmUrl,
                    'site_url'    => $this->getBaseUrl(),
                    'site_name'   => 'Vale',
                ],
                locale: $locale,
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            error_log('[Form] Newsletter confirmation failed for ' . $subscriber->getEmail() . ': ' . $e->getMessage());
        }
    }

    /**
     * Normalize the auto-tag list stored on a newsletter_subscribe field.
     *
     * @param mixed $tags
     *
     * @return string[]
     */
    private function normalizeSubscribeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        if (!is_array($tags)) {
            return [];
        }
        $clean = [];
        foreach ($tags as $tag) {
            $tag = trim(str_replace(',', '', (string) $tag));
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag) > 100) {
                $tag = mb_substr($tag, 0, 100);
            }
            if (!in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
            if (count($clean) >= 20) {
                break;
            }
        }
        return array_values($clean);
    }

    /**
     * Merge auto tags into the subscriber without dropping existing ones.
     *
     * @param string[] $tags
     *
     * @return string[] newly added tags
     */
    private function mergeSubscribeTags(Subscriber $subscriber, array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $current = $subscriber->getTags();
        $added   = array_values(array_diff($tags, $current));
        if ($added === []) {
            return [];
        }
        $subscriber->setTags(array_values(array_merge($current, $added)));
        return $added;
    }

    /** @param string[] $tags */
    private function enrollTagAutomations(Subscriber $subscriber, array $tags): void
    {
        if ($this->automationService === null) {
            return;
        }
        foreach ($tags as $tag) {
            $this->automationService->enrollSubscriberByTag($subscriber, $tag);
        }
    }

    private function getBaseUrl(): string
    {
        return __require_app_url();
    }
}
