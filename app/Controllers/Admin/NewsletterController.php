<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;
use ValeApp\Application\Mail\MailService;
use ValeApp\Application\Membership\SubscriptionService;
use ValeApp\Application\Newsletter\AutomationService;
use ValeApp\Application\Newsletter\NewsletterService;
use ValeApp\Domain\Content\Repository\CategoryRepositoryInterface;
use ValeApp\Domain\Content\Repository\TagRepositoryInterface;
use ValeApp\Domain\Form\Repository\FormRepositoryInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Domain\Newsletter\Entity\Campaign;
use ValeApp\Domain\Newsletter\Entity\Subscriber;
use ValeApp\Domain\Newsletter\Repository\CampaignRepositoryInterface;
use ValeApp\Domain\Newsletter\Repository\SubscriberRepositoryInterface;
use ValeApp\Domain\Shared\ValueObject\Pagination;
use ValeApp\Domain\User\Repository\UserRepositoryInterface;
use ValeApp\Infrastructure\Cache\AdminSearchCache;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class NewsletterController
{
    private const SUBSCRIBER_SEARCH_NAMESPACE = 'subscriber_search';
    private const SUBSCRIBER_SEARCH_TTL       = 120;

    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly SubscriberRepositoryInterface $subscribers,
        private readonly CampaignRepositoryInterface $campaigns,
        private readonly SessionManager $session,
        private readonly FormRepositoryInterface $forms,
        private readonly NewsletterService $newsletterService,
        private readonly MailService $mail,
        private readonly AutomationService $automationService,
        private readonly CategoryRepositoryInterface $categories,
        private readonly TagRepositoryInterface $tags,
        private readonly AdminSearchCache $searchCache,
        private readonly ?SubscriptionService $subscriptionService = null,
        private readonly ?UserRepositoryInterface $users = null,
    ) {
    }

    // ── Subscribers ───────────────────────────────────────

    /**
     * Read-only subscriber tag lookup for the block editor (tag pills).
     * Mirrors the TagController::listJson envelope: preloadable, searchable.
     */
    public function subscriberTagsJson(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $q      = isset($params['q']) ? mb_strtolower(trim((string) $params['q'])) : '';

        $all = $this->subscribers->getAllTags();
        if ($q !== '') {
            $all = array_values(array_filter(
                $all,
                static fn (string $tag): bool => str_contains(mb_strtolower($tag), $q),
            ));
        }

        $total     = count($all);
        $limit     = max(1, (int) ($params['limit'] ?? client_side_search_fetch_limit()));
        $truncated = $total > $limit;
        if ($truncated) {
            $all = array_slice($all, 0, $limit);
        }

        $payload = [
            'data'      => array_map(static fn (string $tag): array => ['name' => $tag], $all),
            'total'     => $total,
            'truncated' => $truncated,
        ];

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function subscribers(Request $request, Response $response): Response
    {
        $params  = $request->getQueryParams();
        $filters = [];
        if (!empty($params['status'])) {
            $filters['status'] = $params['status'];
        }
        if (!empty($params['search'])) {
            $filters['search'] = $params['search'];
        }

        $baseCount  = $this->subscribers->count();
        $clientSide = $baseCount <= client_side_search_fetch_limit();

        if ($clientSide) {
            $pagination  = new Pagination(1, 100000);
            $subscribers = $this->subscribers->findAll($pagination, $filters);
            $total       = !empty($filters['search']) ? $this->subscribers->count($filters) : $baseCount;
        } else {
            $page       = max(1, (int) ($params['page'] ?? 1));
            $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 20)));
            $pagination = new Pagination($page, $perPage, $params['sort'] ?? null, $params['dir'] ?? 'desc');
            $searchKey  = $this->subscriberSearchKeyParts($filters, $pagination);
            $cached     = $searchKey !== null
                ? $this->searchCache->getObjects(self::SUBSCRIBER_SEARCH_NAMESPACE, $searchKey, [Subscriber::class])
                : null;
            $subscribers = $cached !== null ? $this->validSubscribers($cached['items']) : null;
            if ($subscribers === null) {
                $total       = $this->subscribers->count($filters);
                $subscribers = $this->subscribers->findAll($pagination, $filters);
                if ($searchKey !== null) {
                    $this->searchCache->setObjects(
                        self::SUBSCRIBER_SEARCH_NAMESPACE,
                        $searchKey,
                        $total,
                        $subscribers,
                        self::SUBSCRIBER_SEARCH_TTL,
                    );
                }
            } else {
                $total = $cached['total'];
            }
        }

        $subIds = array_map(fn ($s) => $s->getId(), $subscribers);
        $stats  = $this->campaigns->getSubscribersStats($subIds);

        $counts = [
            'all'          => $this->subscribers->count([]),
            'active'       => $this->subscribers->count(['status' => 'active']),
            'pending'      => $this->subscribers->count(['status' => 'pending']),
            'unsubscribed' => $this->subscribers->count(['status' => 'unsubscribed']),
        ];

        return $this->twig->render($response, 'admin/newsletter/subscribers.twig', [
            'subscribers'     => $subscribers,
            'total'           => $total,
            'filters'         => $params,
            'counts'          => $counts,
            'forms'           => $this->forms->findAll(),
            'allTags'         => $this->subscribers->getAllTags(),
            'subscriberStats' => $stats,
            'client_side'     => $clientSide,
            'page'            => $clientSide ? 1 : $pagination->page,
            'per_page'        => $clientSide ? 20 : $pagination->perPage,
            'base_url'        => admin_url('/newsletter/subscribers'),
        ]);
    }

    public function deleteSubscriber(Request $request, Response $response, array $args): Response
    {
        $this->subscribers->delete((int) $args['id']);
        $this->searchCache->bump(self::SUBSCRIBER_SEARCH_NAMESPACE);
        $this->session->flash('success', 'Subscriber removed.');
        return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
    }

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
            $this->session->flash('error', $this->translator->get('No items selected.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        $count = 0;
        foreach ($ids as $id) {
            $sub = $this->subscribers->findById((int) $id);
            if ($sub === null) {
                continue;
            }
            if ($action === 'delete') {
                $this->subscribers->delete((int) $id);
                $count++;
            } elseif (in_array($action, ['activate', 'pending', 'unsubscribe'], true)) {
                $sub->setStatus($action);
                $this->subscribers->save($sub);
                $count++;
            }
        }
        $this->searchCache->bump(self::SUBSCRIBER_SEARCH_NAMESPACE);
        $this->session->flash('success', $this->translator->get(':count subscriber(s) updated.', [':count' => (string) $count]));
        return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
    }

    public function storeSubscriber(Request $request, Response $response): Response
    {
        $body  = (array) $request->getParsedBody();
        $email = strtolower(trim($body['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('error', 'Invalid email address.');
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        $existing = $this->subscribers->findByEmail($email);
        if ($existing !== null) {
            $this->session->flash('error', $this->translator->get('A subscriber with this email already exists.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        $subscriber = new Subscriber($email, bin2hex(random_bytes(16)));
        $subscriber->setName(!empty($body['name']) ? trim($body['name']) : null);
        $subscriber->setSourceFormId(!empty($body['source_form_id']) ? (int) $body['source_form_id'] : null);

        // Tags: comma-separated string -> array
        if (!empty($body['tags'])) {
            $tags = array_filter(array_map('trim', explode(',', $body['tags'])));
            $subscriber->setTags(array_values($tags));
        }

        $status = in_array($body['status'] ?? '', ['active', 'pending'], true) ? $body['status'] : 'active';
        if ($status === 'active') {
            $subscriber->confirm();
        }

        $this->subscribers->save($subscriber);
        $this->searchCache->bump(self::SUBSCRIBER_SEARCH_NAMESPACE);

        // Enroll into automations if active and checkbox ticked
        if ($status === 'active' && !empty($body['enroll_automations'])) {
            $this->automationService->enrollSubscriber($subscriber);
        }

        // When added as pending, send a confirmation email
        if ($status === 'pending') {
            $base       = __require_app_url();
            $confirmUrl = $base . '/newsletter/confirm/' . $subscriber->getToken();

            try {
                $this->mail->send(new MailMessage(
                    to: $email,
                    subject: 'Confirm your subscription',
                    htmlBody: "<p>Please confirm your subscription by clicking: <a href=\"{$confirmUrl}\">{$confirmUrl}</a></p>",
                ));
                $this->session->flash('success', 'Subscriber added as pending — confirmation email sent.');
            } catch (\Throwable $e) {
                // Subscriber row is already saved — report the provider failure
                // instead of blowing up into an error page.
                error_log('[Newsletter] Admin confirmation email failed for ' . $email . ': ' . $e->getMessage());
                $this->session->flash(
                    'error',
                    $this->translator->get(
                        'Subscriber saved, but the confirmation email could not be sent: :error',
                        [':error' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')],
                    ),
                );
            }
        } else {
            $this->session->flash('success', 'Subscriber added as active.');
        }

        return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
    }

    public function updateSubscriber(Request $request, Response $response, array $args): Response
    {
        $subscriber = $this->subscribers->findById((int) $args['id']);
        if ($subscriber === null) {
            $this->session->flash('error', 'Subscriber not found.');
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        $body = (array) $request->getParsedBody();

        $subscriber->setName(!empty($body['name']) ? trim($body['name']) : null);
        $subscriber->setSourceFormId(!empty($body['source_form_id']) ? (int) $body['source_form_id'] : null);

        $rawTags = $body['tags'] ?? '';
        $subscriber->setTags(
            !empty($rawTags)
                ? array_values(array_filter(array_map('trim', explode(',', $rawTags))))
                : []
        );

        $newStatus = in_array($body['status'] ?? '', ['active', 'pending', 'unsubscribed'], true)
            ? $body['status'] : null;

        if ($newStatus !== null && $newStatus !== $subscriber->getStatus()) {
            if ($newStatus === 'active') {
                $subscriber->confirm();
            } else {
                $subscriber->setStatus($newStatus);
            }
        }

        $this->subscribers->save($subscriber);
        $this->searchCache->bump(self::SUBSCRIBER_SEARCH_NAMESPACE);

        // Enroll into automations if checkbox ticked and subscriber is active
        if (!empty($body['enroll_automations']) && $subscriber->getStatus() === 'active') {
            $this->automationService->enrollSubscriber($subscriber);
        }

        $this->session->flash('success', $this->translator->get('Subscriber updated.'));
        return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
    }

    public function terminateSubscription(Request $request, Response $response, array $args): Response
    {
        $subscriber = $this->subscribers->findById((int) $args['id']);
        if ($subscriber === null) {
            $this->session->flash('error', 'Subscriber not found.');
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        if ($this->subscriptionService === null) {
            $this->session->flash('error', $this->translator->get('Subscription service not available.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        $userId = $subscriber->getUserId();
        if ($userId === null) {
            $this->session->flash('error', $this->translator->get('No user account linked to this subscriber.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        $activeSub = $this->subscriptionService->findActiveByUserId((int) $userId);

        $this->subscriptionService->terminateByUserId($userId);

        $message = trim((string) ($request->getParsedBody()['message'] ?? ''));
        $this->sendTerminationEmail($subscriber, $activeSub, $message !== '' ? $message : null);

        $this->session->flash('success', $this->translator->get('Subscription terminated successfully.'));
        return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
    }

    private function sendTerminationEmail(Subscriber $subscriber, ?array $activeSub, ?string $message = null): void
    {
        if ($this->users === null || $this->mail === null) {
            return;
        }

        $userId = $subscriber->getUserId();
        if ($userId === null) {
            return;
        }

        $user = $this->users->findById((int) $userId);
        if ($user === null) {
            return;
        }

        $planName  = '';
        $expiresAt = null;
        if ($activeSub !== null) {
            $plan      = $this->subscriptionService->findPlanById((int) $activeSub['plan_id']);
            $planName  = $plan?->getName()        ?? '';
            $expiresAt = $activeSub['expires_at'] ?? null;
        }

        $context = [
            'plan_name'      => $planName,
            'name'           => $user->getName(),
            'expires_at'     => $expiresAt,
            'message'        => $message,
            'membership_url' => absolute_url('/membership'),
        ];

        try {
            $locale = $user->getLocale() ?: 'en';
            $this->mail->sendTemplate(
                (string) $user->getEmail(),
                'Your subscription has been cancelled',
                'mail/subscription-cancelled',
                $context,
                locale: $locale,
                recipientType: 'customer',
            );
        } catch (\Throwable $e) {
            error_log('[Newsletter] Failed to send termination email: ' . $e->getMessage());
        }
    }

    public function cancelSubscription(Request $request, Response $response, array $args): Response
    {
        $subscriber = $this->subscribers->findById((int) $args['id']);
        if ($subscriber === null) {
            $this->session->flash('error', 'Subscriber not found.');
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        if ($this->subscriptionService === null) {
            $this->session->flash('error', $this->translator->get('Subscription service not available.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        $userId = $subscriber->getUserId();
        if ($userId === null) {
            $this->session->flash('error', $this->translator->get('No user account linked to this subscriber.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        $this->subscriptionService->cancelByUserId((int) $userId);

        $this->session->flash('success', $this->translator->get('Subscription will be cancelled at the end of the billing period.'));
        return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
    }

    public function exportSubscribers(Request $request, Response $response): Response
    {
        $params      = array_merge($request->getQueryParams(), (array) $request->getParsedBody());
        $exportScope = $params['export_scope'] ?? 'all';

        $rawIds = $params['ids'] ?? [];
        if (is_string($rawIds)) {
            $ids = array_filter(array_map('intval', explode(',', $rawIds)));
        } else {
            $ids = array_filter(array_map('intval', (array) $rawIds));
        }

        if ($exportScope === 'selected' && $ids !== []) {
            $allSubs     = $this->subscribers->findAll(new Pagination(1, 100000));
            $subscribers = array_values(array_filter($allSubs, fn ($s) => in_array($s->getId(), $ids, true)));
        } elseif ($exportScope === 'filtered') {
            $filters = [];
            if (!empty($params['status'])) {
                $filters['status'] = $params['status'];
            }
            if (!empty($params['search'])) {
                $filters['search'] = $params['search'];
            }
            $subscribers = $this->subscribers->findAll(new Pagination(1, 100000), $filters);
        } else {
            $subscribers = $this->subscribers->findAll(new Pagination(1, 100000));
        }

        $subIds = array_map(fn ($s) => $s->getId(), $subscribers);
        $stats  = $this->campaigns->getSubscribersStats($subIds);

        $stream = fopen('php://temp', 'r+');
        // UTF-8 BOM for Excel compatibility
        fwrite($stream, "\xEF\xBB\xBF");

        // Explicit $escape: PHP 8.4 deprecates relying on the default.
        fputcsv($stream, [
            'Email',
            'Name',
            'Status',
            'Tags',
            'Created At',
            'Campaigns Count',
            'Opens Count',
            'Clicks Count',
        ], ',', '"', '\\');

        foreach ($subscribers as $sub) {
            $id     = $sub->getId();
            $sStats = $id !== null
                ? ($stats[$id] ?? ['campaigns_count' => 0, 'opens_count' => 0, 'clicks_count' => 0])
                : ['campaigns_count' => 0, 'opens_count' => 0, 'clicks_count' => 0];
            $row    = [
                $sub->getEmail(),
                $sub->getName() ?? '',
                $sub->getStatus(),
                implode(', ', $sub->getTags()),
                $sub->getCreatedAt()?->format('Y-m-d H:i:s') ?? '',
                $sStats['campaigns_count'],
                $sStats['opens_count'],
                $sStats['clicks_count'],
            ];
            $line = array_map(static function ($val): string {
                $str = (string) $val;
                if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    return "'" . $str;
                }
                return $str;
            }, $row);
            fputcsv($stream, $line, ',', '"', '\\');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = 'subscribers-' . date('Y-m-d') . '.csv';

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStatus(200);
    }

    public function importSubscribers(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        /** @var UploadedFileInterface|null $file */
        $file = $uploadedFiles['csv_file'] ?? null;

        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            $this->session->flash('error', $this->translator->get('Please select a valid CSV file to import.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        $body              = (array) $request->getParsedBody();
        $defaultStatus     = in_array($body['default_status'] ?? '', ['active', 'pending'], true) ? $body['default_status'] : 'active';
        $enrollAutomations = !empty($body['enroll_automations']);

        $tmpPath = $file->getFilePath();
        if (!$tmpPath || !file_exists($tmpPath)) {
            $stream  = $file->getStream();
            $content = (string) $stream;
            $tmpPath = tempnam(sys_get_temp_dir(), 'sub_csv_');
            file_put_contents($tmpPath, $content);
            $isTempCreated = true;
        } else {
            $isTempCreated = false;
        }

        $handle = fopen($tmpPath, 'r');
        if ($handle === false) {
            if ($isTempCreated) {
                @unlink($tmpPath);
            }
            $this->session->flash('error', $this->translator->get('Could not open uploaded file.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        // Read sample to detect delimiter and strip UTF-8 BOM
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            if ($isTempCreated) {
                @unlink($tmpPath);
            }
            $this->session->flash('error', $this->translator->get('CSV file is empty.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        // Remove BOM
        if (str_starts_with($firstLine, "\xEF\xBB\xBF")) {
            $firstLine = substr($firstLine, 3);
        }

        $delimiter = str_contains($firstLine, ';') && !str_contains($firstLine, ',') ? ';' : ',';

        rewind($handle);

        $addedCount   = 0;
        $skippedCount = 0;
        $invalidCount = 0;

        // Parse first row to check for header
        // Explicit $escape: PHP 8.4 deprecates relying on the default.
        $firstRow = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if ($firstRow === false || $firstRow === [null] || $firstRow === []) {
            fclose($handle);
            if ($isTempCreated) {
                @unlink($tmpPath);
            }
            $this->session->flash('error', $this->translator->get('CSV file is empty.'));
            return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
        }

        // Strip BOM from first cell if present
        if (isset($firstRow[0])) {
            $firstRow[0] = preg_replace('/^\x{FEFF}/u', '', $firstRow[0]);
        }

        $headers    = [];
        $hasHeader  = false;
        $emailCol   = 0;
        $nameCol    = null;
        $tagsCol    = null;
        $statusCol  = null;

        // Determine if first row is header or data
        $firstCell = strtolower(trim((string) ($firstRow[0] ?? '')));
        if (str_contains($firstCell, '@')) {
            // First row is actually data (a single-column email list or email in 1st col without header)
            $hasHeader = false;
        } else {
            // Check if any element in first row matches standard headers
            foreach ($firstRow as $idx => $headerVal) {
                $cleanHeader   = strtolower(trim((string) $headerVal));
                $headers[$idx] = $cleanHeader;
                if ($cleanHeader === 'email' || $cleanHeader === 'e-mail' || $cleanHeader === 'email address') {
                    $emailCol  = $idx;
                    $hasHeader = true;
                } elseif ($cleanHeader === 'name' || $cleanHeader === 'full name' || $cleanHeader === 'display name') {
                    $nameCol   = $idx;
                    $hasHeader = true;
                } elseif ($cleanHeader === 'tags' || $cleanHeader === 'tag') {
                    $tagsCol   = $idx;
                    $hasHeader = true;
                } elseif ($cleanHeader === 'status') {
                    $statusCol = $idx;
                    $hasHeader = true;
                }
            }
        }

        $rowsToProcess = [];
        if (!$hasHeader) {
            $rowsToProcess[] = $firstRow;
        }

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null] || $row === [] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue;
            }
            $rowsToProcess[] = $row;
        }

        fclose($handle);
        if ($isTempCreated) {
            @unlink($tmpPath);
        }

        foreach ($rowsToProcess as $row) {
            $emailRaw = isset($row[$emailCol]) ? trim((string) $row[$emailCol]) : '';
            $email    = strtolower($emailRaw);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidCount++;
                continue;
            }

            // Skip existing subscriber
            if ($this->subscribers->findByEmail($email) !== null) {
                $skippedCount++;
                continue;
            }

            $name = ($nameCol !== null && isset($row[$nameCol])) ? trim((string) $row[$nameCol]) : null;
            if ($name === '') {
                $name = null;
            }

            $tags = [];
            if ($tagsCol !== null && isset($row[$tagsCol])) {
                $rawTags = trim((string) $row[$tagsCol]);
                if ($rawTags !== '') {
                    $tags = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $rawTags))));
                }
            }

            $rowStatus = ($statusCol !== null && isset($row[$statusCol])) ? strtolower(trim((string) $row[$statusCol])) : '';
            $status    = in_array($rowStatus, ['active', 'pending', 'unsubscribed'], true) ? $rowStatus : $defaultStatus;

            $subscriber = new Subscriber($email, bin2hex(random_bytes(16)));
            if ($name !== null) {
                $subscriber->setName($name);
            }
            if ($tags !== []) {
                $subscriber->setTags($tags);
            }

            if ($status === 'active') {
                $subscriber->confirm();
            } else {
                $subscriber->setStatus($status);
            }

            $this->subscribers->save($subscriber);

            if ($status === 'active' && $enrollAutomations) {
                $this->automationService->enrollSubscriber($subscriber);
            }

            $addedCount++;
        }

        $this->searchCache->bump(self::SUBSCRIBER_SEARCH_NAMESPACE);

        $msg = $this->translator->get(
            'Import complete: :added added, :skipped skipped (already existing), :invalid invalid.',
            [
                ':added'   => (string) $addedCount,
                ':skipped' => (string) $skippedCount,
                ':invalid' => (string) $invalidCount,
            ]
        );

        $this->session->flash('success', $msg);
        return $response->withHeader('Location', admin_url('/newsletter/subscribers'))->withStatus(302);
    }

    // ── Campaigns ─────────────────────────────────────────

    public function campaigns(Request $request, Response $response): Response
    {
        $params       = $request->getQueryParams();
        $subscriberId = !empty($params['subscriber_id']) ? (int) $params['subscriber_id'] : null;
        $filter       = trim($params['filter'] ?? '');
        if (!in_array($filter, ['opened', 'clicked'], true)) {
            $filter = null;
        }

        if ($subscriberId !== null) {
            $total            = $this->campaigns->countBySubscriberId($subscriberId, $filter);
            $clientSide       = $total <= client_side_search_fetch_limit();
            $page             = max(1, (int) ($params['page'] ?? 1));
            $perPage          = max(1, min(100, (int) ($params['per_page'] ?? 20)));
            $pagination       = new Pagination($page, $perPage, $params['sort'] ?? null, $params['dir'] ?? 'desc');
            $campaigns        = $this->campaigns->findBySubscriberId($subscriberId, $pagination, $filter);
            $filterSubscriber = $this->subscribers->findById($subscriberId);
        } else {
            $search     = isset($params['search']) ? trim((string) $params['search']) : '';
            $searchBody = $search !== '' && !empty($params['search_body']);

            $total      = $this->campaigns->count($search !== '' ? $search : null, $searchBody);
            $clientSide = $total <= client_side_search_fetch_limit() && empty($params['search_body']);

            if ($clientSide) {
                $pagination = new Pagination(1, 100000);
            } else {
                $page       = max(1, (int) ($params['page'] ?? 1));
                $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 20)));
                $pagination = new Pagination(
                    $page,
                    $perPage,
                    $params['sort'] ?? null,
                    $params['dir']  ?? 'desc',
                );
            }

            $campaigns        = $this->campaigns->findAll($pagination, $search !== '' ? $search : null, $searchBody);
            $filterSubscriber = null;
        }

        // Audience preview for unsent campaigns: live count of active subscribers
        // matching each campaign's filters (recipients are only recorded on send).
        $audienceEstimates = [];
        foreach ($campaigns as $campaign) {
            if (
                $campaign->getRecipientsCount() !== 0
                || ($campaign->getStatus() !== 'draft' && $campaign->getStatus() !== 'scheduled')
            ) {
                continue;
            }
            $audienceEstimates[$campaign->getId()] = $this->subscribers->countActive(
                $campaign->getTagFilter(),
                $campaign->getSourceFormFilter(),
                $campaign->getMinOrdersFilter(),
                $campaign->getMaxOrdersFilter(),
                $campaign->getMinOpenRateFilter(),
                $campaign->getMaxOpenRateFilter(),
                $campaign->getMinClickRateFilter(),
                $campaign->getMaxClickRateFilter(),
            );
        }

        return $this->twig->render($response, 'admin/newsletter/campaigns.twig', [
            'campaigns'         => $campaigns,
            'audienceEstimates' => $audienceEstimates,
            'total'             => $total,
            'filters'           => $params,
            'client_side'       => $clientSide,
            'page'              => $clientSide && $subscriberId === null ? 1 : $pagination->page,
            'per_page'          => $clientSide && $subscriberId === null ? 20 : $pagination->perPage,
            'filterSubscriber'  => $filterSubscriber,
            'base_url'          => admin_url('/newsletter/campaigns' . ($subscriberId ? '?subscriber_id=' . $subscriberId . ($filter ? '&filter=' . $filter : '') : '')),
        ]);
    }

    public function createCampaign(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'admin/newsletter/campaign-form.twig', [
            'campaign'       => null,
            'is_edit'        => false,
            'forms'          => $this->forms->findAll(),
            'allTags'        => $this->subscribers->getAllTags(),
            'allCategories'  => $this->categories->findAll(),
            'allContentTags' => $this->tags->findAll(),
        ]);
    }

    public function storeCampaign(Request $request, Response $response): Response
    {
        $body    = (array) $request->getParsedBody();
        $subject = trim($body['subject'] ?? '');
        if ($subject === '') {
            $this->session->flash('error', $this->translator->get('Subject is required.'));
            return $response->withHeader('Location', admin_url('/newsletter/campaigns/create'))->withStatus(302);
        }

        $campaign = new Campaign($subject, $body['body_html'] ?? '');
        $this->fillCampaign($campaign, $body);
        $this->campaigns->save($campaign);

        $this->session->flash('success', 'Campaign saved.');
        return $response->withHeader('Location', admin_url('/newsletter/campaigns'))->withStatus(302);
    }

    public function cloneCampaign(Request $request, Response $response, array $args): Response
    {
        $original = $this->campaigns->findById((int) $args['id']);
        if ($original === null) {
            return $response->withStatus(404);
        }

        $clone = new Campaign('Copy of ' . $original->getSubject(), $original->getBodyHtml());
        // Content + configuration are copied; delivery state stays fresh
        // (draft, zero stats, no recipients, no schedule, no recurrence).
        $clone->setSubjectB($original->getSubjectB());
        $clone->setBodyHtmlB($original->getBodyHtmlB());
        $clone->setFromName($original->getFromName());
        $clone->setFromEmail($original->getFromEmail());
        $clone->setAbTestEnabled($original->isAbTestEnabled());
        $clone->setVariationBWeight($original->getVariationBWeight());
        $clone->setTagFilter($original->getTagFilter());
        $clone->setSourceFormFilter($original->getSourceFormFilter());
        $clone->setMinOrdersFilter($original->getMinOrdersFilter());
        $clone->setMaxOrdersFilter($original->getMaxOrdersFilter());
        $clone->setMinOpenRateFilter($original->getMinOpenRateFilter());
        $clone->setMaxOpenRateFilter($original->getMaxOpenRateFilter());
        $clone->setMinClickRateFilter($original->getMinClickRateFilter());
        $clone->setMaxClickRateFilter($original->getMaxClickRateFilter());
        $clone->setTrackingEnabled($original->getTrackingEnabled());
        $this->campaigns->save($clone);
        $this->searchCache->bump('campaign_search');

        $this->session->flash('success', 'Campaign cloned as draft.');
        return $response->withHeader('Location', admin_url('/newsletter/campaigns/' . $clone->getId() . '/edit'))->withStatus(302);
    }

    public function viewCampaign(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->campaigns->findById((int) $args['id']);
        if ($campaign === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/newsletter/campaign-form.twig', [
            'campaign'       => $campaign,
            'is_edit'        => false,
            'readonly'       => true,
            'forms'          => $this->forms->findAll(),
            'allTags'        => $this->subscribers->getAllTags(),
            'allCategories'  => $this->categories->findAll(),
            'allContentTags' => $this->tags->findAll(),
        ]);
    }

    public function editCampaign(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->campaigns->findById((int) $args['id']);
        if ($campaign === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'admin/newsletter/campaign-form.twig', [
            'campaign'       => $campaign,
            'is_edit'        => true,
            'forms'          => $this->forms->findAll(),
            'allTags'        => $this->subscribers->getAllTags(),
            'allCategories'  => $this->categories->findAll(),
            'allContentTags' => $this->tags->findAll(),
        ]);
    }

    public function updateCampaign(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->campaigns->findById((int) $args['id']);
        if ($campaign === null) {
            return $response->withStatus(404);
        }

        if (!$campaign->isDraft()) {
            $this->session->flash('error', 'Only draft campaigns can be edited.');
            return $response->withHeader('Location', admin_url('/newsletter/campaigns'))->withStatus(302);
        }

        $body    = (array) $request->getParsedBody();
        $subject = trim($body['subject'] ?? '');
        if ($subject !== '') {
            $campaign->setSubject($subject);
        }
        if (!empty($body['body_html'])) {
            $campaign->setBodyHtml($body['body_html']);
        }
        $this->fillCampaign($campaign, $body);
        $this->campaigns->save($campaign);
        $this->searchCache->bump('campaign_search');

        $this->session->flash('success', $this->translator->get('Campaign updated.'));
        return $response->withHeader('Location', admin_url('/newsletter/campaigns/' . $args['id'] . '/edit'))->withStatus(302);
    }

    public function deleteCampaign(Request $request, Response $response, array $args): Response
    {
        $this->campaigns->delete((int) $args['id']);
        $this->searchCache->bump('campaign_search');
        $this->session->flash('success', 'Campaign deleted.');
        return $response->withHeader('Location', admin_url('/newsletter/campaigns'))->withStatus(302);
    }

    public function campaignRecipients(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->campaigns->findById((int) $args['id']);
        if ($campaign === null) {
            return $response->withStatus(404);
        }

        $params  = $request->getQueryParams();
        $search  = trim($params['search'] ?? '');
        $filter  = trim($params['filter'] ?? '');
        if (!in_array($filter, ['opened', 'clicked'], true)) {
            $filter = null;
        }

        $baseCount  = $this->campaigns->countRecipientsByCampaignId($campaign->getId(), $search ?: null, $filter);
        $clientSide = $baseCount <= client_side_search_fetch_limit() && $search === '';

        $page    = 1;
        $perPage = 50;

        if ($clientSide) {
            $pagination  = new Pagination(1, 100000);
            $recipients  = $this->campaigns->findRecipientsByCampaignId($campaign->getId(), $pagination, $search ?: null, $filter);
            $total       = $baseCount;
        } else {
            $page       = max(1, (int) ($params['page'] ?? 1));
            $perPage    = max(1, min(100, (int) ($params['per_page'] ?? 50)));
            $pagination = new Pagination($page, $perPage, $params['sort'] ?? null, $params['dir'] ?? 'desc');

            $recKey = $this->recipientSearchKeyParts($campaign->getId(), $search, $filter, $pagination);
            $cached = $recKey !== null
                ? $this->searchCache->getObjects('recipient_search', $recKey, [Subscriber::class])
                : null;
            $recipients = $cached !== null ? $cached['items'] : null;

            if ($recipients === null) {
                $total      = $this->campaigns->countRecipientsByCampaignId($campaign->getId(), $search ?: null, $filter);
                $recipients = $this->campaigns->findRecipientsByCampaignId($campaign->getId(), $pagination, $search ?: null, $filter);
                if ($recKey !== null) {
                    $this->searchCache->setObjects('recipient_search', $recKey, $total, $recipients, 120);
                }
            } else {
                $total = $cached['total'];
            }
        }

        return $this->twig->render($response, 'admin/newsletter/campaign-recipients.twig', [
            'campaign'   => $campaign,
            'recipients' => $recipients,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'sort'       => $pagination->sortBy,
            'dir'        => $pagination->sortDir,
            'search'     => $search,
            'filter'     => $filter,
            'base_url'   => admin_url('/newsletter/campaigns/' . $campaign->getId() . '/recipients' . ($filter ? '?filter=' . $filter : '')),
        ]);
    }

    public function sendCampaign(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->campaigns->findById((int) $args['id']);
        if ($campaign === null) {
            return $response->withStatus(404);
        }

        if (!in_array($campaign->getStatus(), ['draft', 'scheduled', 'queued', 'processing'], true)) {
            $this->session->flash(
                'error',
                $this->translator->get('Only draft, scheduled or queued campaigns can be sent.'),
            );
            return $response->withHeader('Location', admin_url('/newsletter/campaigns'))->withStatus(302);
        }

        try {
            $sent = $this->newsletterService->sendCampaign($campaign);
        } catch (\Throwable $e) {
            // Provider errors (bad key, sandbox domain, rate limit, ...) must not
            // blow up into an error page — the campaign stays queued/processing
            // so it can be retried after fixing the mail configuration.
            // Flash output is rendered raw, so escape the provider message.
            $this->session->flash(
                'error',
                $this->translator->get(
                    'Sending failed: :error',
                    [':error' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')],
                ),
            );
            return $response->withHeader('Location', admin_url('/newsletter/campaigns'))->withStatus(302);
        }
        $this->searchCache->bump('campaign_search');
        if ($sent === 0) {
            $this->session->flash(
                'info',
                $this->translator->get('Campaign sent — no active subscribers matched the filters. Check subscriber status and filter settings.'),
            );
        } elseif ($campaign->isQueued() || $campaign->isProcessing()) {
            $this->session->flash(
                'success',
                $this->translator->get(
                    ':sent email(s) dispatched in this batch. The rest will be sent automatically by cron.',
                    [':sent' => (string) $sent],
                ),
            );
        } else {
            $this->session->flash(
                'success',
                $this->translator->get('Campaign sent to :sent subscriber(s).', [':sent' => (string) $sent]),
            );
        }
        return $response->withHeader('Location', admin_url('/newsletter/campaigns'))->withStatus(302);
    }

    public function resendCampaign(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->campaigns->findById((int) $args['id']);
        if ($campaign === null) {
            return $response->withStatus(404);
        }

        if (!$campaign->isSent()) {
            $this->session->flash('error', 'Only sent campaigns can be resent.');
            return $response->withHeader('Location', admin_url('/newsletter/campaigns'))->withStatus(302);
        }

        $this->campaigns->clearRecipients($campaign->getId());
        $campaign->setStatus('scheduled');
        $campaign->setScheduledAt(new \DateTimeImmutable());
        $campaign->setSentAt(null);
        $campaign->setOpensCount(0);
        $campaign->setOpensCountB(0);
        $campaign->setClicksCount(0);
        $campaign->setClicksCountB(0);
        // Reset queue progress so the resend starts from the first subscriber.
        $campaign->setProcessedCount(0);
        $campaign->setTotalCount(0);
        $campaign->setLastSubscriberId(0);
        $this->campaigns->save($campaign);
        $this->searchCache->bump('campaign_search');

        try {
            $sent = $this->newsletterService->sendCampaign($campaign);
        } catch (\Throwable $e) {
            $this->session->flash(
                'error',
                $this->translator->get(
                    'Sending failed: :error',
                    [':error' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')],
                ),
            );
            return $response->withHeader('Location', admin_url('/newsletter/campaigns'))->withStatus(302);
        }
        if ($sent === 0) {
            $this->session->flash('info', $this->translator->get('Campaign sent — no active subscribers matched the filters.'));
        } elseif ($campaign->isQueued() || $campaign->isProcessing()) {
            $this->session->flash(
                'success',
                $this->translator->get(
                    ':sent email(s) resent in this batch. The rest will be sent automatically by cron.',
                    [':sent' => (string) $sent],
                ),
            );
        } else {
            $this->session->flash(
                'success',
                $this->translator->get('Campaign resent to :sent subscriber(s).', [':sent' => (string) $sent]),
            );
        }
        return $response->withHeader('Location', admin_url('/newsletter/campaigns'))->withStatus(302);
    }

    private function fillCampaign(Campaign $campaign, array $body): void
    {
        $campaign->setSubjectB(!empty($body['subject_b']) ? $body['subject_b'] : null);
        $campaign->setBodyHtmlB(!empty($body['body_html_b']) ? $body['body_html_b'] : null);
        $campaign->setAbTestEnabled(!empty($body['ab_test_enabled']));
        $campaign->setVariationBWeight(!empty($body['variation_b_weight']) ? (int) $body['variation_b_weight'] : 50);
        $campaign->setMinOrdersFilter(!empty($body['min_orders_filter']) ? (int) $body['min_orders_filter'] : null);
        $campaign->setMaxOrdersFilter(!empty($body['max_orders_filter']) ? (int) $body['max_orders_filter'] : null);
        $campaign->setMinOpenRateFilter(!empty($body['min_open_rate_filter']) ? (int) $body['min_open_rate_filter'] : null);
        $campaign->setMaxOpenRateFilter(!empty($body['max_open_rate_filter']) ? (int) $body['max_open_rate_filter'] : null);
        $campaign->setMinClickRateFilter(!empty($body['min_click_rate_filter']) ? (int) $body['min_click_rate_filter'] : null);
        $campaign->setMaxClickRateFilter(!empty($body['max_click_rate_filter']) ? (int) $body['max_click_rate_filter'] : null);
        $campaign->setFromName(!empty($body['from_name']) ? $body['from_name'] : null);
        $campaign->setFromEmail(!empty($body['from_email']) ? $body['from_email'] : null);
        $campaign->setTagFilter(!empty($body['tag_filter']) ? $body['tag_filter'] : null);
        $campaign->setSourceFormFilter(!empty($body['source_form_filter']) ? (int) $body['source_form_filter'] : null);

        $campaign->setRecurring(!empty($body['is_recurring']));
        if ($campaign->isRecurring()) {
            $interval = $body['recurring_interval'] ?? 'weekly';
            $campaign->setRecurringInterval($interval);
            $campaign->setRecurringTime($body['recurring_time'] ?? '09:00');
            $campaign->setRecurringPostCount(isset($body['recurring_post_count']) ? (int) $body['recurring_post_count'] : 1);
            $campaign->setRecurringPostTypes($body['recurring_post_types'] ?? 'post');
            $campaign->setRecurringCategoryId(!empty($body['recurring_category_id']) ? (int) $body['recurring_category_id'] : null);
            $campaign->setRecurringTagId(!empty($body['recurring_tag_id']) ? (int) $body['recurring_tag_id'] : null);
            $campaign->setRecurringCategoryIds(!empty($body['recurring_category_ids']) && is_array($body['recurring_category_ids']) ? implode(',', array_map('intval', $body['recurring_category_ids'])) : null);
            $campaign->setRecurringTagIds(!empty($body['recurring_tag_ids']) && is_array($body['recurring_tag_ids']) ? implode(',', array_map('intval', $body['recurring_tag_ids'])) : null);
            $campaign->setRecurringLanguage(!empty($body['recurring_language']) ? $body['recurring_language'] : null);

            if ($interval === 'weekly') {
                $days = $body['recurring_day_weekly'] ?? [];
                $campaign->setRecurringDay(is_array($days) ? implode(',', $days) : (string) $days);
            } elseif ($interval === 'monthly') {
                $campaign->setRecurringDay(isset($body['recurring_day_monthly']) ? (string) $body['recurring_day_monthly'] : '1');
            } else {
                $campaign->setRecurringDay(null);
            }
            $campaign->setStatus('draft');
            $campaign->setScheduledAt(null);
        }

        if (isset($body['tracking_enabled'])) {
            $trackingValues = $body['tracking_enabled'];
            if (!is_array($trackingValues)) {
                $trackingValues = [$trackingValues];
            }

            $trackingMask = 0;
            foreach ($trackingValues as $value) {
                if (is_numeric($value)) {
                    $trackingMask |= (int) $value;
                }
            }
            $campaign->setTrackingEnabled(max(0, min(3, $trackingMask)));
        } else {
            $campaign->setTrackingEnabled(0);
        }
        if (!empty($body['scheduled_at'])) {
            $campaign->setScheduledAt(new \DateTimeImmutable($body['scheduled_at']));
            $campaign->setStatus('scheduled');
        }
    }

    /**
     * Build cache key parts when a server-side search/filter is active.
     * Returns null for unfiltered views (handled client-side).
     *
     * @return array<string, mixed>|null
     */
    private function subscriberSearchKeyParts(array $filters, Pagination $pagination): ?array
    {
        $search = trim($filters['search'] ?? '');
        $status = $filters['status'] ?? '';
        if ($search === '' && $status === '') {
            return null;
        }
        return [
            'v'        => 1,
            'search'   => $search,
            'status'   => $status,
            'sort'     => $pagination->sortBy  ?? '',
            'dir'      => $pagination->sortDir ?? 'desc',
            'page'     => $pagination->page,
            'perPage'  => $pagination->perPage,
        ];
    }

    /**
     * Validate cached items are proper Subscriber instances.
     *
     * @param array<int, mixed> $items
     * @return Subscriber[]
     */
    private function validSubscribers(array $items): array
    {
        return array_filter($items, fn ($item) => $item instanceof Subscriber);
    }

    /**
     * Build cache key parts for campaign recipient list.
     *
     * @return array<string, mixed>|null
     */
    private function recipientSearchKeyParts(int $campaignId, ?string $search, ?string $filter, Pagination $pagination): ?array
    {
        if ($search === '' && $filter === null) {
            return null;
        }
        return [
            'campaignId' => $campaignId,
            'search'     => $search,
            'filter'     => $filter,
            'sort'       => $pagination->sortBy  ?? '',
            'dir'        => $pagination->sortDir ?? 'desc',
            'page'       => $pagination->page,
            'perPage'    => $pagination->perPage,
        ];
    }
}
