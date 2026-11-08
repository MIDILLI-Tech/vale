<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;

/**
 * Log driver — writes full email content to storage/logs/mail.log.
 * Useful for local development without a real mail server.
 */
final class LogMailAdapter implements MailInterface
{
    public function __construct(private readonly string $logDir = '')
    {
    }

    public function driverName(): string
    {
        return 'log';
    }

    #[\Override]
    public function getMaxBatchSize(): int
    {
        return 1000;
    }

    #[\Override]
    public function send(MailMessage $message): void
    {
        $this->sendBatch([$message]);
    }

    /**
     * @param MailMessage[] $messages
     */
    #[\Override]
    public function sendBatch(array $messages): void
    {
        foreach ($messages as $message) {
            $separator = str_repeat('=', 80);
            $entry     = $separator . "\n";
            $entry .= '[' . date('Y-m-d H:i:s') . "] TO={$message->to}\n";
            $entry .= "SUBJECT={$message->subject}\n";
            $entry .= "FROM=" . ($message->fromAddress ?? '(default)') . "\n";
            if ($message->listUnsubscribeUrl !== null) {
                $entry .= "LIST-UNSUBSCRIBE=<{$message->listUnsubscribeUrl}>\n";
            }
            $entry .= "--- HTML BODY ---\n";
            $entry .= $message->htmlBody . "\n";
            $entry .= $separator . "\n\n";

            $logFile = $this->logDir !== '' ? rtrim($this->logDir, '/\\') . '/mail.log' : '';

            if ($logFile !== '') {
                if (!is_dir(dirname($logFile))) {
                    @mkdir(dirname($logFile), 0755, true);
                }
                file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
            } else {
                // Fallback: write to PHP error log (body truncated to 1 KB)
                error_log('[Vale Mail] TO=' . $message->to . ' SUBJECT=' . $message->subject);
                error_log('[Vale Mail] BODY: ' . substr(strip_tags($message->htmlBody), 0, 1024));
            }
        }
    }
}
