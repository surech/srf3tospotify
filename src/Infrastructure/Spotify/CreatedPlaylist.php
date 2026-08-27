<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

final readonly class CreatedPlaylist
{
    public function __construct(
        public string $id,
        public string $ownerId,
    ) {
        if ($id === '' || $ownerId === '') {
            throw new SpotifyException('Spotify playlist response is incomplete.');
        }
    }
}
