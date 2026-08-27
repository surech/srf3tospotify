<?php

declare(strict_types=1);

namespace App\Web;

final readonly class OAuthState
{
    private const SESSION_KEY = 'spotify_oauth_state';

    public function __construct(private SessionStore $session) {}

    public function issue(): string
    {
        $state = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $state);

        return $state;
    }

    public function consume(?string $submittedState): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);
        $this->session->remove(self::SESSION_KEY);

        return \is_string($expected)
            && \is_string($submittedState)
            && hash_equals($expected, $submittedState);
    }
}
