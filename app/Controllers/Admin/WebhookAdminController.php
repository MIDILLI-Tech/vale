<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use ValeApp\Application\Webhook\OutboundWebhookService;
use ValeApp\Domain\Shared\Entity\Webhook;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Infrastructure\Session\SessionManager;
use ValeApp\Infrastructure\Translation\Translator;

final class WebhookAdminController
{
    /**
     * Placeholder shown in the secret field when a secret is already stored.
     * The stored secret is never sent to the browser; submitting the form
     * with this value untouched must leave the stored secret unchanged.
     */
    private const SECRET_PLACEHOLDER = '••••••••';

    public function __construct(
        private readonly Translator $translator,
        private readonly Twig $twig,
        private readonly WebhookRepositoryInterface $webhooks,
        private readonly OutboundWebhookService $service,
        private readonly SessionManager $session,
    ) {
    }

    // ── Create ───────────────────────────────────────────────────────────────

    public function store(Request $request, Response $response): Response
    {
        $body            = (array) $request->getParsedBody();
        [$errors, $data] = $this->validate($body);

        if ($errors !== []) {
            $this->session->set('webhook_form_errors', $errors);
            $this->session->set('webhook_form_old', $body);
            return $response
                ->withHeader('Location', admin_url('/settings?tab=advanced&subtab=webhooks&webhook_modal=new'))
                ->withStatus(302);
        }

        $webhook = new Webhook($data['name'], $data['url'], $data['secret']);
        $webhook->setMethod($data['method']);
        $webhook->setBodyTemplate($data['body_template']);
        $webhook->setHeaders($data['headers']);
        $webhook->setEvents($data['events']);
        $webhook->setActive($data['active']);
        $this->webhooks->save($webhook);

        $this->session->set('flash_success', $this->translator->get($this->translator->get('Webhook created.')));
        return $response
            ->withHeader('Location', admin_url('/settings?tab=advanced&subtab=webhooks'))
            ->withStatus(302);
    }

    // ── Update ───────────────────────────────────────────────────────────────

    public function update(Request $request, Response $response, array $args): Response
    {
        $webhook = $this->webhooks->findById((int) $args['id']);
        if ($webhook === null) {
            return $response->withStatus(404);
        }

        $body            = (array) $request->getParsedBody();
        [$errors, $data] = $this->validate($body);

        if ($errors !== []) {
            $this->session->set('webhook_form_errors', $errors);
            $this->session->set('webhook_form_old', array_merge($body, ['_id' => (int) $args['id']]));
            return $response
                ->withHeader('Location', admin_url('/settings?tab=advanced&subtab=webhooks&webhook_modal=' . $args['id']))
                ->withStatus(302);
        }

        $webhook->setName($data['name']);
        $webhook->setUrl($data['url']);
        $webhook->setMethod($data['method']);
        $webhook->setBodyTemplate($data['body_template']);
        if ($data['secret'] !== null) {
            $webhook->setSecret($data['secret']);
        }
        $webhook->setHeaders($data['headers']);
        $webhook->setEvents($data['events']);
        $webhook->setActive($data['active']);
        $this->webhooks->save($webhook);

        $this->session->set('flash_success', $this->translator->get($this->translator->get('Webhook updated.')));
        return $response
            ->withHeader('Location', admin_url('/settings?tab=advanced&subtab=webhooks'))
            ->withStatus(302);
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $this->webhooks->delete((int) $args['id']);
        $this->session->set('flash_success', $this->translator->get($this->translator->get('Webhook deleted.')));
        return $response
            ->withHeader('Location', admin_url('/settings?tab=advanced&subtab=webhooks'))
            ->withStatus(302);
    }

    // ── Test delivery ────────────────────────────────────────────────────────

    public function test(Request $request, Response $response, array $args): Response
    {
        $webhook = $this->webhooks->findById((int) $args['id']);
        if ($webhook === null) {
            return $this->json($response, ['ok' => false, 'error' => 'Webhook not found'], 404);
        }

        $webhookEvents = $webhook->getEvents();
        $events        = $webhookEvents !== [] ? $webhookEvents : ['order.paid'];

        $results = [];
        foreach ($events as $e) {
            $results[] = [
                'event'  => $e,
                'result' => $this->service->deliverOne($webhook->getId(), $e, self::samplePayload($e)),
            ];
        }

        $allOk = !in_array(false, array_map(fn (array $r) => $r['result']['ok'], $results), true);

        return $this->json($response, [
            'ok'      => $allOk,
            'results' => $results,
        ]);
    }

    // ── Test with live form values (no saved webhook needed) ─────────────────

    public function testPreview(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $url    = trim($body['url'] ?? '');
        $method = strtoupper(trim($body['method'] ?? 'POST'));

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->json($response, ['ok' => false, 'error' => 'Invalid or missing URL'], 422);
        }

        if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $this->json($response, ['ok' => false, 'error' => 'URL must use http or https'], 422);
        }

        if ($this->isPrivateHost($url)) {
            return $this->json($response, ['ok' => false, 'error' => 'URL must point to a public internet host'], 422);
        }

        $bodyTemplate = trim($body['body_template'] ?? '') ?: null;

        // Secret: if the value is the masked placeholder and an editing_id is given, look up from DB
        $secretRaw = $body['secret'] ?? '';
        if ($secretRaw === self::SECRET_PLACEHOLDER && !empty($body['editing_id'])) {
            $saved  = $this->webhooks->findById((int) $body['editing_id']);
            $secret = $saved?->getSecret();
        } else {
            $secret = ($secretRaw !== '' && $secretRaw !== self::SECRET_PLACEHOLDER) ? $secretRaw : null;
        }

        // Headers: array of {key, value}
        $headers = [];
        foreach ((array) ($body['headers'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $k = preg_replace('/[^A-Za-z0-9\-_]/', '', trim((string) ($row['key'] ?? '')));
            $v = str_replace(["\r", "\n"], '', trim((string) ($row['value'] ?? '')));
            if ($k !== '') {
                $headers[$k] = $v;
            }
        }

        $formEvents = array_values(array_filter((array) ($body['events'] ?? []), fn ($e) => is_string($e) && array_key_exists($e, OutboundWebhookService::AVAILABLE_EVENTS)));
        $events     = $formEvents !== [] ? $formEvents : ['order.paid'];

        $results = [];
        foreach ($events as $e) {
            $results[] = [
                'event'  => $e,
                'result' => $this->service->deliverRaw($url, $method, $bodyTemplate, $secret, $headers, self::samplePayload($e), $e),
            ];
        }

        $allOk = !in_array(false, array_map(fn (array $r) => $r['result']['ok'], $results), true);

        return $this->json($response, [
            'ok'      => $allOk,
            'results' => $results,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{array<string,string>, array{name: string, url: string, secret: ?string, method: string, body_template: ?string, headers: array<string,string>, events: string[], active: bool}}
     */
    private function validate(array $body): array
    {
        $errors = [];

        $name      = trim($body['name'] ?? '');
        $url       = trim($body['url'] ?? '');
        $secretRaw = trim($body['secret'] ?? '');
        // The edit form shows the masked placeholder when a secret is stored
        // but never receives the real value; submitting it untouched must
        // leave the stored secret unchanged (update() skips null secrets).
        $secret    = ($secretRaw !== '' && $secretRaw !== self::SECRET_PLACEHOLDER) ? $secretRaw : null;
        $allowed   = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $method    = strtoupper(trim($body['method'] ?? 'POST'));
        if (!in_array($method, $allowed, true)) {
            $method = 'POST';
        }
        $body_template = trim($body['body_template'] ?? '');
        $body_template = $body_template !== '' ? $body_template : null;
        $events        = array_values(array_filter((array) ($body['events'] ?? []), fn ($e) => is_string($e) && array_key_exists($e, OutboundWebhookService::AVAILABLE_EVENTS)));
        $active        = !empty($body['is_active']);

        $headers = [];
        foreach ((array) ($body['headers'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $k = preg_replace('/[^A-Za-z0-9\-_]/', '', trim((string) ($row['key'] ?? '')));
            $v = str_replace(["\r", "\n"], '', trim((string) ($row['value'] ?? '')));
            if ($k !== '') {
                $headers[$k] = $v;
            }
        }

        if ($name === '') {
            $errors['name'] = $this->translator->get('Name is required.');
        }
        if ($url === '') {
            $errors['url'] = $this->translator->get('URL is required.');
        } elseif (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            $errors['url'] = 'Please enter a valid HTTP or HTTPS URL.';
        } elseif ($this->isPrivateHost($url)) {
            $errors['url'] = 'URL must point to a public internet host (internal/private addresses are not allowed).';
        }
        if ($events === []) {
            $errors['events'] = 'Select at least one event.';
        }

        $data = compact('name', 'url', 'secret', 'method', 'body_template', 'headers', 'events', 'active');
        return [$errors, $data];
    }

    private function isPrivateHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            return true;
        }
        $host = ltrim(rtrim($host, ']'), '['); // strip IPv6 brackets

        $asIp = filter_var($host, FILTER_VALIDATE_IP);
        if ($asIp !== false) {
            return !filter_var($asIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        $lower = strtolower($host);
        if (
            $lower === 'localhost'
            || str_ends_with($lower, '.local')
            || str_ends_with($lower, '.internal')
            || str_ends_with($lower, '.localhost')
        ) {
            return true;
        }

        $resolved = gethostbyname($host);
        if ($resolved === $host) {
            return true;
        }
        return !filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    /** @return array<string, mixed> */
    private static function samplePayload(string $event): array
    {
        $ts = date(\DateTimeInterface::ATOM);

        return match (true) {
            str_starts_with($event, 'order.') => [
                'order_id'             => 0,
                'order_number'         => 'TEST-' . date('YmdHis'),
                'order_status'         => 'processing',
                'order_total'          => '9.99',
                'currency'             => 'USD',
                'order_customer_email' => 'test@example.com',
                'order_customer_name'  => 'Test User',
                'items'                => [
                    ['product_id' => 1, 'name' => 'Sample Product', 'qty' => 1, 'price' => '9.99'],
                ],
                'new_status'     => $event === 'order.status_changed' ? 'shipped' : null,
            ],
            str_starts_with($event, 'post.') => [
                'post_id'           => 0,
                'post_title'        => 'Sample Post Title',
                'post_slug'         => 'sample-post-title',
                'post_status'       => 'published',
                'post_content_type' => 'post',
                'post_author_id'    => 1,
                'post_published_at' => $ts,
            ],
            str_starts_with($event, 'product.') => [
                'product_id'         => 0,
                'product_name'       => 'Sample Product',
                'product_slug'       => 'sample-product',
                'product_sku'        => 'SKU-0001',
                'product_status'     => 'published',
                'product_price'      => '9.99',
                'product_sale_price' => null,
                'product_stock'      => 10,
                'variations'         => [
                    [
                        'id'             => 1,
                        'sku'            => 'SKU-0001-RED-M',
                        'attributes'     => ['Color' => 'Red', 'Size' => 'M'],
                        'price'          => 9.99,
                        'sale_price'     => null,
                        'stock_quantity' => 5,
                        'stock_status'   => 'in_stock',
                    ],
                    [
                        'id'             => 2,
                        'sku'            => 'SKU-0001-BLUE-L',
                        'attributes'     => ['Color' => 'Blue', 'Size' => 'L'],
                        'price'          => 11.99,
                        'sale_price'     => 9.99,
                        'stock_quantity' => 0,
                        'stock_status'   => 'out_of_stock',
                    ],
                ],
            ],
            str_starts_with($event, 'user.') => [
                'user_id'      => 0,
                'user_name'    => 'Test User',
                'user_email'   => 'test@example.com',
                'roles'        => ['subscriber'],
            ],
            $event === 'form.submitted' => [
                'form_id'       => 1,
                'form_name'     => 'Contact Form',
                'form_slug'     => 'contact',
                'submission_id' => 1,
                'fields'        => [
                    'name'    => 'John Doe',
                    'email'   => 'john@example.com',
                    'message' => 'Hello from webhook!',
                ],
                'ip_address'   => '127.0.0.1',
                'user_agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36...',
                'submitted_at' => $ts,
            ],
            str_starts_with($event, 'subscriber.') => [
                'subscriber_id'     => 0,
                'subscriber_email'  => 'subscriber@example.com',
                'subscriber_name'   => 'Test Subscriber',
                'subscriber_status' => 'active',
                'subscriber_tier'   => 'free',
                'subscriber_tags'   => ['newsletter'],
            ],
            default => ['event' => $event],
        };
    }
}
