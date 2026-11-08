<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Payment\HttpTransport;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Sends email via the Mailgun HTTP API.
 *
 * Uses multipart/form-data so attachments can be uploaded as file parts
 * (Mailgun requires multipart encoding for the `attachment` parameter).
 *
 * @see https://documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun/messages
 */
final class MailgunAdapter implements MailInterface
{
    use MailApiUrlTrait;
    private const DEFAULT_API_URL = 'https://api.mailgun.net/v3';

    private string $apiKey;
    private string $domain;
    private string $fromAddress;
    private string $fromName;
    private string $apiBaseUrl;

    public function __construct(private readonly DbalSettingsRepository $settings, ?string $apiBaseUrl = null)
    {
        $this->apiKey      = $settings->get('mail_mailgun_api_key', '');
        $this->domain      = $settings->get('mail_mailgun_domain', '');
        $this->fromAddress = $settings->get('mail_from_address', 'noreply@example.com');
        $this->fromName    = $settings->get('mail_from_name', 'Vale');
        $this->apiBaseUrl  = rtrim($apiBaseUrl ?? self::catalogApiUrl(), '/');
    }


    public function driverName(): string
    {
        return 'mailgun';
    }

    public function getMaxBatchSize(): int
    {
        $custom = (int) $this->settings->get('mail_batch_size', '1000');
        return $custom > 0 ? min($custom, 1000) : 1000;
    }

    public function send(MailMessage $message): void
    {
        $this->sendBatch([$message]);
    }

    /**
     * @param MailMessage[] $messages
     */
    public function sendBatch(array $messages): void
    {
        if (empty($messages)) {
            return;
        }

        if ($this->apiKey === '') {
            throw new \RuntimeException('Mailgun API key is not configured.');
        }
        if ($this->domain === '') {
            throw new \RuntimeException('Mailgun domain is not configured.');
        }

        $batchEnabled = $this->settings->get('mail_batch_enabled', '1') !== '0';

        // If batch feature is disabled or batch has only 1 message or messages have differing subjects/html, send sequentially or individually
        if (!$batchEnabled || count($messages) === 1) {
            foreach ($messages as $message) {
                $this->sendSingleMessage($message);
            }
            return;
        }

        // Native batch shares one body + one List-Unsubscribe header across all
        // recipients, so it is only safe when every per-message field matches.
        // Personalized campaigns (e.g. per-subscriber unsubscribe URLs) fall
        // back to individual sends below.
        $first     = $messages[0];
        $isUniform = true;
        foreach ($messages as $msg) {
            if (
                $msg->subject               !== $first->subject
                || $msg->htmlBody           !== $first->htmlBody
                || $msg->textBody           !== $first->textBody
                || $msg->fromAddress        !== $first->fromAddress
                || $msg->fromName           !== $first->fromName
                || $msg->replyTo            !== $first->replyTo
                || $msg->listUnsubscribeUrl !== $first->listUnsubscribeUrl
            ) {
                $isUniform = false;
                break;
            }
        }

        if (!$isUniform) {
            foreach ($messages as $message) {
                $this->sendSingleMessage($message);
            }
            return;
        }

        // Native Mailgun batch via recipient-variables
        $from = ($first->fromName ?? $this->fromName)
            . ' <' . ($first->fromAddress ?? $this->fromAddress) . '>';

        $recipients    = [];
        $recipientVars = [];
        foreach ($messages as $msg) {
            $recipients[]            = $msg->to;
            $recipientVars[$msg->to] = [
                'id' => $msg->to,
            ];
        }

        $fields = [
            'from'                => $from,
            'to'                  => implode(',', $recipients),
            'subject'             => $first->subject,
            'html'                => $first->htmlBody,
            'recipient-variables' => json_encode($recipientVars, JSON_UNESCAPED_SLASHES),
        ];

        if ($first->textBody !== '') {
            $fields['text'] = $first->textBody;
        }

        if ($first->replyTo !== null) {
            $fields['h:Reply-To'] = $first->replyTo;
        }

        if ($first->listUnsubscribeUrl !== null) {
            $fields['h:List-Unsubscribe']      = '<' . $first->listUnsubscribeUrl . '>';
            $fields['h:List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        [$body, $boundary] = self::buildMultipartBody($fields, $first->attachments);

        $resp = HttpTransport::request(
            'POST',
            $this->apiBaseUrl . '/' . $this->domain . '/messages',
            [
                'Authorization: Basic ' . base64_encode('api:' . $this->apiKey),
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
            $body,
            30
        );

        if (!$resp['ok']) {
            throw new \RuntimeException('Mailgun request failed: ' . $resp['error']);
        }

        $status   = $resp['status'];
        $respBody = $resp['body'];

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($respBody, true);
            $detail  = $decoded['message'] ?? $respBody;
            throw new \RuntimeException("Mailgun API error (HTTP {$status}): {$detail}");
        }
    }

    private function sendSingleMessage(MailMessage $message): void
    {
        $from = ($message->fromName ?? $this->fromName)
            . ' <' . ($message->fromAddress ?? $this->fromAddress) . '>';

        $fields = [
            'from'    => $from,
            'to'      => $message->to,
            'subject' => $message->subject,
            'html'    => $message->htmlBody,
        ];

        if ($message->textBody !== '') {
            $fields['text'] = $message->textBody;
        }

        if ($message->replyTo !== null) {
            $fields['h:Reply-To'] = $message->replyTo;
        }

        if ($message->listUnsubscribeUrl !== null) {
            $fields['h:List-Unsubscribe']      = '<' . $message->listUnsubscribeUrl . '>';
            $fields['h:List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        [$body, $boundary] = self::buildMultipartBody($fields, $message->attachments);

        $resp = HttpTransport::request(
            'POST',
            $this->apiBaseUrl . '/' . $this->domain . '/messages',
            [
                'Authorization: Basic ' . base64_encode('api:' . $this->apiKey),
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
            $body,
            15
        );

        if (!$resp['ok']) {
            throw new \RuntimeException('Mailgun request failed: ' . $resp['error']);
        }

        $status   = $resp['status'];
        $respBody = $resp['body'];

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($respBody, true);
            $detail  = $decoded['message'] ?? $respBody;
            throw new \RuntimeException("Mailgun API error (HTTP {$status}): {$detail}");
        }
    }

    /**
     * @param array<string, string>                                          $fields
     * @param array<int, array{name: string, content: string, mime: string}> $attachments
     * @return array{0: string, 1: string} body and boundary
     */
    private static function buildMultipartBody(array $fields, array $attachments): array
    {
        $boundary = '----ValeMailgun' . bin2hex(random_bytes(16));
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n"
                . $value . "\r\n";
        }

        foreach ($attachments as $att) {
            $filename  = addslashes($att['name']);
            $mime      = $att['mime'] !== '' ? $att['mime'] : 'application/octet-stream';
            $dispoName = 'name="attachment"; filename="' . $filename . '"';
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; {$dispoName}\r\n"
                . "Content-Type: {$mime}\r\n"
                . "Content-Transfer-Encoding: binary\r\n\r\n"
                . $att['content'] . "\r\n";
        }

        return [$body . "--{$boundary}--\r\n", $boundary];
    }
}
