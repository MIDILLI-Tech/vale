<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Payment\HttpTransport;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Sends email via the Unosend HTTP API.
 *
 * @see https://docs.unosend.co/introduction
 */
final class UnosendAdapter implements MailInterface
{
    use MailApiUrlTrait;
    private const DEFAULT_API_URL = 'https://api.unosend.co';

    private string $apiKey;
    private string $fromAddress;
    private string $fromName;
    private string $apiBaseUrl;

    public function __construct(private readonly DbalSettingsRepository $settings, ?string $apiBaseUrl = null)
    {
        $this->apiKey      = $settings->get('mail_unosend_api_key', '');
        $this->fromAddress = $settings->get('mail_from_address', 'noreply@example.com');
        $this->fromName    = $settings->get('mail_from_name', 'Vale');
        $this->apiBaseUrl  = rtrim($apiBaseUrl ?? self::catalogApiUrl(), '/');
    }


    public function driverName(): string
    {
        return 'unosend';
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
            throw new \RuntimeException('Unosend API key is not configured.');
        }

        foreach ($messages as $message) {
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
                        'filename'     => $att['name'],
                        'content'      => base64_encode($att['content']),
                        'content_type' => $att['mime'],
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
                throw new \RuntimeException('Unosend request failed: ' . $resp['error']);
            }

            $status = $resp['status'];
            $body   = $resp['body'];

            if ($status < 200 || $status >= 300) {
                $decoded = json_decode($body, true);
                $msg     = $decoded['message'] ?? $decoded['error'] ?? $body;
                throw new \RuntimeException('Unosend API error (' . $status . '): ' . $msg);
            }
        }
    }
}
