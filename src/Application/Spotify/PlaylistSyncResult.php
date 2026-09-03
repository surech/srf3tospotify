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
    public int $totalTrackCount;
    public int $totalUnresolvedCount;

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
        $totalTrackCount = 0;
        $totalUnresolvedCount = 0;
        foreach ($playlists as $playlist) {
            $totalTrackCount += $playlist->trackCount;
            $totalUnresolvedCount += $playlist->unresolvedCount;
        }
        $this->totalTrackCount = $totalTrackCount;
        $this->totalUnresolvedCount = $totalUnresolvedCount;
    }

    /** @return array<string, int|string|list<array<string, int|string>>> */
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
            'total_track_count' => $this->totalTrackCount,
            'total_unresolved_count' => $this->totalUnresolvedCount,
            'playlists' => array_map(
                static fn(SynchronizedPlaylist $playlist): array => $playlist->toArray(),
                $this->playlists,
            ),
        ];
    }
}
