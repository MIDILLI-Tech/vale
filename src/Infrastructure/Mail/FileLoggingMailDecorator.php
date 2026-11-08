<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;

/**
 * Decorator that sends via the real driver and also appends to storage/logs/mail.log.
 */
final class FileLoggingMailDecorator implements MailInterface
{
    public function __construct(
        private readonly MailInterface $inner,
        private readonly string $logDir,
    ) {
    }

    public function driverName(): string
    {
        return $this->inner->driverName();
    }

    public function getMaxBatchSize(): int
    {
        return $this->inner->getMaxBatchSize();
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
        $status = 'sent';
        $error  = null;

        try {
            $this->inner->sendBatch($messages);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error  = $e->getMessage();
        }

        $logFile = rtrim($this->logDir, '/\\') . '/mail.log';
        if (!is_dir(dirname($logFile))) {
            @mkdir(dirname($logFile), 0755, true);
        }

        foreach ($messages as $message) {
            $separator = str_repeat('=', 80);
            $entry     = $separator . "\n";
            $entry .= '[' . date('Y-m-d H:i:s') . "] TO={$message->to}\n";
            $entry .= "SUBJECT={$message->subject}\n";
            $entry .= "FROM=" . ($message->fromAddress ?? '(default)') . "\n";
            $entry .= "DRIVER={$this->driverName()}\n";
            $entry .= "STATUS={$status}\n";
            if ($error !== null) {
                $entry .= "ERROR={$error}\n";
            }
            $entry .= "--- HTML BODY ---\n";
            $entry .= $message->htmlBody . "\n";
            $entry .= $separator . "\n\n";

            @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        }

        if ($error !== null) {
            throw $e;
        }
    }
}
