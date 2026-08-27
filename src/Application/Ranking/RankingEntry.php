<?php

declare(strict_types=1);

namespace App\Application\Ranking;

use DateTimeImmutable;

final readonly class RankingEntry
{
    public function __construct(
        public int $songId,
        public string $artist,
        public string $title,
        public int $playCount,
        public int $durationMs,
        public DateTimeImmutable $lastPlayedAtUtc,
        public ?string $spotifyTrackId,
        public string $matchStatus,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'song_id' => $this->songId,
            'artist' => $this->artist,
            'title' => $this->title,
            'play_count' => $this->playCount,
            'duration_ms' => $this->durationMs,
            'last_played_at_utc' => $this->lastPlayedAtUtc->format(DATE_ATOM),
            'spotify_track_id' => $this->spotifyTrackId,
            'match_status' => $this->matchStatus,
        ];
    }
}
