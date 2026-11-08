<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Payment\HttpTransport;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Sends email via the SendGrid (Twilio) HTTP API.
 *
 * @see https://docs.sendgrid.com/api-reference/mail-send/mail-send
 */
final class SendGridAdapter implements MailInterface
{
    use MailApiUrlTrait;
    private const DEFAULT_API_URL = 'https://api.sendgrid.com/v3';

    private string $apiKey;
    private string $fromAddress;
    private string $fromName;
    private string $apiBaseUrl;

    public function __construct(private readonly DbalSettingsRepository $settings, ?string $apiBaseUrl = null)
    {
        $this->apiKey      = $settings->get('mail_sendgrid_api_key', '');
        $this->fromAddress = $settings->get('mail_from_address', 'noreply@example.com');
        $this->fromName    = $settings->get('mail_from_name', 'Vale');
        $this->apiBaseUrl  = rtrim($apiBaseUrl ?? self::catalogApiUrl(), '/');
    }


    public function driverName(): string
    {
        return 'sendgrid';
    }

    public function getMaxBatchSize(): int
    {
        $custom = (int) $this->settings->get('mail_batch_size', '1000');
        return $custom > 0 ? min($custom, 1000) : 1000;
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    private function buildContent(MailMessage $message): array
    {
        $content = [];

        if ($message->textBody !== '') {
            $content[] = [
                'type'  => 'text/plain',
                'value' => $message->textBody,
            ];
        }

        $content[] = [
            'type'  => 'text/html',
            'value' => $message->htmlBody,
        ];

        return $content;
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
            throw new \RuntimeException('SendGrid API key is not configured.');
        }

        $batchEnabled = $this->settings->get('mail_batch_enabled', '1') !== '0';

        if (!$batchEnabled || count($messages) === 1) {
            foreach ($messages as $message) {
                $this->sendSingleMessage($message);
            }
            return;
        }

        // Check if all messages have identical body content (to avoid sharing recipient 0's body with everyone)
        $first     = $messages[0];
        $isUniform = true;
        foreach ($messages as $msg) {
            if ($msg->htmlBody !== $first->htmlBody || $msg->textBody !== $first->textBody) {
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

        // SendGrid personalizations batch array when body is uniform across all recipients
        $personalizations = [];
        foreach ($messages as $msg) {
            $personalization = [
                'to' => [
                    ['email' => $msg->to],
                ],
                'subject' => $msg->subject,
            ];

            if ($msg->listUnsubscribeUrl !== null) {
                $personalization['headers'] = [
                    'List-Unsubscribe'      => '<' . $msg->listUnsubscribeUrl . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ];
            }

            $personalizations[] = $personalization;
        }

        $payload = [
            'personalizations' => $personalizations,
            'from'             => [
                'email' => $first->fromAddress ?? $this->fromAddress,
                'name'  => $first->fromName    ?? $this->fromName,
            ],
            'content' => $this->buildContent($first),
        ];

        if ($first->replyTo !== null) {
            $payload['reply_to'] = ['email' => $first->replyTo];
        }

        if ($first->attachments !== []) {
            $payload['attachments'] = array_map(
                static fn (array $att): array => [
                    'content'     => base64_encode($att['content']),
                    'filename'    => $att['name'],
                    'type'        => $att['mime'],
                    'disposition' => 'attachment',
                ],
                $first->attachments
            );
        }

        $resp = HttpTransport::request(
            'POST',
            $this->apiBaseUrl . '/mail/send',
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            json_encode($payload),
            30
        );

        if (!$resp['ok']) {
            throw new \RuntimeException('SendGrid request failed: ' . $resp['error']);
        }

        $status = $resp['status'];
        $body   = $resp['body'];

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($body, true);
            $detail  = $decoded['errors'][0]['message'] ?? $decoded['message'] ?? $body;
            throw new \RuntimeException("SendGrid API error (HTTP {$status}): {$detail}");
        }
    }

    private function sendSingleMessage(MailMessage $message): void
    {
        $payload = [
            'personalizations' => [
                [
                    'to' => [
                        ['email' => $message->to],
                    ],
                ],
            ],
            'from' => [
                'email' => $message->fromAddress ?? $this->fromAddress,
                'name'  => $message->fromName    ?? $this->fromName,
            ],
            'subject' => $message->subject,
            'content' => $this->buildContent($message),
        ];

        if ($message->replyTo !== null) {
            $payload['reply_to'] = ['email' => $message->replyTo];
        }

        if ($message->listUnsubscribeUrl !== null) {
            $payload['headers'] = [
                'List-Unsubscribe'      => '<' . $message->listUnsubscribeUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ];
        }

        if ($message->attachments !== []) {
            $payload['attachments'] = array_map(
                static fn (array $att): array => [
                    'content'     => base64_encode($att['content']),
                    'filename'    => $att['name'],
                    'type'        => $att['mime'],
                    'disposition' => 'attachment',
                ],
                $message->attachments
            );
        }

        $resp = HttpTransport::request(
            'POST',
            $this->apiBaseUrl . '/mail/send',
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            json_encode($payload),
            15
        );

        if (!$resp['ok']) {
            throw new \RuntimeException('SendGrid request failed: ' . $resp['error']);
        }

        $status = $resp['status'];
        $body   = $resp['body'];

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($body, true);
            $detail  = $decoded['errors'][0]['message'] ?? $decoded['message'] ?? $body;
            throw new \RuntimeException("SendGrid API error (HTTP {$status}): {$detail}");
        }
    }
}
