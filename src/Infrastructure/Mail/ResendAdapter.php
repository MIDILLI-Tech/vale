<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Payment\HttpTransport;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Sends email via the Resend HTTP API.
 *
 * @see https://resend.com/docs/api-reference/emails/send-batch
 */
final class ResendAdapter implements MailInterface
{
    use MailApiUrlTrait;
    private const DEFAULT_API_URL = 'https://api.resend.com';

    private string $apiKey;
    private string $fromAddress;
    private string $fromName;
    private string $apiBaseUrl;

    public function __construct(private readonly DbalSettingsRepository $settings, ?string $apiBaseUrl = null)
    {
        $this->apiKey      = $settings->get('mail_resend_api_key', '');
        $this->fromAddress = $settings->get('mail_from_address', 'noreply@example.com');
        $this->fromName    = $settings->get('mail_from_name', 'Vale');
        $this->apiBaseUrl  = rtrim($apiBaseUrl ?? self::catalogApiUrl(), '/');
    }


    public function driverName(): string
    {
        return 'resend';
    }

    public function getMaxBatchSize(): int
    {
        $custom = (int) $this->settings->get('mail_batch_size', '100');
        return $custom > 0 ? min($custom, 100) : 100;
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
            throw new \RuntimeException('Resend API key is not configured.');
        }

        $batchEnabled = $this->settings->get('mail_batch_enabled', '1') !== '0';

        if (!$batchEnabled || count($messages) === 1) {
            foreach ($messages as $message) {
                $this->sendSingleMessage($message);
            }
            return;
        }

        $batchPayload = [];
        foreach ($messages as $message) {
            $from = ($message->fromName ?? $this->fromName)
                . ' <' . ($message->fromAddress ?? $this->fromAddress) . '>';

            $item = [
                'from'    => $from,
                'to'      => [$message->to],
                'subject' => $message->subject,
                'html'    => $message->htmlBody,
            ];

            if ($message->textBody !== '') {
                $item['text'] = $message->textBody;
            }

            if ($message->replyTo !== null) {
                $item['reply_to'] = $message->replyTo;
            }

            if ($message->listUnsubscribeUrl !== null) {
                $item['headers'] = [
                    'List-Unsubscribe'      => '<' . $message->listUnsubscribeUrl . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ];
            }

            if ($message->attachments !== []) {
                $item['attachments'] = array_map(
                    static fn (array $att): array => [
                        'filename' => $att['name'],
                        'content'  => base64_encode($att['content']),
                    ],
                    $message->attachments
                );
            }

            $batchPayload[] = $item;
        }

        $resp = HttpTransport::request(
            'POST',
            $this->apiBaseUrl . '/emails/batch',
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            json_encode($batchPayload),
            30
        );

        if (!$resp['ok']) {
            throw new \RuntimeException('Resend batch request failed: ' . $resp['error']);
        }

        $status = $resp['status'];
        $body   = $resp['body'];

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($body, true);
            $detail  = $decoded['message'] ?? $body;
            throw new \RuntimeException("Resend API error (HTTP {$status}): {$detail}");
        }
    }

    private function sendSingleMessage(MailMessage $message): void
    {
        $from = ($message->fromName ?? $this->fromName)
            . ' <' . ($message->fromAddress ?? $this->fromAddress) . '>';

        $payload = [
            'from'    => $from,
            'to'      => [$message->to],
            'subject' => $message->subject,
            'html'    => $message->htmlBody,
        ];

        if ($message->textBody !== '') {
            $payload['text'] = $message->textBody;
        }

        if ($message->replyTo !== null) {
            $payload['reply_to'] = $message->replyTo;
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
                    'filename' => $att['name'],
                    'content'  => base64_encode($att['content']),
                ],
                $message->attachments
            );
        }

        $resp = HttpTransport::request(
            'POST',
            $this->apiBaseUrl . '/emails',
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            json_encode($payload),
            15
        );

        if (!$resp['ok']) {
            throw new \RuntimeException('Resend request failed: ' . $resp['error']);
        }

        $status = $resp['status'];
        $body   = $resp['body'];

        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($body, true);
            $detail  = $decoded['message'] ?? $body;
            throw new \RuntimeException("Resend API error (HTTP {$status}): {$detail}");
        }
    }
}
