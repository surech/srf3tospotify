<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

final readonly class SpotifyTrack
{
    /** @param list<string> $artists */
    public function __construct(
        public string $id,
        public string $uri,
        public string $title,
        public array $artists,
        public int $durationMs,
    ) {
        if ($id === '' || $uri === '' || $title === '' || $artists === [] || $durationMs < 0) {
            throw new SpotifyException('Spotify track is incomplete.');
        }
    }

    public function artistLabel(): string
    {
        return implode(', ', $this->artists);
    }
}
