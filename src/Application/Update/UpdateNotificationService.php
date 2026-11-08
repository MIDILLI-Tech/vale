<?php

declare(strict_types=1);

namespace ValeApp\Application\Update;

use ValeApp\Domain\Mail\Port\MailInterface;
use ValeApp\Domain\Mail\ValueObject\MailMessage;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;

/**
 * Sends admin notifications for update lifecycle events.
 *
 * Delivery uses the currently configured mail driver. If the driver is "log",
 * messages are written to storage/logs/mail.log by the existing mail adapter.
 */
final class UpdateNotificationService
{
    private const KEY_LAST_AVAILABLE_NOTIFIED_VERSION = 'update_last_notified_available_version';
    private const KEY_LAST_AUTO_APPLY_NOTIFIED_HASH   = 'update_last_notified_auto_apply_hash';

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly MailInterface $mailer,
        private readonly string $appUrl = '',
    ) {
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public function notifyUpdateAvailable(array $manifest, string $currentVersion): void
    {
        $version = trim((string) ($manifest['version'] ?? ''));
        if ($version === '') {
            return;
        }

        $alreadyNotified = (string) ($this->settings->get(self::KEY_LAST_AVAILABLE_NOTIFIED_VERSION, '') ?? '');
        if ($alreadyNotified === $version) {
            return;
        }

        $releasedAt = (string) ($manifest['released_at'] ?? 'unknown');
        $checkedAt  = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $driver     = (string) ($this->settings->get('mail_driver', 'log') ?? 'log');

        $changelogItems = $this->normalizeChangelog($manifest['changelog'] ?? []);

        $subject = sprintf('[Vale] New update available: v%s', $version);
        $html    = $this->buildAvailableHtml(
            currentVersion: $currentVersion,
            availableVersion: $version,
            releasedAt: $releasedAt,
            checkedAt: $checkedAt,
            changelogItems: $changelogItems,
            driver: $driver,
        );
        $text    = $this->buildAvailableText(
            currentVersion: $currentVersion,
            availableVersion: $version,
            releasedAt: $releasedAt,
            checkedAt: $checkedAt,
            changelogItems: $changelogItems,
            driver: $driver,
        );

        $this->sendToAdmin($subject, $html, $text);
        $this->settings->set(self::KEY_LAST_AVAILABLE_NOTIFIED_VERSION, $version, 'cron');
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public function notifyAutoApplyResult(array $manifest, bool $success, string $details = '', ?UpdateResult $result = null): void
    {
        $version = trim((string) ($manifest['version'] ?? ''));
        if ($version === '') {
            $version = trim((string) ($this->settings->get('update_last_auto_applied_version', '') ?? ''));
        }
        if ($version === '') {
            $version = 'unknown';
        }

        $status     = $success ? 'success' : 'failed';
        $releasedAt = (string) ($manifest['released_at'] ?? 'unknown');
        $ranAt      = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $driver     = (string) ($this->settings->get('mail_driver', 'log') ?? 'log');

        $fingerprint = sha1(
            implode('|', [
                $version,
                $status,
                $releasedAt,
                (string) ($manifest['sha256'] ?? ''),
                (string) ($manifest['download_url'] ?? ''),
            ])
        );

        $alreadyNotified = (string) ($this->settings->get(self::KEY_LAST_AUTO_APPLY_NOTIFIED_HASH, '') ?? '');
        if ($alreadyNotified === $fingerprint) {
            return;
        }

        $changelogItems = $this->normalizeChangelog($manifest['changelog'] ?? []);

        $subject = sprintf(
            '[Vale] Auto-update %s: v%s',
            $success ? 'completed' : 'failed',
            $version
        );

        $html = $this->buildAutoApplyHtml(
            success: $success,
            version: $version,
            releasedAt: $releasedAt,
            ranAt: $ranAt,
            details: $details,
            changelogItems: $changelogItems,
            driver: $driver,
            result: $result,
        );

        $text = $this->buildAutoApplyText(
            success: $success,
            version: $version,
            releasedAt: $releasedAt,
            ranAt: $ranAt,
            details: $details,
            changelogItems: $changelogItems,
            driver: $driver,
            result: $result,
        );

        $this->sendToAdmin($subject, $html, $text);
        $this->settings->set(self::KEY_LAST_AUTO_APPLY_NOTIFIED_HASH, $fingerprint, 'cron');
    }

    /**
     * @param list<string> $changelogItems
     */
    private function buildAvailableHtml(
        string $currentVersion,
        string $availableVersion,
        string $releasedAt,
        string $checkedAt,
        array $changelogItems,
        string $driver,
    ): string {
        $list = '';
        foreach ($changelogItems as $item) {
            $list .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        $updateUrl = $this->appUrl !== '' ? rtrim($this->appUrl, '/') . '/admin/update' : '/admin/update';

        return '<h2>Vale update available</h2>'
            . '<p><strong>Current:</strong> v' . htmlspecialchars($currentVersion, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Available:</strong> v' . htmlspecialchars($availableVersion, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Released at:</strong> ' . htmlspecialchars($releasedAt, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Checked at:</strong> ' . htmlspecialchars($checkedAt, ENT_QUOTES, 'UTF-8') . '</p>'
            . ($list !== '' ? '<h3>Changelog</h3><ul>' . $list . '</ul>' : '')
            . '<p><strong>Mail driver:</strong> ' . htmlspecialchars($driver, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="' . htmlspecialchars($updateUrl, ENT_QUOTES, 'UTF-8') . '">Open update panel</a></p>';
    }

    /**
     * @param list<string> $changelogItems
     */
    private function buildAvailableText(
        string $currentVersion,
        string $availableVersion,
        string $releasedAt,
        string $checkedAt,
        array $changelogItems,
        string $driver,
    ): string {
        $lines = [
            'Vale update available',
            'Current version: v' . $currentVersion,
            'Available version: v' . $availableVersion,
            'Released at: ' . $releasedAt,
            'Checked at: ' . $checkedAt,
            'Mail driver: ' . $driver,
        ];

        if ($this->appUrl !== '') {
            $lines[] = 'Update panel: ' . rtrim($this->appUrl, '/') . '/admin/update';
        }

        if ($changelogItems !== []) {
            $lines[] = '';
            $lines[] = 'Changelog:';
            foreach ($changelogItems as $item) {
                $lines[] = '- ' . $item;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $changelogItems
     */
    private function buildAutoApplyHtml(
        bool $success,
        string $version,
        string $releasedAt,
        string $ranAt,
        string $details,
        array $changelogItems,
        string $driver,
        ?UpdateResult $result,
    ): string {
        $statusText    = $success ? 'SUCCESS' : 'FAILED';
        $statusColor   = $success ? '#15803d' : '#b91c1c';
        $detailEscaped = htmlspecialchars($details, ENT_QUOTES, 'UTF-8');

        $extra = '';
        if ($result !== null) {
            $extra = '<p><strong>Copied files:</strong> ' . $result->copiedFiles . '<br>'
                . '<strong>Cleared cache files:</strong> ' . $result->clearedCacheFiles . '<br>'
                . '<strong>Backup:</strong> ' . htmlspecialchars($result->backupDir !== '' ? basename($result->backupDir) : 'none', ENT_QUOTES, 'UTF-8') . '<br>'
                . '<strong>Rolled back:</strong> ' . ($result->rolledBack ? 'yes' : 'no') . '</p>';
        }

        $list = '';
        foreach ($changelogItems as $item) {
            $list .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        return '<h2>Vale auto-update result</h2>'
            . '<p><strong>Status:</strong> <span style="color:' . $statusColor . '">' . $statusText . '</span><br>'
            . '<strong>Version:</strong> v' . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Released at:</strong> ' . htmlspecialchars($releasedAt, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Executed at:</strong> ' . htmlspecialchars($ranAt, ENT_QUOTES, 'UTF-8') . '</p>'
            . ($detailEscaped !== '' ? '<p><strong>Details:</strong> ' . $detailEscaped . '</p>' : '')
            . $extra
            . ($list !== '' ? '<h3>Changelog</h3><ul>' . $list . '</ul>' : '')
            . '<p><strong>Mail driver:</strong> ' . htmlspecialchars($driver, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    /**
     * @param list<string> $changelogItems
     */
    private function buildAutoApplyText(
        bool $success,
        string $version,
        string $releasedAt,
        string $ranAt,
        string $details,
        array $changelogItems,
        string $driver,
        ?UpdateResult $result,
    ): string {
        $lines = [
            'Vale auto-update result',
            'Status: ' . ($success ? 'SUCCESS' : 'FAILED'),
            'Version: v' . $version,
            'Released at: ' . $releasedAt,
            'Executed at: ' . $ranAt,
            'Mail driver: ' . $driver,
        ];

        if ($details !== '') {
            $lines[] = 'Details: ' . $details;
        }

        if ($result !== null) {
            $lines[] = 'Copied files: ' . (string) $result->copiedFiles;
            $lines[] = 'Cleared cache files: ' . (string) $result->clearedCacheFiles;
            $lines[] = 'Backup: ' . ($result->backupDir !== '' ? basename($result->backupDir) : 'none');
            $lines[] = 'Rolled back: ' . ($result->rolledBack ? 'yes' : 'no');
        }

        if ($changelogItems !== []) {
            $lines[] = '';
            $lines[] = 'Changelog:';
            foreach ($changelogItems as $item) {
                $lines[] = '- ' . $item;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param mixed $changelog
     * @return list<string>
     */
    private function normalizeChangelog(mixed $changelog): array
    {
        if (!is_array($changelog)) {
            return [];
        }

        $items = [];
        foreach ($changelog as $line) {
            if (!is_string($line)) {
                continue;
            }
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $items[] = $trimmed;
            }
        }

        return $items;
    }

    private function sendToAdmin(string $subject, string $html, string $text): void
    {
        $adminEmail = trim((string) ($this->settings->get('admin_email', '') ?? ''));
        if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $this->mailer->send(new MailMessage(
            to: $adminEmail,
            subject: $subject,
            htmlBody: $html,
            textBody: $text,
        ));
    }
}
