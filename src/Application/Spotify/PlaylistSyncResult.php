<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use RuntimeException;

final readonly class PlaylistSyncResult
{
    public string $correlationId;
    public string $playlistId;
    public string $snapshotId;
    public int $playlistCount;
    public int $trackCount;
    public int $unresolvedCount;
    public bool $hasWarnings;
    public int $totalTrackCount;
    public int $totalRequestedCount;
    public int $totalUnresolvedCount;
    public int $totalIgnoredCount;
    public int $totalDuplicateTrackCount;

    /** @param list<SynchronizedPlaylist> $playlists */
    public function __construct(public array $playlists)
    {
        $primary = $playlists[0] ?? throw new RuntimeException('At least one playlist must be synchronized.');
        $this->correlationId = $primary->correlationId;
        $this->playlistId = $primary->playlistId;
        $this->snapshotId = $primary->snapshotId;
        $this->playlistCount = \count($playlists);
        $this->trackCount = $primary->trackCount;
        $this->unresolvedCount = $primary->unresolvedCount;
        $hasWarnings = false;
        $totalTrackCount = 0;
        $totalRequestedCount = 0;
        $totalUnresolvedCount = 0;
        $totalIgnoredCount = 0;
        $totalDuplicateTrackCount = 0;
        foreach ($playlists as $playlist) {
            $hasWarnings = $hasWarnings || $playlist->hasWarning;
            $totalTrackCount += $playlist->trackCount;
            $totalRequestedCount += $playlist->requestedCount;
            $totalUnresolvedCount += $playlist->unresolvedCount;
            $totalIgnoredCount += $playlist->ignoredCount;
            $totalDuplicateTrackCount += $playlist->duplicateTrackCount;
        }
        $this->hasWarnings = $hasWarnings;
        $this->totalTrackCount = $totalTrackCount;
        $this->totalRequestedCount = $totalRequestedCount;
        $this->totalUnresolvedCount = $totalUnresolvedCount;
        $this->totalIgnoredCount = $totalIgnoredCount;
        $this->totalDuplicateTrackCount = $totalDuplicateTrackCount;
    }

    /** @return array<string, bool|int|string|list<array<string, bool|int|string>>> */
    public function toArray(): array
    {
        return [
            'status' => 'succeeded',
            'correlation_id' => $this->correlationId,
            'playlist_id' => $this->playlistId,
            'snapshot_id' => $this->snapshotId,
            'playlist_count' => $this->playlistCount,
            'track_count' => $this->trackCount,
            'unresolved_count' => $this->unresolvedCount,
            'has_warnings' => $this->hasWarnings,
            'total_track_count' => $this->totalTrackCount,
            'total_requested_count' => $this->totalRequestedCount,
            'total_unresolved_count' => $this->totalUnresolvedCount,
            'total_ignored_count' => $this->totalIgnoredCount,
            'total_duplicate_track_count' => $this->totalDuplicateTrackCount,
            'playlists' => array_map(
                static fn(SynchronizedPlaylist $playlist): array => $playlist->toArray(),
                $this->playlists,
            ),
        ];
    }
}
