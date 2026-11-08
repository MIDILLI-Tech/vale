<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Sends email via PHP's native mail() function.
 * Requires a working sendmail / MTA on the server.
 */
final class PhpMailAdapter implements MailInterface
{
    private string $fromAddress;
    private string $fromName;

    public function __construct(private readonly DbalSettingsRepository $settings)
    {
        $this->fromAddress = $settings->get('mail_from_address', 'noreply@example.com');
        $this->fromName    = $settings->get('mail_from_name', 'Vale');
    }

    public function driverName(): string
    {
        return 'phpmail';
    }

    public function getMaxBatchSize(): int
    {
        return 50;
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
        foreach ($messages as $message) {
            $from    = $message->fromAddress ?? $this->fromAddress;
            $name    = $message->fromName    ?? $this->fromName;

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= 'From: ' . $this->encodeHeader($name) . ' <' . $from . ">\r\n";
            $headers .= 'X-Mailer: Vale' . "\r\n";

            if ($message->replyTo !== null) {
                $headers .= 'Reply-To: ' . $message->replyTo . "\r\n";
            }

            if ($message->listUnsubscribeUrl !== null) {
                $headers .= 'List-Unsubscribe: <' . $message->listUnsubscribeUrl . ">\r\n";
                $headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
            }

            if ($message->attachments === []) {
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $body = $message->htmlBody;
            } else {
                [$body, $boundary] = self::buildMultipartBody($message);
                $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
            }

            $subject = '=?UTF-8?B?' . base64_encode($message->subject) . '?=';

            $sent = mail($message->to, $subject, $body, $headers);

            if (!$sent) {
                throw new \RuntimeException('php mail() returned false — check server sendmail configuration.');
            }
        }
    }

    /**
     * Builds a multipart/mixed body carrying the HTML (and optional plain-text)
     * content plus every attachment as a base64 part.
     *
     * @return array{0: string, 1: string} body and boundary
     */
    private static function buildMultipartBody(MailMessage $message): array
    {
        $boundary = '----ValePhpMail' . bin2hex(random_bytes(16));
        $body     = '';

        $body .= "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $message->htmlBody . "\r\n";

        if ($message->textBody !== '') {
            $body .= "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $message->textBody . "\r\n";
        }

        foreach ($message->attachments as $att) {
            $filename = str_replace(['"', "\r", "\n"], '', $att['name']);
            $mime     = $att['mime'] !== '' ? $att['mime'] : 'application/octet-stream';
            $body .= "--{$boundary}\r\n"
                . "Content-Type: {$mime}\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n\r\n"
                . chunk_split(base64_encode($att['content'])) . "\r\n";
        }

        return [$body . "--{$boundary}--\r\n", $boundary];
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }
}
