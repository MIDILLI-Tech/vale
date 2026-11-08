<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Form\FormCaptchaService;
use ValeApp\Domain\Form\Entity\Form;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Membership\Repository\PlanRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\Shared\ValueObject\Slug;
use ValeApp\Infrastructure\Content\SlugService;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class FormController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly FormRepositoryInterface $forms,
        private readonly SlugService $slugService,
        private readonly SessionManager $session,
        private readonly DbalSettingsRepository $settings,
        private readonly PlanRepositoryInterface $plans,
        private readonly FormCaptchaService $formCaptcha,
        private readonly SubscriberRepositoryInterface $subscribers,
    ) {
    }

    /**
     * Blocks saving a form whose selected captcha provider has no API keys —
     * such a form would render for visitors but reject every submission.
     */
    private function captchaConfigError(Form $form): ?string
    {
        if (!$this->formCaptcha->isMisconfigured($form)) {
            return null;
        }

        // The warning sign stays outside the translatable text: the
        // translation extractor mangles non-ASCII characters in keys.
        return '⚠ ' . __('This captcha is not configured in Privacy settings.')
            . ' <a href="' . admin_url('settings?tab=privacy&subtab=captcha') . '" target="_blank" rel="noopener"'
            . ' class="underline font-semibold">' . __('Open settings') . '</a>';
    }

    /** @return string[] */
    private function getMembershipTagNames(): array
    {
        if ($this->settings->get('membership_enabled', '1') !== '1') {
            return [];
        }
        $plans = $this->plans->findAll();
        $tags  = [];
        foreach ($plans as $plan) {
            if ($plan->getTag() !== '' && !in_array($plan->getTag(), $tags, true)) {
                $tags[] = $plan->getTag();
            }
            if ($plan->getTrialTag() !== null && $plan->getTrialTag() !== '' && !in_array($plan->getTrialTag(), $tags, true)) {
                $tags[] = $plan->getTrialTag();
            }
        }
        sort($tags);
        return $tags;
    }

    public function index(Request $request, Response $response): Response
    {
        $params   = $request->getQueryParams();
        $lang     = $params['language'] ?? null;
        if ($lang === '') {
            $lang = null;
        }

        $multiLanguageContent = $this->settings->get('multi_language_content', '0') === '1';
        $forms                = $this->forms->findAll($multiLanguageContent ? $lang : null);

        return $this->twig->render($response, 'admin/forms/index.twig', [
            'forms'                  => $forms,
            'filters'                => $params,
            'multi_language_content' => $multiLanguageContent,
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'current_locale'         => $lang,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $lang          = $request->getQueryParams()['language']       ?? $this->translator->getLocale();
        $translationOf = $request->getQueryParams()['translation_of'] ?? null;
        $translations  = $translationOf ? $this->forms->findTranslations((int) $translationOf) : [];

        $potentialOriginals = $this->forms->findPotentialOriginals(language: $lang);
        if ($translationOf) {
            $found = false;
            foreach ($potentialOriginals as $po) {
                if ((int) $po->getId() === (int) $translationOf) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $original = $this->forms->findById((int) $translationOf);
                if ($original && $original->getTranslationOf() === null) {
                    array_unshift($potentialOriginals, $original);
                }
            }
        }

        return $this->twig->render($response, 'admin/forms/form.twig', [
            'form'                   => null,
            'is_edit'                => false,
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $potentialOriginals,
            'current_locale'         => $lang,
            'translations'           => $translations,
            'original_id'            => $translationOf,
            'prefill_translation_of' => $translationOf,
            'membership_tags'        => $this->getMembershipTagNames(),
            'subscriber_tags'        => $this->subscribers->getAllTags(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '');
        if ($name === '') {
            $this->session->flash('error', __('Form name is required.'));
            return $response->withHeader('Location', admin_url('/forms/create'))->withStatus(302);
        }

        $lang          = $body['language'] ?? 'en';
        $translationOf = isset($body['translation_of']) && $body['translation_of'] !== '' ? (int) $body['translation_of'] : null;
        if ($translationOf !== null) {
            $original = $this->forms->findById($translationOf);
            if ($original === null) {
                $this->session->flash('error', $this->translator->get('Selected original not found.'));
                return $response->withHeader('Location', admin_url('/forms/create'))->withStatus(302);
            }
            if ($original->getLanguage() === $lang) {
                $this->session->flash('error', $this->translator->get('Cannot translate into the same language.'));
                return $response->withHeader('Location', admin_url('/forms/create'))->withStatus(302);
            }
            foreach ($this->forms->findTranslations($translationOf) as $existing) {
                if ($existing->getLanguage() === $lang) {
                    $this->session->flash('error', $this->translator->get('This original already has a translation in this language.'));
                    return $response->withHeader('Location', admin_url('/forms/create'))->withStatus(302);
                }
            }
        }

        $fieldsJson = $body['fields_json']                    ?? '[]';
        $rawFields  = json_decode((string) $fieldsJson, true) ?? [];
        foreach ($rawFields as $i => $rf) {
            $type = strtolower(trim((string) ($rf['type'] ?? 'text')));
            if ($type !== 'static_text' && $type !== 'newsletter_subscribe' && $type !== 'email') {
                $fName = trim((string) ($rf['name'] ?? ''));
                if ($fName === '') {
                    $this->session->flash('error', __('Field key (Name) is required for form fields.'));
                    return $response->withHeader('Location', admin_url('/forms/create'))
                        ->withStatus(302);
                }
            }
        }

        $rawSlug = trim((string) ($body['slug'] ?? ''));
        $slug    = $rawSlug !== ''
            ? $this->slugService->unique($rawSlug, VL_DB_PREFIX . 'forms', 'slug')
            : $this->slugService->generate($name, VL_DB_PREFIX . 'forms', 'slug');
        $form    = new Form($name, $slug);
        $form->setLanguage($lang);
        $form->setTranslationOf($translationOf);
        $this->fillForm($form, $body);

        $captchaError = $this->captchaConfigError($form);
        if ($captchaError !== null) {
            $this->session->flash('error', $captchaError);
            return $response->withHeader('Location', admin_url('/forms/create'))->withStatus(302);
        }

        $this->forms->save($form);
        $this->session->flash('success', __('Form created successfully.'));
        return $response->withHeader('Location', admin_url('/forms'))->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $form = $this->forms->findById((int) $args['id']);
        if ($form === null) {
            return $response->withStatus(404);
        }

        $translationOf = $form->getTranslationOf();
        $translations  = $translationOf ? $this->forms->findTranslations((int) $translationOf) : ($form->getId() ? $this->forms->findTranslations($form->getId()) : []);

        $potentialOriginals = $this->forms->findPotentialOriginals($form->getId(), $form->getLanguage());
        if ($translationOf) {
            $found = false;
            foreach ($potentialOriginals as $po) {
                if ((int) $po->getId() === (int) $translationOf) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $original = $this->forms->findById((int) $translationOf);
                if ($original && $original->getTranslationOf() === null) {
                    array_unshift($potentialOriginals, $original);
                }
            }
        }

        return $this->twig->render($response, 'admin/forms/form.twig', [
            'form'                   => $form,
            'is_edit'                => true,
            'multi_language_content' => $this->settings->get('multi_language_content', '0') === '1',
            'supported_locales'      => $this->translator->getAvailableLocales(),
            'potential_originals'    => $potentialOriginals,
            'current_locale'         => $form->getLanguage(),
            'translations'           => $translations,
            'original_id'            => $translationOf ?: $form->getId(),
            'membership_tags'        => $this->getMembershipTagNames(),
            'subscriber_tags'        => $this->subscribers->getAllTags(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $form = $this->forms->findById((int) $args['id']);
        if ($form === null) {
            return $response->withStatus(404);
        }

        $body = (array) $request->getParsedBody();
        $name = trim($body['name'] ?? '');
        if ($name !== '') {
            $form->setName($name);
        }

        $rawSlug = trim((string) ($body['slug'] ?? ''));
        if ($rawSlug !== '') {
            $slug = $this->slugService->unique(
                $rawSlug,
                VL_DB_PREFIX . 'forms',
                'slug',
                $form->getId()
            );
            $form->setSlug($slug);
        }

        $fieldsJson = $body['fields_json']                    ?? '[]';
        $rawFields  = json_decode((string) $fieldsJson, true) ?? [];
        foreach ($rawFields as $i => $rf) {
            $type = strtolower(trim((string) ($rf['type'] ?? 'text')));
            if ($type !== 'static_text' && $type !== 'newsletter_subscribe' && $type !== 'email') {
                $fName = trim((string) ($rf['name'] ?? ''));
                if ($fName === '') {
                    $this->session->flash('error', __('Field key (Name) is required for form fields.'));
                    return $response->withHeader('Location', admin_url('/forms/' . $args['id'] . '/edit'))
                        ->withStatus(302);
                }
            }
        }

        if (isset($body['language'])) {
            $form->setLanguage((string) $body['language']);
        }
        if (isset($body['translation_of']) && $body['translation_of'] !== '') {
            $newId    = (int) $body['translation_of'];
            $original = $this->forms->findById($newId);
            if ($original === null) {
                $this->session->flash('error', $this->translator->get('Selected original not found.'));
                return $response->withHeader('Location', admin_url('/forms/' . $args['id'] . '/edit'))->withStatus(302);
            }
            $targetLanguage = (string) ($body['language'] ?? $form->getLanguage());
            if ($original->getLanguage() === $targetLanguage) {
                $this->session->flash('error', $this->translator->get('Cannot translate into the same language.'));
                return $response->withHeader('Location', admin_url('/forms/' . $args['id'] . '/edit'))->withStatus(302);
            }
            foreach ($this->forms->findTranslations($newId) as $existing) {
                if ($existing->getLanguage() === $targetLanguage && (int) $existing->getId() !== (int) $args['id']) {
                    $this->session->flash('error', $this->translator->get('This original already has a translation in this language.'));
                    return $response->withHeader('Location', admin_url('/forms/' . $args['id'] . '/edit'))->withStatus(302);
                }
            }
        }

        if (array_key_exists('translation_of', $body)) {
            $form->setTranslationOf($body['translation_of'] !== '' ? (int) $body['translation_of'] : null);
        }

        $this->fillForm($form, $body);

        $captchaError = $this->captchaConfigError($form);
        if ($captchaError !== null) {
            $this->session->flash('error', $captchaError);
            return $response->withHeader('Location', admin_url('/forms/' . $args['id'] . '/edit'))
                ->withStatus(302);
        }

        $this->forms->save($form);
        $this->session->flash('success', __('Form updated.'));
        return $response->withHeader('Location', admin_url('/forms/' . $args['id'] . '/edit'))->withStatus(302);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->forms->delete((int) $args['id']);
        $this->session->flash('success', __('Form deleted.'));
        return $response->withHeader('Location', admin_url('/forms'))->withStatus(302);
    }

    public function cloneForm(Request $request, Response $response, array $args): Response
    {
        $source = $this->forms->findById((int) $args['id']);
        if ($source === null) {
            return $response->withStatus(404);
        }

        $copyName = $source->getName() . ' (Copy)';
        $slug     = $this->slugService->generate($copyName, VL_DB_PREFIX . 'forms', 'slug');

        $copy = new Form($copyName, $slug);
        $copy->setLanguage($source->getLanguage());
        $copy->setTranslationOf($source->getTranslationOf());
        $copy->setFields($source->getFields());
        $copy->setSettings($source->getSettings());
        $copy->setNotifyEmail($source->getNotifyEmail());
        $copy->setSubmitLabel($source->getSubmitLabel());
        $copy->setSuccessMessage($source->getSuccessMessage());
        $copy->setEnabled($source->isEnabled());

        $captchaError = $this->captchaConfigError($copy);
        if ($captchaError !== null) {
            $this->session->flash('error', $captchaError);
            return $response->withHeader('Location', admin_url('/forms'))->withStatus(302);
        }

        $this->forms->save($copy);
        $this->session->flash('success', __('Form cloned.'));
        return $response->withHeader('Location', admin_url('/forms'))->withStatus(302);
    }

    public function destroySubmission(Request $request, Response $response, array $args): Response
    {
        $formId = (int) $args['id'];
        $subId  = (int) $args['subId'];
        $this->forms->deleteSubmission($subId);
        $this->session->flash('success', __('Submission deleted.'));
        return $response->withHeader('Location', admin_url('/forms/' . $formId . '/submissions'))->withStatus(302);
    }

    /** POST /admin/bulk/forms */
    public function bulkAction(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $rawIds = $body['ids'] ?? '[]';
        if (is_string($rawIds) && str_contains($rawIds, ',')) {
            $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        } else {
            $ids = array_filter(array_map('intval', is_array($rawIds) ? $rawIds : (array) json_decode((string) $rawIds, true)));
        }
        $action = $body['action'] ?? '';

        if ($ids === []) {
            $this->session->set('flash_error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/forms'))->withStatus(302);
        }

        $count = 0;
        foreach ($ids as $id) {
            $id   = (int) $id;
            $form = $this->forms->findById($id);
            if ($form === null) {
                continue;
            }

            if ($action === 'delete') {
                $this->forms->delete($id);
                $count++;
            } elseif (in_array($action, ['activate', 'deactivate'], true)) {
                $form->setEnabled($action === 'activate');
                $this->forms->save($form);
                $count++;
            }
        }

        $this->session->set('flash_success', $this->translator->get(':count form(s) updated.', [':count' => (string) $count]));
        return $response->withHeader('Location', admin_url('/forms'))->withStatus(302);
    }

    /** POST /admin/bulk/submissions */
    public function bulkSubmissionsAction(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $rawIds = $body['ids'] ?? '[]';
        if (is_string($rawIds) && str_contains($rawIds, ',')) {
            $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        } else {
            $ids = array_filter(array_map('intval', is_array($rawIds) ? $rawIds : (array) json_decode((string) $rawIds, true)));
        }
        $action = $body['action'] ?? '';

        if ($ids === []) {
            $this->session->set('flash_error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/forms'))->withStatus(302);
        }

        $referer = $request->getHeaderLine('Referer');
        $count   = 0;

        foreach ($ids as $id) {
            if ($action === 'delete') {
                $this->forms->deleteSubmission((int) $id);
                $count++;
            } elseif ($action === 'mark-read') {
                $this->forms->markSubmissionRead((int) $id);
                $count++;
            }
        }

        $labels = [
            'delete'    => ':count submission(s) deleted.',
            'mark-read' => ':count submission(s) marked as read.',
        ];
        $msg = $labels[$action] ?? ':count submission(s) updated.';
        $this->session->set('flash_success', $this->translator->get($msg, [':count' => (string) $count]));

        if ($referer && str_contains($referer, 'submissions')) {
            return $response->withHeader('Location', $referer)->withStatus(302);
        }

        return $response->withHeader('Location', admin_url('/forms'))->withStatus(302);
    }

    public function formsJson(Request $request, Response $response): Response
    {
        $forms = $this->forms->findAll();
        $data  = array_map(fn (Form $f) => [
            'id'   => $f->getId(),
            'name' => $f->getName(),
            'slug' => (string) $f->getSlug(),
        ], $forms);
        $response->getBody()->write(json_encode(array_values($data)));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function formsJsonSingle(Request $request, Response $response, array $args): Response
    {
        $form = $this->forms->findById((int) $args['id']);
        if ($form === null) {
            return $response->withStatus(404);
        }

        $data = [
            'id'              => $form->getId(),
            'name'            => $form->getName(),
            'slug'            => (string) $form->getSlug(),
            'fields'          => $form->getFields(),
            'settings'        => $form->getSettings(),
            'notify_email'    => $form->getNotifyEmail(),
            'submit_label'    => $form->getSubmitLabel(),
            'success_message' => $form->getSuccessMessage(),
            'enabled'         => $form->isEnabled(),
        ];

        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function submissions(Request $request, Response $response, array $args): Response
    {
        $form = $this->forms->findById((int) $args['id']);
        if ($form === null) {
            return $response->withStatus(404);
        }

        $multiLanguageContent = $this->settings->get('multi_language_content', '0') === '1';
        $groupForms           = [$form];
        $selectedLang         = $request->getQueryParams()['lang'] ?? 'all';

        if ($multiLanguageContent) {
            $originalId = $form->getTranslationOf() ?? $form->getId();
            $groupForms = $this->forms->findTranslations($originalId);
            // Index the group forms by ID to have quick lookups
            $groupFormsMap = [];
            foreach ($groupForms as $gf) {
                $groupFormsMap[$gf->getId()] = $gf;
            }
        } else {
            $selectedLang  = 'all';
            $groupFormsMap = [$form->getId() => $form];
        }

        // Get array of form IDs we care about
        $formIds = array_keys($groupFormsMap);

        // If a specific language is selected, filter to that form
        if ($selectedLang !== 'all') {
            $filteredFormIds = [];
            foreach ($groupForms as $gf) {
                if ($gf->getLanguage() === $selectedLang) {
                    $filteredFormIds[] = $gf->getId();
                }
            }
            if (empty($filteredFormIds)) {
                $filteredFormIds = [$form->getId()];
            }
        } else {
            $filteredFormIds = $formIds;
        }

        // Mark submissions of selected/filtered forms as read
        $this->forms->markSubmissionsAsReadForForms($filteredFormIds);

        // Get submissions for filtered forms
        $submissions = $this->forms->findAllSubmissionsForForms($filteredFormIds);

        // Get counts for tabs
        $counts = ['all' => count($this->forms->findAllSubmissionsForForms($formIds))];
        foreach ($groupForms as $gf) {
            $counts[$gf->getLanguage()] = count($this->forms->findAllSubmissionsForForms([$gf->getId()]));
        }

        return $this->twig->render($response, 'admin/forms/submissions.twig', [
            'form'                   => $form,
            'submissions'            => $submissions,
            'total'                  => count($submissions),
            'multi_language_content' => $multiLanguageContent,
            'group_forms'            => $groupForms,
            'selected_lang'          => $selectedLang,
            'counts'                 => $counts,
            'form_map'               => $groupFormsMap,
        ]);
    }

    /**
     * Normalize the auto-tag list of a newsletter_subscribe field.
     *
     * @param mixed $tags
     *
     * @return string[]
     */
    private static function normalizeTags(mixed $tags): array
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
     * Normalize select/checkbox/radio options into {value, title} pairs.
     * Legacy plain-string options are upgraded; entries without a value are dropped.
     *
     * @return array<int, array{value: string, title: string}>
     */
    private static function normalizeFieldOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $normalized = [];
        foreach ($raw as $option) {
            if (is_array($option)) {
                $value = is_scalar($option['value'] ?? null) ? trim((string) $option['value']) : '';
                $title = is_scalar($option['title'] ?? null) ? trim((string) $option['title']) : '';
            } elseif (is_scalar($option) || $option === null) {
                $value = trim((string) $option);
                $title = '';
            } else {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $normalized[] = ['value' => $value, 'title' => mb_substr($title, 0, 255)];
        }
        return $normalized;
    }

    private function fillForm(Form $form, array $body): void
    {
        // Fields come as JSON string from the builder
        $fieldsJson = $body['fields_json']           ?? '[]';
        $fields     = json_decode($fieldsJson, true) ?? [];

        // Auto-generate field name from label if empty
        foreach ($fields as &$f) {
            $type = strtolower(trim((string) ($f['type'] ?? 'text')));
            if ($type === 'static_text') {
                $f['name']     = '';
                $f['required'] = false;
                $f['options']  = [];
                unset($f['_optionsText'], $f['_titlesText'], $f['tags'], $f['placeholder']);
                continue;
            }
            if ($type === 'newsletter_subscribe') {
                $f['required'] = false;
                if (empty($f['name'])) {
                    $f['name'] = !empty($f['label'])
                        ? preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($f['label'])))
                        : 'subscribe_to_newsletter';
                    $f['name'] = trim($f['name'], '_');
                }
                $f['tags'] = self::normalizeTags($f['tags'] ?? []);
                unset($f['_optionsText'], $f['_titlesText'], $f['options'], $f['placeholder']);
                continue;
            }
            if ($type === 'email') {
                $f['name'] = 'email';
                unset($f['_optionsText'], $f['_titlesText'], $f['tags']);
                continue;
            }
            if (empty($f['name']) && !empty($f['label'])) {
                $f['name'] = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($f['label'])));
                $f['name'] = trim($f['name'], '_');
            }

            // Optional placeholder text shown inside text-like inputs
            if (array_key_exists('placeholder', $f)) {
                $placeholder = is_string($f['placeholder']) ? trim($f['placeholder']) : '';
                if ($placeholder === '') {
                    unset($f['placeholder']);
                } else {
                    $f['placeholder'] = mb_substr($placeholder, 0, 255);
                }
            }

            // Options with optional tooltip titles (select / checkbox / radio)
            if (in_array($type, ['select', 'checkbox', 'radio'], true)) {
                $f['options'] = self::normalizeFieldOptions($f['options'] ?? []);
            } else {
                unset($f['options']);
            }

            // Remove internal Alpine-only keys
            unset($f['_optionsText'], $f['_titlesText'], $f['tags']);
        }
        unset($f);

        $form->setFields($fields);

        $captchaType = $body['captcha_type'] ?? 'none';
        if (!in_array($captchaType, ['none', 'honeypot', 'math', 'hcaptcha', 'recaptcha', 'turnstile'], true)) {
            $captchaType = 'none';
        }

        $allowedDomains = array_filter(array_map(function ($d) {
            $d = trim($d);
            $d = preg_replace('#^https?://#i', '', $d);
            return rtrim($d, '/');
        }, explode("\n", (string) ($body['allowed_domains'] ?? ''))));
        $accessTags     = !empty($body['access_tags']) ? (array) $body['access_tags'] : [];

        $settings = [
            'captcha'             => $captchaType !== 'none',
            'captcha_type'        => $captchaType,
            'allow_external'      => isset($body['allow_external']),
            'allowed_domains'     => array_values($allowedDomains),
            'honeypot_enabled'    => isset($body['honeypot_enabled']),
            'honeypot_field_name' => !empty($body['honeypot_field_name'])
                ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $body['honeypot_field_name'])
                : '_hp_name',
            'allow_multiple'   => (bool) ($body['allow_multiple'] ?? '1'),
            'only_registered'  => isset($body['only_registered']),
            'access_tags'      => array_values(array_filter(array_map('trim', $accessTags))),
        ];
        $form->setSettings($settings);
        $form->setNotifyEmail(!empty($body['notify_email']) ? $body['notify_email'] : null);
        $form->setSubmitLabel(!empty($body['submit_label']) ? $body['submit_label'] : 'Submit');
        $form->setSuccessMessage(!empty($body['success_message']) ? $body['success_message'] : null);
        $form->setEnabled(isset($body['enabled']));
    }
}
