<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

final readonly class PlaylistConfiguration
{
    public function __construct(
        public int $id,
        public ?string $spotifyPlaylistId,
        public ?string $spotifyOwnerId,
        public string $name,
        public string $description,
        public int $rankingDays,
        public int $maxTracks,
        public bool $public,
    ) {}
}
