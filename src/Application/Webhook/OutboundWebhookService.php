<?php

declare(strict_types=1);

namespace ValeApp\Application\Webhook;

use ValeApp\Domain\Shared\Entity\Webhook;
use ValeApp\Domain\Shared\Repository\WebhookRepositoryInterface;
use ValeApp\Infrastructure\Payment\HttpTransport;
use ValeApp\Infrastructure\Security\PrivateNetworkGuard;

/**
 * Delivers outbound webhook calls when internal events fire.
 *
 * Usage:
 *   $service->dispatch('order.paid', $payloadArray);
 *
 * Signing: if a webhook has a secret, the request includes:
 *   X-VL-Signature: sha256=<hex>
 * where the signature covers the raw JSON body (GitHub/Stripe style).
 *
 * Delivery is synchronous and fire-and-forget — errors are logged but
 * never thrown to the caller.
 */
final class OutboundWebhookService
{
    /** Events available for subscription in the admin UI. */
    public const AVAILABLE_EVENTS = [
        // ── Order events ───────────────────────────────────────────
        'order.paid'            => 'Order paid / payment confirmed',
        'order.status_changed'  => 'Order status changed (any transition)',

        // ── Post / content events ───────────────────────────────────
        'post.created'          => 'Post created (any status)',
        'post.published'        => 'Post published (first time going live)',
        'post.updated'          => 'Post updated (existing post saved)',

        // ── Product events ──────────────────────────────────────────
        'product.created'       => 'Product created',
        'product.updated'       => 'Product updated',
        'product.deleted'       => 'Product deleted',

        // ── User events ─────────────────────────────────────────────
        'user.registered'       => 'New user registered / created',

        // ── Form events ─────────────────────────────────────────────
        'form.submitted'        => 'Form submitted',

        // ── Newsletter events ────────────────────────────────────────
        'subscriber.created'    => 'New newsletter subscriber added',
    ];

    public function __construct(
        private readonly WebhookRepositoryInterface $webhooks,
    ) {
    }

    /**
     * Fire all active webhooks that subscribe to $event.
     *
     * @param array<string, mixed> $payload  Event-specific data
     */
    public function dispatch(string $event, array $payload): void
    {
        foreach ($this->webhooks->findActive() as $webhook) {
            if (!$webhook->hasEvent($event)) {
                continue;
            }
            if ($this->isPrivateHost($webhook->getUrl())) {
                error_log('[Outbound Webhook] Blocked delivery to private host: ' . $webhook->getUrl());
                continue;
            }
            $this->deliver($webhook, $event, $payload);
        }
    }

    private function isPrivateHost(string $url): bool
    {
        return PrivateNetworkGuard::isPrivateHost($url);
    }

    /**
     * Send a one-off delivery to a specific webhook (used by the admin "Test" button).
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, status: int, ms: int, error: ?string, body: ?string}
     */
    public function deliverOne(int $webhookId, string $event, array $payload): array
    {
        $webhook = $this->webhooks->findById($webhookId);
        if ($webhook === null) {
            return ['ok' => false, 'status' => 0, 'ms' => 0, 'error' => 'Webhook not found', 'body' => null];
        }

        if ($this->isPrivateHost($webhook->getUrl())) {
            return [
                'ok'     => false,
                'status' => 0,
                'ms'     => 0,
                'error'  => 'Requests to private/internal addresses are not allowed',
                'body'   => null,
            ];
        }

        return $this->deliver($webhook, $event, $payload);
    }

    /**
     * Send a one-off delivery with raw params (no saved webhook required — used by preview test).
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>  $payload
     * @return array{ok: bool, status: int, ms: int, error: ?string, body: ?string}
     */
    public function deliverRaw(
        string $url,
        string $method,
        ?string $bodyTemplate,
        ?string $secret,
        array $headers,
        array $payload = [],
        string $event = 'webhook.test',
    ): array {
        if ($this->isPrivateHost($url)) {
            return [
                'ok'     => false,
                'status' => 0,
                'ms'     => 0,
                'error'  => 'Requests to private/internal addresses are not allowed',
                'body'   => null,
            ];
        }
        $allowed = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];
        $method  = in_array($method, $allowed, true) ? $method : 'POST';
        $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);

        $body = null;
        if ($hasBody) {
            if ($bodyTemplate !== null && $bodyTemplate !== '') {
                $body = self::applyBodyTemplate($bodyTemplate, $payload);
            } else {
                $body = json_encode([
                    'event'     => $event,
                    'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'data'      => $payload,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            }
        }

        $curlHeaders = ['User-Agent: Vale-Webhook/1.0'];
        if ($hasBody) {
            $curlHeaders[] = 'Content-Type: application/json';
        }
        if ($hasBody && $body !== null && $secret !== null) {
            $curlHeaders[] = 'X-VL-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }
        foreach ($headers as $hName => $hValue) {
            $hName  = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $hName);
            if ($hName === '') {
                continue;
            }
            $curlHeaders[] = $hName . ': ' . str_replace(["\r", "\n"], '', (string) $hValue);
        }

        $start        = hrtime(true);
        $result       = $this->sendRequest($url, $method, $curlHeaders, $hasBody ? $body : null);
        $result['ms'] = (int) round((hrtime(true) - $start) / 1_000_000);

        return $result;
    }

    private function deliver(Webhook $webhook, string $event, array $payload): array
    {
        $method  = $webhook->getMethod(); // GET|POST|PUT|PATCH|DELETE
        $hasBody = in_array($method, ['POST', 'PUT', 'PATCH'], true);

        // ── Build request body ───────────────────────────────────────────────
        $body = null;
        if ($hasBody) {
            $template = $webhook->getBodyTemplate();
            if ($template !== null) {
                $body = self::applyBodyTemplate($template, $payload);
            } else {
                $encoded = json_encode([
                    'event'     => $event,
                    'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'data'      => $payload,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    error_log('[Outbound Webhook] JSON encode failed for event: ' . $event);
                    return ['ok' => false, 'status' => 0, 'ms' => 0, 'error' => 'JSON encode failed', 'body' => null];
                }
                $body = $encoded;
            }
        }

        // ── Build headers ────────────────────────────────────────────────────
        $curlHeaders = ['User-Agent: Vale-Webhook/1.0'];

        if ($hasBody) {
            $curlHeaders[] = 'Content-Type: application/json';
        }

        if ($hasBody && $body !== null && $webhook->getSecret() !== null) {
            $sig           = 'sha256=' . hash_hmac('sha256', $body, $webhook->getSecret());
            $curlHeaders[] = 'X-VL-Signature: ' . $sig;
        }

        foreach ($webhook->getHeaders() as $hName => $hValue) {
            $hName  = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $hName);
            if ($hName === '') {
                continue;
            }
            $hValue        = str_replace(["\r", "\n"], '', (string) $hValue);
            $curlHeaders[] = $hName . ': ' . $hValue;
        }

        $start        = hrtime(true);
        $result       = $this->sendRequest($webhook->getUrl(), $method, $curlHeaders, $hasBody ? $body : null);
        $result['ms'] = (int) round((hrtime(true) - $start) / 1_000_000);

        $responseBody = $result['body'];
        $httpCode     = $result['status'];
        $curlError    = $result['error'] ?? '';

        $ok     = $result['ok'];
        $status = $ok ? 'ok' : 'error';

        $webhook->recordDelivery($status, $result['ms']);
        try {
            $this->webhooks->save($webhook);
        } catch (\Throwable $e) {
            error_log('[Outbound Webhook] Failed to persist delivery status: ' . $e->getMessage());
        }

        if (!$ok) {
            error_log(sprintf(
                '[Outbound Webhook] Delivery failed for webhook #%d (%s) — HTTP %d, %dms, error: %s, body: %s',
                $webhook->getId(),
                $webhook->getUrl(),
                $httpCode,
                $result['ms'],
                $curlError,
                $responseBody !== null ? substr($responseBody, 0, 200) : '(no body)',
            ));
        }

        return $result;
    }

    /**
     * Substitute {{key}} placeholders in a Body Template against a payload.
     *
     * Value rendering rules:
     * - missing key  → keep literal `{{key}}` so the user notices a typo
     * - null value   → empty string
     * - bool         → `true` / `false`
     * - array        → JSON-encoded (so `{{tags}}` and `{{items}}` produce valid JSON
     *                  when embedded inside a Body Template string)
     * - scalar       → `(string) $value`
     *
     * Exposed static so the substitution can be unit-tested without an HTTP target.
     *
     * @param array<string,mixed> $payload
     */
    public static function applyBodyTemplate(string $template, array $payload): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', static function (array $m) use ($payload): string {
            if (!array_key_exists($m[1], $payload)) {
                return $m[0];
            }
            $value = $payload[$m[1]];
            if ($value === null) {
                return '';
            }
            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            return (string) $value;
        }, $template) ?? $template;
    }

    /**
     * @param string[] $headers
     * @return array{ok: bool, status: int, ms: int, error: ?string, body: ?string}
     */
    private function sendRequest(string $url, string $method, array $headers, ?string $body): array
    {
        $resp = HttpTransport::request($method, $url, $headers, $body, 10);

        if (!$resp['ok'] && $resp['status'] === 0) {
            return [
                'ok'     => false,
                'status' => 0,
                'ms'     => 0,
                'error'  => $resp['error'],
                'body'   => null,
            ];
        }

        $httpCode = $resp['status'];
        return [
            'ok'     => $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'ms'     => 0,
            'error'  => $httpCode >= 200 && $httpCode < 300 ? null : sprintf('HTTP %d', $httpCode),
            'body'   => substr($resp['body'], 0, 2000),
        ];
    }
}
