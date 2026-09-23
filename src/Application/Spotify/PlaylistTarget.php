<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use App\Application\Ranking\RankingEntry;
use App\Infrastructure\Database\StoredSpotifyMatch;

final readonly class PlaylistTarget
{
    public const SKIPPED_MISSING_MATCH = 'missing_match';
    public const SKIPPED_DUPLICATE_TRACK = 'duplicate_track';

    /**
     * @param list<array{ranking: RankingEntry, match: StoredSpotifyMatch}> $desired
        * @param list<array{ranking: RankingEntry, match: StoredSpotifyMatch|null, reason: string, airplay_rank: int}> $skipped
     */
    public function __construct(
        public array $desired,
        public array $skipped,
        public int $ignoredCount,
        public int $missingMatchCount,
        public int $duplicateTrackCount,
        public int $examinedCount,
    ) {}
}
