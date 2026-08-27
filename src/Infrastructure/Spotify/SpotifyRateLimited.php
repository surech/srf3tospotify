<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

final class SpotifyRateLimited extends SpotifyException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(\sprintf('Spotify rate limit reached; retry after %d seconds.', $retryAfterSeconds));
    }
}
