<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

interface OAuthTokenStore
{
    public function load(): ?TokenSet;

    public function save(TokenSet $tokens): void;
}
