<?php

declare(strict_types=1);

namespace ValeApp\Infrastructure\Session;

/**
 * Native PHP session wrapper with secure defaults.
 */
class SessionManager
{
    private bool $started = false;

    /** @param array{lifetime: int, secure: bool, httponly: bool, samesite: string} $config */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Start the session with secure cookie parameters.
     */
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        // Prevent session file from being garbage collected too early
        ini_set('session.gc_maxlifetime', (string) max($this->config['lifetime'] * 60, 30 * 24 * 3600));

        session_set_cookie_params([
            'lifetime'  => $this->config['lifetime'] * 60,
            'path'      => '/',
            'secure'    => $this->config['secure'],
            'httponly'  => $this->config['httponly'],
            'samesite'  => $this->config['samesite'],
        ]);

        session_name('vl_session');
        session_start();
        $this->started = true;

        // Remember-me: extend cookie on every request
        if (!empty($_SESSION['_remember_me'])) {
            $lifetime = ($_SESSION['_remember_lifetime'] ?? (30 * 24 * 60)) * 60;
            $params   = session_get_cookie_params();
            setcookie(
                session_name(),
                session_id(),
                [
                    'expires'  => time() + $lifetime,
                    'path'     => $params['path'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'],
                ],
            );
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Flash data: set or get+remove.
     *
     * Two args  -> setter: stores value under "flash_{$key}".
     * One arg   -> getter: reads and removes the key.
     */
    public function flash(string $key, mixed $value = null): mixed
    {
        $this->ensureStarted();

        // Setter mode: $this->flash('success', 'Saved!')
        if (func_num_args() === 2) {
            $_SESSION["flash_{$key}"] = $value;
            return $value;
        }

        // Getter mode: $this->flash('flash_success')
        $stored = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $stored;
    }

    /**
     * Regenerate session ID (call on login/logout).
     */
    public function regenerate(): void
    {
        $this->ensureStarted();
        session_regenerate_id(true);
    }

    /**
     * Destroy the entire session.
     */
    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $this->started = false;
    }

    public function getId(): string
    {
        $this->ensureStarted();
        return session_id();
    }

    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }
}
