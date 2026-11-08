<?php

declare(strict_types=1);

namespace ValeApp\Domain\Mail\ValueObject;

final readonly class MailMessage
{
    /**
     * @param array<int, array{name: string, content: string, mime: string}> $attachments
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $htmlBody,
        public string $textBody = '',
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $replyTo = null,
        public array $attachments = [],
        public ?string $listUnsubscribeUrl = null,
    ) {
    }
}
