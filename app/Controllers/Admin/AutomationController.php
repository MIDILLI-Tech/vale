<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Newsletter\Entity\AutomationSequence;
use ValeApp\Domain\Newsletter\Entity\AutomationStep;
use ValeApp\Domain\Newsletter\Repository\AutomationRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class AutomationController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly AutomationRepositoryInterface $automations,
        private readonly AutomationService $automationService,
        private readonly SessionManager $session,
        private readonly FormRepositoryInterface $forms,
        private readonly DbalSettingsRepository $settings,
        private readonly SubscriberRepositoryInterface $subscribers,
    ) {
    }

    // ── List ──────────────────────────────────────────────────────

    public function index(Request $request, Response $response): Response
    {
        // Lazily process a small batch on each page load
        $this->automationService->processDue(10);

        $sequences     = $this->automations->findAllSequences();
        $counts        = [];
        foreach ($sequences as $seq) {
            $counts[$seq->getId()] = $this->automations->getEnrollmentCounts($seq->getId());
        }
        $stepSummaries = $this->automations->getStepSummaries();
        $cronConfig    = $this->buildCronConfig();

        return $this->twig->render($response, 'admin/newsletter/automations.twig', [
            'sequences'     => $sequences,
            'counts'        => $counts,
            'stepSummaries' => $stepSummaries,
            'flash_success' => $this->session->flash('flash_success'),
            'flash_error'   => $this->session->flash('flash_error'),
            'cronConfig'    => $cronConfig,
            'allTags'       => $this->subscribers->getAllTags(),
        ]);
    }

    // ── Create / Store ────────────────────────────────────────────

    public function create(Request $request, Response $response): Response
    {
        $cronConfig = $this->buildCronConfig();

        return $this->twig->render($response, 'admin/newsletter/automation-form.twig', [
            'sequence'   => null,
            'steps'      => [],
            'forms'      => $this->forms->findAll(),
            'cronConfig' => $cronConfig,
            'allTags'    => $this->subscribers->getAllTags(),
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $errors = $this->validateSequenceInput($body);

        if (!empty($errors)) {
            $this->session->flash('error', implode('; ', $errors));
            return $response->withHeader('Location', '/admin/newsletter/automations/create')->withStatus(302);
        }

        $allowedTriggers = ['subscribe', 'manual'];
        $triggerType     = in_array($body['trigger_type'] ?? '', $allowedTriggers, true)
            ? $body['trigger_type'] : 'subscribe';

        $sequence = new AutomationSequence(
            trim($body['name']),
            $triggerType,
            !empty($body['trigger_form_id']) ? (int) $body['trigger_form_id'] : null,
            !empty($body['trigger_tag']) ? trim($body['trigger_tag']) : null,
            !empty($body['trigger_tag_match']) ? trim($body['trigger_tag_match']) : null,
        );
        $this->applyTracking($sequence, $body);
        $this->automations->saveSequence($sequence);

        $this->saveStepsFromRequest($body, $sequence->getId());

        $this->session->flash('success', $this->translator->get('Automation sequence created.'));
        return $response->withHeader('Location', '/admin/newsletter/automations')->withStatus(302);
    }

    // ── Edit / Update ─────────────────────────────────────────────

    public function edit(Request $request, Response $response, array $args): Response
    {
        $sequence = $this->automations->findSequenceById((int) $args['id']);
        if ($sequence === null) {
            $this->session->flash('error', 'Sequence not found.');
            return $response->withHeader('Location', '/admin/newsletter/automations')->withStatus(302);
        }

        $steps      = $this->automations->findStepsBySequenceId($sequence->getId());
        $cronConfig = $this->buildCronConfig();

        return $this->twig->render($response, 'admin/newsletter/automation-form.twig', [
            'sequence'   => $sequence,
            'steps'      => $steps,
            'forms'      => $this->forms->findAll(),
            'cronConfig' => $cronConfig,
            'allTags'    => $this->subscribers->getAllTags(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $sequence = $this->automations->findSequenceById((int) $args['id']);
        if ($sequence === null) {
            $this->session->flash('error', 'Sequence not found.');
            return $response->withHeader('Location', '/admin/newsletter/automations')->withStatus(302);
        }

        $body   = (array) $request->getParsedBody();
        $errors = $this->validateSequenceInput($body);

        if (!empty($errors)) {
            $this->session->flash('error', implode('; ', $errors));
            return $response->withHeader('Location', '/admin/newsletter/automations/' . $sequence->getId() . '/edit')->withStatus(302);
        }

        $allowedTriggers = ['subscribe', 'manual'];
        $triggerType     = in_array($body['trigger_type'] ?? '', $allowedTriggers, true)
            ? $body['trigger_type'] : 'subscribe';

        $sequence->setName(trim($body['name']));
        $sequence->setTriggerType($triggerType);
        $sequence->setTriggerFormId(!empty($body['trigger_form_id']) ? (int) $body['trigger_form_id'] : null);
        $sequence->setTriggerTag(!empty($body['trigger_tag']) ? trim($body['trigger_tag']) : null);
        $sequence->setTriggerTagMatch(!empty($body['trigger_tag_match']) ? trim($body['trigger_tag_match']) : null);
        $this->applyTracking($sequence, $body);
        $this->automations->saveSequence($sequence);

        // Replace all steps
        $this->automations->deleteStepsBySequenceId($sequence->getId());
        $this->saveStepsFromRequest($body, $sequence->getId());

        $this->session->flash('success', $this->translator->get('Automation sequence updated.'));
        return $response->withHeader('Location', '/admin/newsletter/automations')->withStatus(302);
    }

    // ── Clone ─────────────────────────────────────────────────────

    public function clone(Request $request, Response $response, array $args): Response
    {
        $original = $this->automations->findSequenceById((int) $args['id']);
        if ($original === null) {
            $this->session->flash('error', 'Sequence not found.');
            return $response->withHeader('Location', '/admin/newsletter/automations')->withStatus(302);
        }

        $clone = new AutomationSequence(
            name: $original->getName() . ' (Copy)',
            triggerType: $original->getTriggerType(),
            triggerFormId: $original->getTriggerFormId(),
            triggerTag: $original->getTriggerTag(),
            triggerTagMatch: $original->getTriggerTagMatch(),
            status: 'paused',
        );
        $clone->setTrackingEnabled($original->getTrackingEnabled());
        $this->automations->saveSequence($clone);

        $steps = $this->automations->findStepsBySequenceId($original->getId());
        foreach ($steps as $step) {
            $newStep = new AutomationStep(
                sequenceId: $clone->getId(),
                sortOrder: $step->getSortOrder(),
                delayMinutes: $step->getDelayMinutes(),
                subject: $step->getSubject(),
                bodyHtml: $step->getBodyHtml(),
            );
            $this->automations->saveStep($newStep);
        }

        $this->session->flash('success', 'Automation sequence cloned.');
        return $response->withHeader('Location', '/admin/newsletter/automations')->withStatus(302);
    }

    // ── Delete ────────────────────────────────────────────────────

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->automations->deleteStepsBySequenceId((int) $args['id']);
        $this->automations->deleteSequence((int) $args['id']);

        $this->session->flash('success', $this->translator->get('Automation sequence deleted.'));
        return $response->withHeader('Location', '/admin/newsletter/automations')->withStatus(302);
    }

    // ── Toggle status ─────────────────────────────────────────────

    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $sequence = $this->automations->findSequenceById((int) $args['id']);
        if ($sequence === null) {
            $this->session->flash('error', 'Sequence not found.');
            return $response->withHeader('Location', '/admin/newsletter/automations')->withStatus(302);
        }

        if ($sequence->isActive()) {
            $sequence->pause();
        } else {
            $sequence->activate();
        }
        $this->automations->saveSequence($sequence);

        $this->session->flash('success', $this->translator->get('Automation status updated.'));
        return $response->withHeader('Location', '/admin/newsletter/automations')->withStatus(302);
    }

    // ── Cron / manual process endpoint ───────────────────────────

    public function process(Request $request, Response $response): Response
    {
        $limit     = (int) ($request->getQueryParams()['limit'] ?? 50);
        $limit     = max(1, min(500, $limit));
        $processed = $this->automationService->processDue($limit);

        $response->getBody()->write(json_encode(['processed' => $processed], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function applyTracking(AutomationSequence $sequence, array $body): void
    {
        if (isset($body['tracking_enabled'])) {
            $value = $body['tracking_enabled'];
            if (is_array($value)) {
                $value = array_reduce($value, fn (int $carry, $v) => $carry | (is_numeric($v) ? (int) $v : 0), 0);
            } else {
                $value = is_numeric($value) ? (int) $value : 3;
            }
            $sequence->setTrackingEnabled(max(0, min(3, $value)));
        } else {
            $sequence->setTrackingEnabled(0);
        }
    }

    /**
     * Parse steps[0][subject] / steps[0][body_html] / steps[0][delay] / steps[0][delay_unit] from POST.
     */
    private function saveStepsFromRequest(array $body, int $sequenceId): void
    {
        $rawSteps = is_array($body['steps'] ?? null) ? $body['steps'] : [];

        foreach (array_values($rawSteps) as $i => $raw) {
            $delayMinutes = $this->toMinutes(
                (int) ($raw['delay'] ?? 0),
                (string) ($raw['delay_unit'] ?? 'minutes')
            );
            $step = new AutomationStep(
                $sequenceId,
                $i,
                $delayMinutes,
                trim((string) ($raw['subject'] ?? '')),
                trim((string) ($raw['body_html'] ?? '')),
            );
            $step->setSubjectB(!empty($raw['subject_b']) ? trim((string) $raw['subject_b']) : null);
            $step->setBodyHtmlB(!empty($raw['body_html_b']) ? trim((string) $raw['body_html_b']) : null);
            $step->setAbTestEnabled(!empty($raw['ab_test_enabled']));
            $step->setVariationBWeight(!empty($raw['variation_b_weight']) ? (int) $raw['variation_b_weight'] : 50);
            $step->setMinOrdersFilter(!empty($raw['min_orders_filter']) ? (int) $raw['min_orders_filter'] : null);
            $step->setMaxOrdersFilter(!empty($raw['max_orders_filter']) ? (int) $raw['max_orders_filter'] : null);
            $step->setMinOpenRateFilter(!empty($raw['min_open_rate_filter']) ? (int) $raw['min_open_rate_filter'] : null);
            $step->setMaxOpenRateFilter(!empty($raw['max_open_rate_filter']) ? (int) $raw['max_open_rate_filter'] : null);
            $step->setMinClickRateFilter(!empty($raw['min_click_rate_filter']) ? (int) $raw['min_click_rate_filter'] : null);
            $step->setMaxClickRateFilter(!empty($raw['max_click_rate_filter']) ? (int) $raw['max_click_rate_filter'] : null);
            $step->setMinTotalSpendFilter(!empty($raw['min_total_spend_filter']) ? (float) str_replace(',', '.', $raw['min_total_spend_filter']) : null);
            $step->setMaxTotalSpendFilter(!empty($raw['max_total_spend_filter']) ? (float) str_replace(',', '.', $raw['max_total_spend_filter']) : null);
            $step->setMinDaysSinceLastPurchaseFilter(!empty($raw['min_days_since_last_purchase_filter']) ? (int) $raw['min_days_since_last_purchase_filter'] : null);
            $step->setMaxDaysSinceLastPurchaseFilter(!empty($raw['max_days_since_last_purchase_filter']) ? (int) $raw['max_days_since_last_purchase_filter'] : null);
            $this->automations->saveStep($step);
        }
    }

    private function toMinutes(int $amount, string $unit): int
    {
        return match ($unit) {
            'hours' => $amount * 60,
            'days'  => $amount * 1440,
            default => $amount,   // 'minutes'
        };
    }

    /** @return string[] */
    private function validateSequenceInput(array $body): array
    {
        $errors = [];
        if (empty(trim((string) ($body['name'] ?? '')))) {
            $errors[] = $this->translator->get('Name is required.');
        }
        return $errors;
    }

    /** @return array{cron_mode:string,cron_interval:string,cron_last_ran:string,cron_secret:string,cron_last_ran_formatted:string} */
    private function buildCronConfig(): array
    {
        $config = [];
        foreach (['cron_mode', 'cron_interval', 'cron_last_ran'] as $key) {
            $config[$key] = $this->settings->get($key, '') ?? '';
        }
        if ($config['cron_mode'] === '') {
            $config['cron_mode'] = 'none';
        }
        if ($config['cron_interval'] === '') {
            $config['cron_interval'] = '5';
        }
        $cronSecret = $this->settings->get('cron_secret', '') ?? '';
        if ($cronSecret === '') {
            $cronSecret = bin2hex(random_bytes(16));
            $this->settings->set('cron_secret', $cronSecret, 'cron');
        }
        $config['cron_secret']             = $cronSecret;
        $config['cron_last_ran_formatted'] = '';
        if ($config['cron_last_ran'] !== '' && is_numeric($config['cron_last_ran'])) {
            $config['cron_last_ran_formatted'] = date('Y-m-d H:i:s', (int) $config['cron_last_ran']);
        }
        return $config;
    }
}
