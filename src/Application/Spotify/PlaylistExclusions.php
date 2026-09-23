<?php

declare(strict_types=1);

namespace App\Application\Spotify;

final readonly class PlaylistExclusions
{
    /** @param list<int> $songIds
     *  @param list<string> $spotifyTrackIds
     */
    public function __construct(
        public array $songIds,
        public array $spotifyTrackIds,
    ) {}
}
