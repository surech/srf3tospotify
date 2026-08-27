<?php

declare(strict_types=1);

namespace App\Web;

use RuntimeException;

final class NativeSessionStore implements SessionStore
{
    public function __construct(bool $secure)
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('srf3spotify_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Unable to start owner session.');
        }
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Unable to rotate owner session.');
        }
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (!session_destroy()) {
            throw new RuntimeException('Unable to destroy owner session.');
        }
    }
}
