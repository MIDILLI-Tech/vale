<?php

declare(strict_types=1);

namespace ValeApp\Domain\Mail\Port;

use ValeApp\Domain\Mail\ValueObject\MailMessage;

interface MailInterface
{
    /**
     * Send an email.
     *
     * @throws \RuntimeException on delivery failure
     */
    public function send(MailMessage $message): void;

    /**
     * Send a batch of emails.
     *
     * @param MailMessage[] $messages
     * @throws \RuntimeException on delivery failure
     */
    public function sendBatch(array $messages): void;

    /**
     * Returns maximum default batch size supported by adapter.
     */
    public function getMaxBatchSize(): int;

    /**
     * Identifier used in mail logs (smtp | resend | log).
     */
    public function driverName(): string;
}
