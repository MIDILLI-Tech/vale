<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use ValeApp\Domain\Shared\Repository\SettingsRepositoryInterface;
use ValeApp\Infrastructure\Security\Encrypter;

class DbalSettingsRepository implements SettingsRepositoryInterface
{
    private static ?self $instance = null;

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    /**
     * Request-scoped in-memory cache for autoloaded settings.
     * Populated on first get() call, reducing N individual SQL queries to 1.
     */
    private array $cache = [];
    private bool $loaded = false;

    /**
         * Settings keys that should be encrypted in database.
         *
         * These are API secrets, webhook secrets, API keys, SMTP passwords, and other credentials
         * that must not be stored in plaintext. They are encrypted using XChaCha20-Poly1305.
         */
    private const ENCRYPTED_KEYS = [
        // Payment gateway secrets
        'stripe_secret_key',
        'stripe_webhook_secret',
        'paypal_client_secret',
        'mollie_api_key',
        'twocheckout_api_secret',
        'twocheckout_secret_key',
        'checkoutcom_secret_key',
        'checkoutcom_webhook_secret',
        // Mail service credentials
        'mail_mailgun_api_key',
        'mail_sendgrid_api_key',
        'mail_resend_api_key',
        'mail_unosend_api_key',
        'mail_smtp_pass',
    ];

    /**
     * Settings keys that are encrypted at runtime based on key pattern.
     * Allows new providers added in reference-data.php to be encrypted automatically.
     */
    private const ENCRYPTED_KEY_PATTERNS = [
        '~^sso_[a-z0-9_]+_client_secret$~',
    ];

    private function isEncryptedKey(string $key): bool
    {
        if (in_array($key, self::ENCRYPTED_KEYS, true)) {
            return true;
        }
        foreach (self::ENCRYPTED_KEY_PATTERNS as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }
        return false;
    }

    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
    ) {
        self::$instance = $this;
    }

    public function getLocalized(string $key, string $locale, ?string $default = null): ?string
    {
        $locale        = strtolower(trim($locale));
        $defaultLocale = strtolower(trim((string) ($this->get('default_locale', 'en') ?? 'en')));

        if ($locale !== $defaultLocale) {
            $localized = $this->get($key . '_' . $locale, null);
            if ($localized !== null && $localized !== '') {
                return $localized;
            }

            if (
                $this->get('multi_language_content', '0')             === '1'
                && $this->get('i18n_display_originals_fallback', '1') === '1'
            ) {
                $fallback = $this->get($key, $default);
                if ($fallback !== null && $fallback !== '') {
                    return $fallback;
                }
            }

            return $default;
        }

        return $this->get($key, $default);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (!$this->loaded) {
            $this->loadCache();
        }

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        // Key not in autoloaded set — fetch individually
        try {
            $val = $this->db->fetchOne(
                'SELECT ' . $this->db->quoteIdentifier('value') . ' FROM ' . VL_DB_PREFIX . 'settings WHERE ' . $this->db->quoteIdentifier('key') . ' = ? ORDER BY id DESC',
                [$key],
                [ParameterType::STRING],
            );
        } catch (\Exception) {
            $val = false;
        }

        if ($val === false) {
            return $default;
        }

        if ($this->isEncryptedKey($key)) {
            $val = $this->encrypter->decrypt((string) $val);
        }

        $this->cache[$key] = (string) $val;
        return $this->cache[$key];
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return $value === '1';
    }

    /**
     * Load all autoloaded settings into request-scoped in-memory cache.
     * Reduces N individual SQL queries to 1 per request.
     */
    private function loadCache(): void
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT ' . $this->db->quoteIdentifier('key') . ', ' . $this->db->quoteIdentifier('value') . ' FROM ' . VL_DB_PREFIX . 'settings WHERE is_autoload = TRUE'
            );
        } catch (\Exception) {
            // Table might not exist yet during migration or installation
            $rows = [];
        }

        foreach ($rows as $row) {
            $val = $row['value'];
            if ($this->isEncryptedKey($row['key'])) {
                $val = $this->encrypter->decrypt((string) $val);
            }
            $this->cache[$row['key']] = (string) $val;
        }

        $this->loaded = true;
    }

    /**
     * @param string $group
     * @return array<string, string>
     */
    public function getGroup(string $group): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . $this->db->quoteIdentifier('key') . ', ' . $this->db->quoteIdentifier('value') . ' FROM ' . VL_DB_PREFIX . 'settings WHERE group_name = ? ORDER BY ' . $this->db->quoteIdentifier('key'),
            [$group],
            [ParameterType::STRING],
        );

        $result = [];
        foreach ($rows as $row) {
            $val = (string) $row['value'];
            if ($this->isEncryptedKey($row['key'])) {
                $val = $this->encrypter->decrypt($val);
            }
            $result[$row['key']] = $val;
        }
        return $result;
    }

    /**
     * @return array<string, string> All autoloaded settings
     */
    public function getAutoloaded(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT ' . $this->db->quoteIdentifier('key') . ', ' . $this->db->quoteIdentifier('value') . ' FROM ' . VL_DB_PREFIX . 'settings WHERE is_autoload = TRUE',
        );

        $result = [];
        foreach ($rows as $row) {
            $val = (string) $row['value'];
            if ($this->isEncryptedKey($row['key'])) {
                $val = $this->encrypter->decrypt($val);
            }
            $result[$row['key']] = $val;
        }
        return $result;
    }

    public function set(string $key, string $value, string $group = 'general', string $type = 'string'): void
    {
        // Encrypt sensitive keys before storage
        $storageValue = $value;
        if ($this->isEncryptedKey($key) && !empty($value)) {
            $storageValue = $this->encrypter->encrypt($value);
        }

        $existing = $this->db->fetchAssociative(
            'SELECT id FROM ' . VL_DB_PREFIX . 'settings WHERE ' . $this->db->quoteIdentifier('key') . ' = ? ORDER BY id DESC',
            [$key],
            [ParameterType::STRING],
        );

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($existing !== false) {
            $id = (int) $existing['id'];

            // Keys are globally unique, but the UNIQUE(group_name, key) index still
            // allows the same key to exist in two groups (e.g. a re-run seeder next
            // to a row saved from another settings tab). Updating by key would move
            // every duplicate into the target group and trip the index, so keep the
            // newest row and drop the rest before updating it.
            $this->db->executeStatement(
                'DELETE FROM ' . VL_DB_PREFIX . 'settings WHERE ' . $this->db->quoteIdentifier('key') . ' = ? AND id != ?',
                [$key, $id],
                [ParameterType::STRING, ParameterType::INTEGER],
            );

            $this->db->executeStatement(
                'UPDATE ' . VL_DB_PREFIX . 'settings SET ' . $this->db->quoteIdentifier('value') . ' = ?, group_name = ?, updated_at = ? WHERE id = ?',
                [$storageValue, $group, $now, $id],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
            );
        } else {
            try {
                $this->db->executeStatement(
                    'INSERT INTO ' . VL_DB_PREFIX . 'settings (group_name, ' . $this->db->quoteIdentifier('key') . ', ' . $this->db->quoteIdentifier('value') . ', ' . $this->db->quoteIdentifier('type') . ', created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$group, $key, $storageValue, $type, $now, $now],
                    [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
                );
            } catch (UniqueConstraintViolationException) {
                // A concurrent request inserted the same key between SELECT and
                // INSERT — update the winning row instead of failing the save.
                $this->db->executeStatement(
                    'UPDATE ' . VL_DB_PREFIX . 'settings SET ' . $this->db->quoteIdentifier('value') . ' = ?, group_name = ?, updated_at = ? WHERE ' . $this->db->quoteIdentifier('key') . ' = ?',
                    [$storageValue, $group, $now, $key],
                    [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
                );
            }
        }

        $this->cache[$key] = $value;
    }

    /**
     * @param array<string, string> $settings Key => value pairs
     */
    public function setMany(array $settings, string $group = 'general'): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, $value, $group);
        }
    }

    public function delete(string $key): void
    {
        $this->db->executeStatement(
            'DELETE FROM ' . VL_DB_PREFIX . 'settings WHERE ' . $this->db->quoteIdentifier('key') . ' = ?',
            [$key],
            [ParameterType::STRING],
        );
        unset($this->cache[$key]);
    }

    public function deleteByPrefix(string $prefix): int
    {
        $deleted = 0;

        foreach ($this->all() as $row) {
            $key = (string) ($row['key'] ?? '');
            if ($key !== '' && str_starts_with($key, $prefix)) {
                $this->delete($key);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array<int, array{id: int, group_name: string, key: string, value: string, type: string}>
     */
    public function all(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM ' . VL_DB_PREFIX . 'settings ORDER BY group_name, ' . $this->db->quoteIdentifier('key'),
        );
    }
}
