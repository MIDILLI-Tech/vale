<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Mail\MailService;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Theme\ColorValueValidator;
use ValeApp\Infrastructure\Theme\ThemeManager;
use ValeApp\Infrastructure\Translation\Translator;

final class MailController
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly DbalSettingsRepository $settings,
        private readonly MailService $mailer,
        private readonly SessionManager $session,
        private readonly ThemeManager $themeManager,
    ) {
    }

    // ── Config (form action) ──────────────────────────────────────────

    public function save(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        unset($body['_csrf_token'], $body['_METHOD']);

        $allowed = [
            'mail_driver', 'mail_from_address', 'mail_from_name',
            'mail_smtp_host', 'mail_smtp_port', 'mail_smtp_user',
            'mail_smtp_pass', 'mail_smtp_encryption',
            'mail_resend_api_key', 'mail_unosend_api_key',
            'mail_mailgun_api_key', 'mail_mailgun_domain',
            'mail_sendgrid_api_key', 'mail_log_enabled',
            'mail_batch_enabled', 'mail_batch_size',
        ];

        $data = array_intersect_key($body, array_flip($allowed));

        // Log driver always logs; force enabled so checkbox stays checked
        if (($data['mail_driver'] ?? $body['mail_driver'] ?? '') === 'log') {
            $data['mail_log_enabled'] = '1';
        }

        // Collect all mail_notification_* keys (checkbox toggles)
        foreach ($body as $key => $value) {
            if (str_starts_with($key, 'mail_notification_')) {
                $data[$key] = $value === '1' ? '1' : '0';
            }
        }

        $this->settings->setMany($data, 'mail');

        // Email colors form (Templates sub-tab) — validated separately
        if (isset($body['_colors_form'])) {
            $this->saveEmailColors($body);
            $this->session->set('flash_success', $this->translator->get('Email color settings saved.'));
        } else {
            $this->session->set('flash_success', $this->translator->get('Mail settings saved.'));
        }
        return $this->redirectToMail($request, $response);
    }

    /**
     * Persist email color settings from the Templates sub-tab form.
     * Dynamically saves all theme color variables as mail_color_* settings.
     *
     * @param array<string, mixed> $body
     */
    private function saveEmailColors(array $body): void
    {
        $useThemeColors = isset($body['mail_use_theme_colors']) && $body['mail_use_theme_colors'] === '1' ? '1' : '0';
        $this->settings->set('mail_use_theme_colors', $useThemeColors, 'mail');

        if ($useThemeColors === '0') {
            $themeSlug = $this->settings->get('active_theme', 'default');
            $schema    = $this->themeManager->getColorVariablesSchema($themeSlug);

            foreach ($schema as $varName => $spec) {
                if (!is_string($varName) || !str_starts_with($varName, '--')) {
                    continue;
                }
                $settingKey = 'mail_color_' . ltrim(str_replace('--theme-', '', $varName), '-');
                $fallback   = (string) ($spec['default'] ?? '#000000');
                $value      = trim((string) ($body[$settingKey] ?? ''));
                if (!ColorValueValidator::isValid($value)) {
                    $value = $fallback;
                }
                $this->settings->set($settingKey, $value, 'mail');
            }
        }
    }

    // ── Test send ─────────────────────────────────────────────────────

    public function test(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $to   = trim($body['test_to'] ?? '');

        if ($to === '') {
            $this->session->set('flash_error', $this->translator->get('Please enter a recipient email address.'));
            return $this->redirectToMail($request, $response);
        }

        try {
            $this->mailer->send(new MailMessage(
                to:       $to,
                subject:  '[' . ($this->settings->get('site_name', 'Vale')) . '] ' . $this->translator->get('Test email'),
                htmlBody: '<h2>' . $this->translator->get('Test email') . '</h2><p>' . $this->translator->get('If you received this, mail is working correctly.') . '</p>',
                textBody: $this->translator->get('Test email — if you received this, mail is working correctly.'),
            ));
            $this->session->set('flash_success', $this->translator->get('Test email sent to :to.', [':to' => $to]));
        } catch (\Throwable $e) {
            $this->session->set('flash_error', $this->translator->get('Mail failed: :error', [':error' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]));
        }

        return $this->redirectToMail($request, $response);
    }

    // ── Email Templates (customise Twig templates) ──────────────────

    /**
     * Return the list of available mail templates with their content.
     * Used by the wizard page and AJAX.
     */
    private function getMailTemplates(): array
    {
        $viewsDir  = dirname(__DIR__, 3) . '/resources/views/mail';
        $templates = [];

        if (!is_dir($viewsDir)) {
            return $templates;
        }

        foreach (glob($viewsDir . '/*.twig') as $path) {
            $name = basename($path, '.twig');

            $default = file_get_contents($path);
            $custom  = $this->settings->get('mail_template_' . $name, '');

            $templates[] = [
                'name'    => $name,
                'label'   => $this->templateLabel($name),
                'default' => $default,
                'custom'  => ($custom !== '' && $custom !== null) ? $custom : null,
            ];
        }

        usort($templates, fn ($a, $b) => $a['name'] <=> $b['name']);

        return $templates;
    }

    private function templateLabel(string $name): string
    {
        return match ($name) {
            'layout'                  => 'Layout (Base)',
            'order-confirmation'      => 'Order Confirmation',
            'order-status-update'     => 'Order Status Update',
            'order-note'              => 'Order Note (Admin)',
            'payment-received'        => 'Payment Received',
            'password-reset'          => 'Password Reset',
            'welcome'                 => 'Welcome',
            'newsletter-confirmation' => 'Newsletter Confirmation',
            default                   => str_replace(['-', '_'], ' ', ucfirst($name)),
        };
    }

    /**
     * AJAX: return template content as JSON.
     */
    public function getTemplate(Request $request, Response $response, array $args): Response
    {
        $name     = $args['template'] ?? '';
        $viewsDir = dirname(__DIR__, 3) . '/resources/views/mail';

        if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
            $payload = json_encode(['ok' => false, 'error' => 'Invalid template name'], JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $file = $viewsDir . '/' . $name . '.twig';
        if (!file_exists($file)) {
            $payload = json_encode(['ok' => false, 'error' => 'Template not found'], JSON_THROW_ON_ERROR);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $default = file_get_contents($file);
        $custom  = $this->settings->get('mail_template_' . $name, '');

        $payload = json_encode([
            'ok'      => true,
            'name'    => $name,
            'label'   => $this->templateLabel($name),
            'default' => $default,
            'custom'  => ($custom !== '' && $custom !== null) ? $custom : null,
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Save custom template content.
     */
    public function saveTemplate(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim($body['template_name'] ?? '');

        if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
            $this->session->flash('error', 'Invalid template name.');
            return $this->redirectToMail($request, $response);
        }

        $content = $body['template_content'] ?? '';
        if ($content === '') {
            $this->session->flash('error', 'Template content cannot be empty.');
            return $this->redirectToMail($request, $response);
        }

        $this->settings->set('mail_template_' . $name, $content, 'mail');
        $this->session->flash('success', 'Template "' . $this->templateLabel($name) . '" saved.');
        return $this->redirectToMail($request, $response);
    }

    /**
     * Reset template to default (delete custom from DB).
     */
    public function resetTemplate(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $name = trim($body['template_name'] ?? '');

        if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
            $this->session->flash('error', 'Invalid template name.');
            return $this->redirectToMail($request, $response);
        }

        $this->settings->delete('mail_template_' . $name);
        $this->session->flash('success', 'Template "' . $this->templateLabel($name) . '" reset to default.');
        return $this->redirectToMail($request, $response);
    }

    /**
     * Redirect back to the mail settings tab, preserving the active subtab
     * (notifications | setup | templates) so the user doesn't lose their place.
     */
    private function redirectToMail(Request $request, Response $response): Response
    {
        $body   = (array) $request->getParsedBody();
        $subtab = trim((string) ($body['_subtab'] ?? ''));

        $allowed = ['notifications', 'setup', 'templates'];
        if (!in_array($subtab, $allowed, true)) {
            $subtab = 'notifications';
        }

        $query = http_build_query(['tab' => 'mail', 'subtab' => $subtab]);
        return $response->withHeader('Location', admin_url('/settings?' . $query))->withStatus(302);
    }

    // ── File-based Logs ──────────────────────────────────────────────

    public function logs(Request $request, Response $response): Response
    {
        $logFile = $this->getLogFilePath();
        $entries = $this->parseLogFile($logFile);

        $params   = $request->getQueryParams();
        $search   = trim($params['search'] ?? '');
        $filtered = $entries;

        if ($search !== '') {
            $lc       = mb_strtolower($search);
            $filtered = array_filter($entries, static function (array $e) use ($lc): bool {
                return str_contains(mb_strtolower($e['to_address']), $lc)
                    || str_contains(mb_strtolower($e['subject']), $lc)
                    || str_contains(mb_strtolower($e['driver']), $lc)
                    || str_contains(mb_strtolower($e['created_at']), $lc);
            });
        }

        $total   = count($filtered);
        $perPage = 20;
        $page    = max(1, (int) ($params['page'] ?? 1));
        $sliced  = array_slice($filtered, ($page - 1) * $perPage, $perPage);

        return $this->twig->render($response, 'admin/mail/logs.twig', [
            'logs'        => $sliced,
            'total'       => $total,
            'client_side' => true,
            'page'        => $page,
            'per_page'    => $perPage,
            'search'      => $search,
            'base_url'    => admin_url('/mail/logs'),
        ]);
    }

    public function clearLogs(Request $request, Response $response): Response
    {
        $logFile = $this->getLogFilePath();
        if (is_file($logFile)) {
            @unlink($logFile);
        }
        $this->session->set('flash_success', 'Mail logs cleared.');
        return $response->withHeader('Location', admin_url('/mail/logs'))->withStatus(302);
    }

    public function deleteLog(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id < 1) {
            return $response->withHeader('Location', admin_url('/mail/logs'))->withStatus(302);
        }

        $logFile  = $this->getLogFilePath();
        $entries  = $this->parseLogFileFileOrder($logFile);
        $total    = count($entries);
        $fileIdx  = $total - $id;

        if ($fileIdx < 0 || $fileIdx >= $total) {
            $this->session->set('flash_error', 'Log entry not found.');
            return $response->withHeader('Location', admin_url('/mail/logs'))->withStatus(302);
        }

        array_splice($entries, $fileIdx, 1);
        $this->writeLogFile($logFile, $entries);

        $this->session->set('flash_success', 'Log entry deleted.');
        return $response->withHeader('Location', admin_url('/mail/logs'))->withStatus(302);
    }

    private function getLogFilePath(): string
    {
        $appCfg = require dirname(__DIR__, 3) . '/config/app.php';
        return ($appCfg['paths']['storage'] ?? dirname(__DIR__, 3) . '/storage') . '/logs/mail.log';
    }

    /**
     * Parse storage/logs/mail.log into structured entries.
     * @return array<int, array{int id, string created_at, string to_address, string subject, string driver, string status, string|null error_message, string|null html_body}>
     */
    private function parseLogFile(string $logFile): array
    {
        if (!is_file($logFile)) {
            return [];
        }

        $content = @file_get_contents($logFile);
        if ($content === false || $content === '') {
            return [];
        }

        $chunks   = preg_split('/^={80}$/m', $content);
        $entries  = [];
        $id       = 0;

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $id++;
            $createdAt = '';
            $to        = '';
            $subject   = '';
            $driver    = 'log';
            $status    = 'sent';
            $error     = null;
            $htmlBody  = '';

            foreach (explode("\n", $chunk) as $line) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]\s+TO=(.+)$/', $line, $m)) {
                    $createdAt = $m[1];
                    $to        = $m[2];
                } elseif (str_starts_with($line, 'SUBJECT=')) {
                    $subject = substr($line, 8);
                } elseif (str_starts_with($line, 'DRIVER=')) {
                    $driver = substr($line, 7);
                } elseif (str_starts_with($line, 'STATUS=')) {
                    $status = substr($line, 7);
                } elseif (str_starts_with($line, 'ERROR=')) {
                    $error = substr($line, 6);
                }
            }

            $bodyMarker = '--- HTML BODY ---';
            $bodyPos    = strpos($chunk, $bodyMarker);
            if ($bodyPos !== false) {
                $htmlBody = trim(substr($chunk, $bodyPos + strlen($bodyMarker)));
            }

            $entries[] = [
                'id'            => $id,
                'created_at'    => $createdAt,
                'to_address'    => $to,
                'subject'       => $subject,
                'driver'        => $driver,
                'status'        => $status,
                'error_message' => $error,
                'html_body'     => $htmlBody,
            ];
        }

        return array_reverse($entries);
    }

    /**
     * Parse file in chronological order (oldest first) for delete/rebuild.
     */
    private function parseLogFileFileOrder(string $logFile): array
    {
        $entries = $this->parseLogFile($logFile);
        return array_reverse($entries);
    }

    /**
     * Rewrite log file from entries array (chronological order).
     */
    private function writeLogFile(string $logFile, array $entries): void
    {
        if ($entries === []) {
            @unlink($logFile);
            return;
        }

        $separator = str_repeat('=', 80);
        $parts     = [];

        foreach ($entries as $e) {
            $chunk  = $separator . "\n";
            $chunk .= '[' . $e['created_at'] . '] TO=' . $e['to_address'] . "\n";
            $chunk .= 'SUBJECT=' . $e['subject'] . "\n";
            $chunk .= 'FROM=' . ($e['from_address'] ?? '(default)') . "\n";
            $chunk .= 'DRIVER=' . $e['driver'] . "\n";
            $chunk .= 'STATUS=' . ($e['status'] ?? 'sent') . "\n";
            if (!empty($e['error_message'])) {
                $chunk .= 'ERROR=' . $e['error_message'] . "\n";
            }
            $chunk .= "--- HTML BODY ---\n";
            $chunk .= $e['html_body'] . "\n";
            $chunk .= $separator . "\n\n";
            $parts[] = $chunk;
        }

        @file_put_contents($logFile, implode('', $parts), LOCK_EX);
    }

    /**
     * GET /admin/mail/preview/{template}
     * Render the raw email HTML with mock data — used as an iframe src
     * inside the Templates sub-tab preview modal.
     */
    public function preview(Request $request, Response $response, array $args): Response
    {
        $template = basename((string) ($args['template'] ?? ''), '.twig');
        if (!preg_match('/^[a-z0-9_-]+$/', $template) || !in_array($template, $this->getMailTemplateList(), true)) {
            return $response->withStatus(404);
        }

        $mockData = array_merge(
            $this->getMockData($template),
            ['vl_email_colors' => $this->mailer->getEmailColorContext()],
            $this->mailer->getEmailFlatColors(),
        );

        $html = $this->twig->getEnvironment()->render('mail/' . $template . '.twig', $mockData);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Return list of available mail templates (excluding layout).
     */
    private function getMailTemplateList(): array
    {
        $viewsDir  = dirname(__DIR__, 3) . '/resources/views/mail';
        $templates = [];

        if (!is_dir($viewsDir)) {
            return $templates;
        }

        foreach (glob($viewsDir . '/*.twig') as $path) {
            $name = basename($path, '.twig');
            if ($name !== 'layout') {
                $templates[] = $name;
            }
        }

        sort($templates);
        return $templates;
    }

    /**
     * Mock data for email preview.
     */
    private function getMockData(string $template): array
    {
        $base = [
            'site_name' => 'Vale Demo Store',
            'site_url'  => 'https://example.com',
        ];

        $mocks = [
            'welcome'                 => array_merge($base, ['name' => 'John Doe', 'login_url' => 'https://example.com/login']),
            'password-reset'          => array_merge($base, ['name' => 'John Doe', 'reset_url' => 'https://example.com/reset?token=abc123']),
            'email-verify'            => array_merge($base, ['name' => 'John Doe', 'verify_url' => 'https://example.com/verify?token=abc123']),
            'newsletter-confirmation' => array_merge($base, ['confirm_url' => 'https://example.com/newsletter/confirm?token=abc123']),
            'abandoned-cart'          => array_merge($base, [
                'item_count'   => 3, 'subtotal' => 149.99, 'current_currency' => 'USD',
                'checkout_url' => 'https://example.com/cart',
            ]),
            'order-confirmation' => array_merge($base, [
                'order' => (object)[
                    'orderNumber' => '1001',
                    'status'      => 'processing',
                    'total'       => 299.99,
                    'currency'    => 'USD',
                    'createdAt'   => new \DateTimeImmutable(),
                    'billing'     => (object)['email' => 'john@example.com', 'first_name' => 'John', 'last_name' => 'Doe', 'address' => '123 Main St', 'city' => 'NYC', 'postcode' => '10001'],
                    'items'       => [(object)['name' => 'Product A', 'quantity' => 2, 'price' => 149.99]],
                ],
            ]),
            'order-status-update' => array_merge($base, [
                'order' => (object)[
                    'orderNumber' => '1002',
                    'status'      => 'shipped',
                    'total'       => 199.99,
                    'currency'    => 'USD',
                    'items'       => [(object)['name' => 'Product B', 'quantity' => 1, 'price' => 199.99]],
                ],
                'old_status' => 'processing',
            ]),
            'order-note' => array_merge($base, [
                'subject' => 'Order Update',
                'order'   => (object)['orderNumber' => '1001', 'total' => 299.99, 'currency' => 'USD', 'billing' => (object)['first_name' => 'John']],
                'message' => 'Your order will be shipped tomorrow.',
            ]),
            'payment-link' => array_merge($base, [
                'order'        => (object)['orderNumber' => '1001', 'total' => 299.99, 'currency' => 'USD', 'createdAt' => new \DateTimeImmutable(), 'billing' => (object)['first_name' => 'John']],
                'payment_link' => 'https://example.com/pay/abc123',
            ]),
            'payment-received' => array_merge($base, [
                'order' => (object)[
                    'orderNumber'   => '1001', 'status' => 'paid', 'transactionId' => 'txn_123',
                    'total'         => 299.99, 'currency' => 'USD', 'paidAt' => new \DateTimeImmutable(),
                    'billing'       => (object)['email' => 'john@example.com', 'first_name' => 'John', 'last_name' => 'Doe'],
                    'paymentMethod' => 'stripe',
                    'items'         => [(object)['name' => 'Product A', 'quantity' => 1, 'price' => 299.99]],
                ],
            ]),
            'payment-claim-admin' => array_merge($base, [
                'order' => (object)['orderNumber' => '1001', 'total' => 299.99, 'currency' => 'USD', 'billing' => (object)['email' => 'john@example.com']],
            ]),
            'invoice' => array_merge($base, [
                'invoice' => (object)['invoice_number' => 'INV-001', 'issued_at' => '2024-01-15', 'total' => 299.99, 'currency' => 'USD', 'tax' => 59.99, 'tax_rate' => 20, 'items' => [(object)['name' => 'Product A', 'quantity' => 1, 'price' => 299.99]], 'revision' => 1],
                'order'   => (object)['orderNumber' => '1001', 'status' => 'paid'],
            ]),
            'refund-request-customer' => array_merge($base, [
                'order'            => (object)['orderNumber' => '1001', 'status' => 'refund_requested', 'total' => 299.99, 'currency' => 'USD'],
                'reason'           => 'Product did not match description.',
                'order_status_url' => 'https://example.com/order/1001',
            ]),
            'refund-request-admin' => array_merge($base, [
                'order'  => (object)['orderNumber' => '1001', 'id' => 1, 'status' => 'refund_requested', 'total' => 299.99, 'currency' => 'USD', 'billing' => (object)['email' => 'john@example.com']],
                'reason' => 'Product did not match description.',
            ]),
            'refund-request-reviewed' => array_merge($base, [
                'order'            => (object)['orderNumber' => '1001', 'status' => 'refunded', 'total' => 299.99, 'currency' => 'USD'],
                'approved'         => true,
                'note'             => 'Refund processed successfully.',
                'order_status_url' => 'https://example.com/order/1001',
            ]),
            'refund-request-auto-approved' => array_merge($base, [
                'order'            => (object)['orderNumber' => '1001', 'total' => 299.99, 'currency' => 'USD'],
                'customer_name'    => 'John Doe',
                'order_status_url' => 'https://example.com/order/1001',
            ]),
            'subscription-welcome' => array_merge($base, [
                'name'            => 'John Doe',
                'plan_name'       => 'Premium Plan',
                'amount'          => '$9.99',
                'currency_symbol' => '$',
                'expires_at'      => '2024-12-31',
                'membership_url'  => 'https://example.com/membership',
            ]),
            'subscription-cancelled' => array_merge($base, [
                'plan_name'      => 'Premium Plan', 'name' => 'John Doe',
                'expires_at'     => '2024-12-31',
                'message'        => 'No reason provided.',
                'membership_url' => 'https://example.com/membership',
            ]),
            'tracking-update' => array_merge($base, [
                'order' => (object)[
                    'orderNumber' => '1001', 'carrier' => 'fedex', 'trackingNumber' => 'TRK123',
                    'total'       => 299.99, 'currency' => 'USD',
                ],
                'track_url' => 'https://example.com/track/TRK123',
            ]),
            'comment-notification-admin' => array_merge($base, [
                'author_name'       => 'Jane Smith', 'author_email' => 'jane@example.com',
                'entity_type_label' => 'Post', 'entity_title' => 'Hello World', 'entity_url' => 'https://example.com/post/1',
                'rating'            => 5,
            ]),
            'comment-reply' => array_merge($base, [
                'author_name'     => 'Admin', 'entity_title' => 'Hello World',
                'entity_url'      => 'https://example.com/post/1', 'comment_url' => 'https://example.com/post/1#comments',
                'comment_content' => 'This is a reply to your comment.',
            ]),
            'review-notification-admin' => array_merge($base, [
                'author_name'  => 'John Doe', 'author_email' => 'john@example.com',
                'entity_title' => 'Product A', 'entity_url' => 'https://example.com/product/1',
                'rating'       => 4,
            ]),
            'stock-warning-admin' => array_merge($base, [
                'product_name'  => 'Product A', 'sku' => 'SKU-001',
                'current_stock' => 2, 'is_out_of_stock' => false,
            ]),
            'order-status-update' => array_merge($base, [
                'order'      => (object)['orderNumber' => '1001', 'status' => 'shipped', 'total' => 299.99, 'currency' => 'USD', 'items' => [(object)['name' => 'Product A', 'quantity' => 1, 'price' => 299.99]]],
                'old_status' => 'processing',
            ]),
        ];

        return $mocks[$template] ?? $base;
    }
}
