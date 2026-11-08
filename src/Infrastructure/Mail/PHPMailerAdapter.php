<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Infrastructure\Persistence\DbalSettingsRepository;

/**
 * Sends email via SMTP using PHPMailer.
 * Settings are read from settings (group: mail_smtp_*).
 */
final class PHPMailerAdapter implements MailInterface
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
        return 'smtp';
    }

    public function getMaxBatchSize(): int
    {
        $custom = (int) $this->settings->get('mail_batch_size', '500');
        return $custom > 0 ? min($custom, 500) : 500;
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

        $host       = $this->settings->get('mail_smtp_host', '');
        $port       = (int) $this->settings->get('mail_smtp_port', '587');
        $user       = $this->settings->get('mail_smtp_user', '');
        $pass       = $this->settings->get('mail_smtp_pass', '');
        $encryption = $this->settings->get('mail_smtp_encryption', 'tls');

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host       = $host;
        $mailer->SMTPAuth   = ($user !== '');
        $mailer->Username   = $user;
        $mailer->Password   = $pass;
        $mailer->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port       = $port;
        $mailer->CharSet    = PHPMailer::CHARSET_UTF8;

        if (count($messages) > 1) {
            $mailer->SMTPKeepAlive = true;
        }

        try {
            foreach ($messages as $message) {
                $mailer->clearAddresses();
                $mailer->clearCustomHeaders();
                $mailer->clearAttachments();
                $mailer->clearReplyTos();

                $from = $message->fromAddress ?? $this->fromAddress;
                $name = $message->fromName    ?? $this->fromName;
                $mailer->setFrom($from, $name);

                if ($message->replyTo !== null) {
                    $mailer->addReplyTo($message->replyTo);
                }

                if ($message->listUnsubscribeUrl !== null) {
                    $mailer->addCustomHeader('List-Unsubscribe', '<' . $message->listUnsubscribeUrl . '>');
                    $mailer->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
                }

                $mailer->addAddress($message->to);
                $mailer->isHTML(true);
                $mailer->Subject = $message->subject;
                $mailer->Body    = $message->htmlBody;

                if ($message->textBody !== '') {
                    $mailer->AltBody = $message->textBody;
                }

                foreach ($message->attachments as $att) {
                    $mailer->addStringAttachment($att['content'], $att['name'], 'base64', $att['mime']);
                }

                $mailer->send();
            }
        } finally {
            if ($mailer->SMTPKeepAlive) {
                $mailer->smtpClose();
            }
        }
    }
}
