<?php

declare(strict_types=1);

namespace App\Web;

final readonly class CsrfGuard
{
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private SessionStore $session) {}

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (\is_string($token) && \strlen($token) === 64) {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function valid(?string $submittedToken): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);

        return \is_string($expected)
            && \is_string($submittedToken)
            && hash_equals($expected, $submittedToken);
    }

    public function rotate(): string
    {
        $this->session->remove(self::SESSION_KEY);

        return $this->token();
    }
}
