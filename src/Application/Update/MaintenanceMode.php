<?php

declare(strict_types=1);

namespace ValeApp\Application\Update;

/**
 * Maintenance mode manager.
 *
 * When active, a flag file is written to storage/framework/maintenance.php.
 * The MaintenanceModeMiddleware reads this file on every request and returns
 * a 503 response if it exists and the client is not in the allowed-IP list.
 *
 * Flag file format (PHP file returning an array for O(1) inclusion):
 *
 *   <?php return ['active' => true, 'allowed_ips' => ['127.0.0.1'], 'message' => 'Updating…'];
 */
final class MaintenanceMode
{
    private string $flagFile;

    public function __construct(private readonly string $rootDir)
    {
        $this->flagFile = $rootDir . '/storage/framework/maintenance.php';
    }

    // ── Activate ──────────────────────────────────────────────────────────

    /**
     * Enable maintenance mode.
     *
     * @param string[]|null $allowedIps IP addresses that bypass the 503 page.
     *                                  When null, only loopback (127.0.0.1 / ::1) is allowed.
     */
    public function activate(?array $allowedIps = null, string $message = 'Site is temporarily under maintenance. Please check back shortly.'): void
    {
        $allowed = $allowedIps ?? ['127.0.0.1', '::1'];

        $dir = dirname($this->flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            'active'       => true,
            'allowed_ips'  => $allowed,
            'message'      => $message,
            'activated_at' => time(),
        ];

        $export  = var_export($data, true);
        $content = "<?php return {$export};\n";

        if (file_put_contents($this->flagFile, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write maintenance flag file: {$this->flagFile}");
        }
    }

    // ── Deactivate ────────────────────────────────────────────────────────

    /**
     * Disable maintenance mode by removing the flag file.
     */
    public function deactivate(): void
    {
        if (file_exists($this->flagFile)) {
            @unlink($this->flagFile);
        }
    }

    // ── Status ────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        if (!file_exists($this->flagFile)) {
            return false;
        }
        $data = @include $this->flagFile;
        return is_array($data) && ($data['active'] ?? false) === true;
    }

    /**
     * @return array<string,mixed>
     */
    public function getData(): array
    {
        if (!file_exists($this->flagFile)) {
            return ['active' => false, 'allowed_ips' => [], 'message' => ''];
        }
        $data = @include $this->flagFile;
        return is_array($data) ? $data : ['active' => false, 'allowed_ips' => [], 'message' => ''];
    }

    /**
     * @return string[]
     */
    public function getAllowedIps(): array
    {
        return $this->getData()['allowed_ips'] ?? [];
    }
}
