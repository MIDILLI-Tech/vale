<?php

declare(strict_types=1);

namespace ValeApp\App\Controllers\Front;

use ValeApp\Infrastructure\Content\CaptchaUrls;
use ValeApp\Infrastructure\Payment\HttpTransport;

trait CaptchaTrait
{
    /** @return array{captcha_type: string, captcha_question: ?string, captcha_site_key: ?string} */
    private function buildCaptchaData(string $typeSettingKey, string $keyPrefix = 'captcha_'): array
    {
        $type     = $this->settings->get($typeSettingKey, 'none');
        $question = null;
        $siteKey  = null;
        $prefix   = str_replace('_type', '', $typeSettingKey) . '_answer';

        if ($type === 'math') {
            [$a, $b] = [random_int(1, 9), random_int(1, 9)];
            $this->session->set($prefix, $a + $b);
            $question = "{$a} + {$b}";
        } elseif ($type === 'hcaptcha') {
            $siteKey = $this->settings->get('hcaptcha_site_key', '');
        } elseif ($type === 'recaptcha') {
            $siteKey = $this->settings->get('recaptcha_site_key', '');
        } elseif ($type === 'turnstile') {
            $siteKey = $this->settings->get('turnstile_site_key', '');
        }

        return [
            $keyPrefix . 'type'     => $type,
            $keyPrefix . 'question' => $question,
            $keyPrefix . 'site_key' => $siteKey,
        ];
    }

    private function validateCaptcha(array $body, string $typeSettingKey, ?\Psr\Http\Message\ServerRequestInterface $request = null): ?string
    {
        $type     = $this->settings->get($typeSettingKey, 'none');
        $prefix   = str_replace('_type', '', $typeSettingKey) . '_answer';
        $remoteIp = $request !== null ? vl_client_ip($request) : null;

        return $this->validateCaptchaValue($body, (string) $type, $prefix, '_hp_name', $remoteIp);
    }

    /**
     * Validates a captcha of an explicitly given type. The type must come
     * from the server (settings or session) — never from the request body,
     * or visitors could downgrade or disable the check.
     */
    private function validateCaptchaValue(
        array $body,
        string $type,
        string $answerSessionKey,
        string $honeypotField = '_hp_name',
        ?string $remoteIp = null,
    ): ?string {
        if ($type === 'math') {
            $expected = (int) $this->session->get($answerSessionKey, 0);
            $given    = (int) ($body['captcha_answer'] ?? -999);
            $this->session->remove($answerSessionKey);
            if ($given !== $expected || $expected === 0) {
                return 'Incorrect captcha answer. Please try again.';
            }
        } elseif ($type === 'hcaptcha') {
            $token  = trim($body['h-captcha-response'] ?? '');
            $secret = (string) ($this->settings->get('hcaptcha_secret_key', '') ?? '');
            $res    = $this->verifyCaptchaToken(CaptchaUrls::fromFile()->verifyUrl('hcaptcha'), $secret, $token, $remoteIp);
            if (!$res['success']) {
                return 'Captcha verification failed: ' . ($res['error'] ?? 'Unknown error.');
            }
        } elseif ($type === 'recaptcha') {
            $token  = trim($body['g-recaptcha-response'] ?? '');
            $secret = (string) ($this->settings->get('recaptcha_secret_key', '') ?? '');
            $res    = $this->verifyCaptchaToken(CaptchaUrls::fromFile()->verifyUrl('recaptcha'), $secret, $token, $remoteIp);
            if (!$res['success']) {
                return 'Captcha verification failed: ' . ($res['error'] ?? 'Unknown error.');
            }
            $threshold = (float) $this->settings->get('recaptcha_threshold', '0.5');
            if ($res['score'] !== null && $res['score'] < $threshold) {
                return "Captcha verification failed: Low score ({$res['score']}).";
            }
        } elseif ($type === 'turnstile') {
            $token  = trim($body['cf-turnstile-response'] ?? '');
            $secret = (string) ($this->settings->get('turnstile_secret_key', '') ?? '');
            $res    = $this->verifyCaptchaToken(CaptchaUrls::fromFile()->verifyUrl('turnstile'), $secret, $token, $remoteIp);
            if (!$res['success']) {
                return 'Captcha verification failed: ' . ($res['error'] ?? 'Unknown error.');
            }
        } elseif ($type === 'honeypot') {
            if (!empty($body[$honeypotField])) {
                return 'Spam detected.';
            }
        }

        return null;
    }

    /** @return array{success: bool, error: ?string, score: ?float} */
    private function verifyCaptchaToken(string $url, string $secret, string $token, ?string $remoteIp = null): array
    {
        if ($token === '') {
            return ['success' => false, 'error' => 'Missing captcha response.', 'score' => null];
        }
        if ($secret === '') {
            return ['success' => false, 'error' => 'Missing provider secret key.', 'score' => null];
        }

        $params = ['secret' => $secret, 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '') {
            $params['remoteip'] = $remoteIp;
        }

        $response = HttpTransport::request(
            'POST',
            $url,
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($params),
            5
        );

        if (!$response['ok']) {
            $err = $response['error'] ?: 'Unknown error.';
            return ['success' => false, 'error' => 'Connection error: ' . $err, 'score' => null];
        }

        $data = json_decode($response['body'], true);
        $ok   = (isset($data['success']) && $data['success'] === true);

        return [
            'success' => $ok,
            'error'   => $ok ? null : ($data['error-codes'][0] ?? 'Verification failed.'),
            'score'   => isset($data['score']) ? (float) $data['score'] : null,
        ];
    }
}
