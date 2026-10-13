<?php

namespace Poletti\Framework\Session;

use RuntimeException;

final class PhpSession implements SessionInterface
{
    public function __construct(
        private string $name = 'PHPSESSID'
    ) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            throw new RuntimeException(
                'Cannot start session after headers have been sent.'
            );
        }

        session_name($this->name);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (!session_start()) {
            throw new RuntimeException(
                'Could not start session.'
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

        return array_key_exists($key, $_SESSION);
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();

        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $this->ensureStarted();

        $_SESSION = [];
    }

    public function regenerate(): void
    {
        $this->ensureStarted();
    
        if (!session_regenerate_id(true)) {
            throw new RuntimeException(
                'Could not regenerate session ID.'
            );
        }
    }

    private function ensureStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException(
                'Session has not been started.'
            );
        }
    }
}