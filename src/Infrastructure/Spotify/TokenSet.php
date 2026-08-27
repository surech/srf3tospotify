<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

use DateTimeImmutable;

final readonly class TokenSet
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public DateTimeImmutable $expiresAt,
    ) {
        if ($accessToken === '' || $refreshToken === '') {
            throw new SpotifyException('Spotify token response is incomplete.');
        }
    }
}
