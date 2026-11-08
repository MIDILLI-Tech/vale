<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Mail;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Mail\PhpMailAdapter;

final class PhpMailMultipartTest extends TestCase
{
    /**
     * @return array{0: string, 1: string} body and boundary
     */
    private function buildBody(MailMessage $message): array
    {
        $method = new \ReflectionMethod(PhpMailAdapter::class, 'buildMultipartBody');

        return $method->invoke(null, $message);
    }

    public function testHtmlAndPlainPartsComeFirst(): void
    {
        $message = new MailMessage(
            to: 'a@example.com',
            subject: 's',
            htmlBody: '<p>hi</p>',
            textBody: 'hi',
            attachments: [
                ['name' => 'INV-1.pdf', 'content' => '%PDF-1.4', 'mime' => 'application/pdf'],
            ],
        );

        [$body, $boundary] = $this->buildBody($message);

        $htmlPos   = strpos($body, 'Content-Type: text/html');
        $plainPos  = strpos($body, 'Content-Type: text/plain');
        $attachPos = strpos($body, 'Content-Disposition: attachment; filename="INV-1.pdf"');

        $this->assertNotFalse($htmlPos);
        $this->assertNotFalse($plainPos);
        $this->assertNotFalse($attachPos);
        $this->assertLessThan($plainPos, $htmlPos);
        $this->assertLessThan($attachPos, $plainPos);
        $this->assertStringEndsWith("--{$boundary}--\r\n", $body);
    }

    public function testAttachmentContentSurvivesBase64RoundTrip(): void
    {
        $binary  = random_bytes(256);
        $message = new MailMessage(
            to: 'a@example.com',
            subject: 's',
            htmlBody: '<p>hi</p>',
            attachments: [
                ['name' => 'INV-1.pdf', 'content' => $binary, 'mime' => 'application/pdf'],
            ],
        );

        [$body] = $this->buildBody($message);

        $encoded = str_replace(["\r", "\n"], '', chunk_split(base64_encode($binary)));
        $flat    = str_replace(["\r", "\n"], '', $body);

        $this->assertStringContainsString($encoded, $flat);
    }

    public function testEmptyMimeFallsBackToOctetStream(): void
    {
        $message = new MailMessage(
            to: 'a@example.com',
            subject: 's',
            htmlBody: '<p>hi</p>',
            attachments: [
                ['name' => 'file.bin', 'content' => 'data', 'mime' => ''],
            ],
        );

        [$body] = $this->buildBody($message);

        $this->assertStringContainsString('Content-Type: application/octet-stream', $body);
    }

    public function testFilenameCannotBreakOutOfHeader(): void
    {
        $message = new MailMessage(
            to: 'a@example.com',
            subject: 's',
            htmlBody: '<p>hi</p>',
            attachments: [
                ['name' => "evil\"\r\nBcc: victim@example.com", 'content' => 'data', 'mime' => 'application/pdf'],
            ],
        );

        [$body] = $this->buildBody($message);

        $this->assertStringNotContainsString("Bcc: victim@example.com\"\r\nBcc:", $body);
        $this->assertStringContainsString('filename="evilBcc: victim@example.com"', $body);
    }
}
