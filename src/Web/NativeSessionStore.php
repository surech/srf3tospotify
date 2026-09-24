<?php

declare(strict_types=1);

namespace App\Web;

use RuntimeException;

final class NativeSessionStore implements SessionStore
{
    private bool $started;

    public function __construct(
        private readonly bool $secure,
        private readonly string $cookiePath = '/admin',
    ) {
        $this->started = session_status() === PHP_SESSION_ACTIVE;
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to rotate owner session.');
        }
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy owner session.');
        }
        $this->started = false;
    }

    private function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }
        session_name('srf3spotify_admin_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Unable to start owner session.');
        }
        $this->started = true;
    }
}
