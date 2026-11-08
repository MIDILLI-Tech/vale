<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Payment;

final class HttpTransport
{
    /**
     * Performs one request and never follows redirects: a redirect target is
     * not validated by the caller's SSRF guard, so a 3xx from an attacker
     * controlled endpoint (webhook receiver, gateway host) must be reported
     * as-is instead of being fetched from a private/internal address.
     *
     * @param string[] $headers
     * @return array{ok: bool, status: int, body: string, headers: string, error: string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 15,
    ): array {
        if (\function_exists('curl_init')) {
            $ch = \curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Failed to initialize cURL.'];
            }

            $method = strtoupper($method);

            $headersResponse = '';
            $collectHeaders  = static function ($ch, string $line) use (&$headersResponse): int {
                $headersResponse .= $line;
                return strlen($line);
            };

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => $collectHeaders,
            ];

            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
            } elseif ($method !== 'GET') {
                $options[CURLOPT_CUSTOMREQUEST] = $method;
            }

            if ($body !== null && $method !== 'GET') {
                $options[CURLOPT_POSTFIELDS] = $body;
            }

            \curl_setopt_array($ch, $options);
            $raw      = \curl_exec($ch);
            $httpCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = (string) \curl_error($ch);

            // curl_close() is deprecated (no-op) since PHP 8.5; the handle is
            // released with $ch going out of scope.

            if ($raw === false || $curlErr !== '') {
                return ['ok' => false, 'status' => $httpCode, 'body' => '', 'headers' => $headersResponse, 'error' => 'cURL error: ' . $curlErr];
            }

            return ['ok' => true, 'status' => $httpCode, 'body' => (string) $raw, 'headers' => $headersResponse, 'error' => ''];
        }

        if (!filter_var(\ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return [
                'ok'      => false,
                'status'  => 0,
                'body'    => '',
                'headers' => '',
                'error'   => 'ext-curl is not installed and allow_url_fopen is disabled on this server.',
            ];
        }

        $context = stream_context_create([
            'http' => [
                'method'          => strtoupper($method),
                'header'          => implode("\r\n", $headers),
                'content'         => $body ?? '',
                'timeout'         => $timeout,
                'ignore_errors'   => true,
                // PHP follows redirects by default; that would bypass the
                // caller's private-host guard (see the class docblock).
                'follow_location' => 0,
            ],
        ]);

        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            $err = error_get_last();
            return [
                'ok'      => false,
                'status'  => 0,
                'body'    => '',
                'headers' => '',
                'error'   => 'HTTP stream error: ' . (is_array($err) && !empty($err['message']) ? $err['message'] : 'Request failed.'),
            ];
        }

        $meta            = stream_get_meta_data($stream);
        $responseHeaders = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
        $formatted       = self::formatStreamHeaders($responseHeaders);
        $raw             = stream_get_contents($stream);
        fclose($stream);

        $status = 0;
        if (!empty($responseHeaders[0]) && preg_match('/HTTP\/\S+\s+(\d{3})/', (string) $responseHeaders[0], $m) === 1) {
            $status = (int) $m[1];
        }

        if ($raw === false) {
            $err = error_get_last();
            return [
                'ok'      => false,
                'status'  => $status,
                'body'    => '',
                'headers' => $formatted,
                'error'   => 'HTTP stream error: ' . (is_array($err) && !empty($err['message']) ? $err['message'] : 'Request failed.'),
            ];
        }

        return ['ok' => true, 'status' => $status, 'body' => (string) $raw, 'headers' => $formatted, 'error' => ''];
    }

    private static function formatStreamHeaders(array $responseHeaders): string
    {
        return implode("\r\n", array_map('strval', $responseHeaders)) . "\r\n";
    }
}
