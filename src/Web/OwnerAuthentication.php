<?php

declare(strict_types=1);

namespace App\Web;

use RuntimeException;

final readonly class OwnerAuthentication
{
    private const SESSION_KEY = 'owner_authenticated';

    public function __construct(
        private SessionStore $session,
        private string $passwordHash,
    ) {}

    public function authenticated(): bool
    {
        return $this->session->get(self::SESSION_KEY) === true;
    }

    public function login(string $password): bool
    {
        if ($this->passwordHash === '') {
            throw new RuntimeException('ADMIN_PASSWORD_HASH is not configured.');
        }
        if (!password_verify($password, $this->passwordHash)) {
            return false;
        }

        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, true);

        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
    }
}
