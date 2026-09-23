<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use App\Application\Ranking\RankingEntry;
use App\Infrastructure\Database\StoredSpotifyMatch;
use InvalidArgumentException;

final class PlaylistTargetBuilder
{
    /** @var array<int, true> */
    private array $ignoredSongIds;

    /** @var array<string, true> */
    private array $ignoredTrackIds;

    /** @var array<string, true> */
    private array $selectedTrackIds = [];

    /** @var list<array{ranking: RankingEntry, match: StoredSpotifyMatch}> */
    private array $desired = [];

    /** @var list<array{ranking: RankingEntry, match: StoredSpotifyMatch|null, reason: string, airplay_rank: int}> */
    private array $skipped = [];

    private int $ignoredCount = 0;
    private int $missingMatchCount = 0;
    private int $duplicateTrackCount = 0;
    private int $examinedCount = 0;

    /** @param list<int> $ignoredSongIds
     *  @param list<string> $ignoredTrackIds
     */
    public function __construct(
        private readonly int $limit,
        array $ignoredSongIds = [],
        array $ignoredTrackIds = [],
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('Playlist target limit must be positive.');
        }

        $this->ignoredSongIds = array_fill_keys($ignoredSongIds, true);
        $this->ignoredTrackIds = array_fill_keys($ignoredTrackIds, true);
    }

    public function needsMore(): bool
    {
        return \count($this->desired) < $this->limit;
    }

    public function isDirectlyIgnored(int $songId): bool
    {
        return isset($this->ignoredSongIds[$songId]);
    }

    public function consider(RankingEntry $entry, ?StoredSpotifyMatch $match): void
    {
        if (!$this->needsMore()) {
            return;
        }

        ++$this->examinedCount;
        if ($this->isDirectlyIgnored($entry->songId)) {
            ++$this->ignoredCount;

            return;
        }
        if ($match === null || $match->status !== 'accepted' || $match->trackId === null || $match->uri === null) {
            ++$this->missingMatchCount;
            $this->skipped[] = [
                'ranking' => $entry,
                'match' => $match,
                'reason' => PlaylistTarget::SKIPPED_MISSING_MATCH,
                'airplay_rank' => $this->examinedCount,
            ];

            return;
        }
        if (isset($this->ignoredTrackIds[$match->trackId])) {
            ++$this->ignoredCount;

            return;
        }
        if (isset($this->selectedTrackIds[$match->trackId])) {
            ++$this->duplicateTrackCount;
            $this->skipped[] = [
                'ranking' => $entry,
                'match' => $match,
                'reason' => PlaylistTarget::SKIPPED_DUPLICATE_TRACK,
                'airplay_rank' => $this->examinedCount,
            ];

            return;
        }

        $this->selectedTrackIds[$match->trackId] = true;
        $this->desired[] = ['ranking' => $entry, 'match' => $match];
    }

    public function result(): PlaylistTarget
    {
        return new PlaylistTarget(
            $this->desired,
            $this->skipped,
            $this->ignoredCount,
            $this->missingMatchCount,
            $this->duplicateTrackCount,
            $this->examinedCount,
        );
    }
}
