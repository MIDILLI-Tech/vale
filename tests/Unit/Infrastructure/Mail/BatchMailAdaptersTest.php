<?php

declare(strict_types=1);

namespace ValeApp\Tests\Unit\Infrastructure\Mail;

use PHPUnit\Framework\TestCase;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Mail\FileLoggingMailDecorator;
use ValeApp\Infrastructure\Mail\LogMailAdapter;
use ValeApp\Infrastructure\Mail\MailgunAdapter;
use ValeApp\Infrastructure\Mail\PhpMailAdapter;
use ValeApp\Infrastructure\Mail\PHPMailerAdapter;
use ValeApp\Infrastructure\Mail\ResendAdapter;
use ValeApp\Infrastructure\Mail\SendGridAdapter;
use ValeApp\Infrastructure\Mail\UnosendAdapter;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

class BatchMailAdaptersTest extends TestCase
{
    private DbalSettingsRepository $settings;

    protected function setUp(): void
    {
        $this->settings = $this->createStub(DbalSettingsRepository::class);
        $this->settings->method('get')->willReturnMap([
            ['mail_from_address', 'noreply@example.com', 'noreply@example.com'],
            ['mail_from_name', 'Vale', 'Vale'],
            ['mail_batch_enabled', '1', '1'],
            ['mail_batch_size', '1000', '1000'],
            ['mail_mailgun_api_key', '', 'test-key'],
            ['mail_mailgun_domain', '', 'mg.example.com'],
            ['mail_sendgrid_api_key', '', 'SG.test'],
            ['mail_resend_api_key', '', 're_test'],
            ['mail_unosend_api_key', '', 'un_test'],
        ]);
    }

    public function testGetMaxBatchSizeAcrossAdapters(): void
    {
        $mailgun   = new MailgunAdapter($this->settings);
        $sendgrid  = new SendGridAdapter($this->settings);
        $resend    = new ResendAdapter($this->settings);
        $phpmailer = new PHPMailerAdapter($this->settings);
        $phpmail   = new PhpMailAdapter($this->settings);
        $log       = new LogMailAdapter();

        $this->assertEquals(1000, $mailgun->getMaxBatchSize());
        $this->assertEquals(1000, $sendgrid->getMaxBatchSize());
        $this->assertEquals(100, $resend->getMaxBatchSize());
        $this->assertEquals(500, $phpmailer->getMaxBatchSize());
        $this->assertEquals(50, $phpmail->getMaxBatchSize());
        $this->assertEquals(1000, $log->getMaxBatchSize());
    }

    public function testLogMailAdapterBatchSending(): void
    {
        $tmpDir     = sys_get_temp_dir() . '/vale_test_logs_' . uniqid();
        $logAdapter = new LogMailAdapter($tmpDir);

        $messages = [
            new MailMessage('user1@example.com', 'Subject 1', '<p>Hello 1</p>'),
            new MailMessage('user2@example.com', 'Subject 2', '<p>Hello 2</p>'),
        ];

        $logAdapter->sendBatch($messages);

        $logFile = $tmpDir . '/mail.log';
        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('TO=user1@example.com', $content);
        $this->assertStringContainsString('TO=user2@example.com', $content);

        @unlink($logFile);
        @rmdir($tmpDir);
    }

    public function testFileLoggingDecoratorBatchSending(): void
    {
        $tmpDir    = sys_get_temp_dir() . '/vale_test_decorator_' . uniqid();
        $inner     = new LogMailAdapter($tmpDir);
        $decorator = new FileLoggingMailDecorator($inner, $tmpDir);

        $messages = [
            new MailMessage('user1@example.com', 'Subject 1', '<p>Hello 1</p>'),
        ];

        $decorator->sendBatch($messages);

        $logFile = $tmpDir . '/mail.log';
        $this->assertFileExists($logFile);

        @unlink($logFile);
        @rmdir($tmpDir);
    }
}
