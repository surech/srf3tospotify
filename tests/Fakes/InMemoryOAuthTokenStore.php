<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Infrastructure\Spotify\OAuthTokenStore;
use App\Infrastructure\Spotify\TokenSet;

final class InMemoryOAuthTokenStore implements OAuthTokenStore
{
    public function __construct(public ?TokenSet $tokens = null) {}

    public function load(): ?TokenSet
    {
        return $this->tokens;
    }

    public function save(TokenSet $tokens): void
    {
        $this->tokens = $tokens;
    }
}
