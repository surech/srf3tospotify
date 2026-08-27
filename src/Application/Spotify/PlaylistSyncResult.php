<?php

declare(strict_types=1);

namespace App\Application\Spotify;

final readonly class PlaylistSyncResult
{
    public function __construct(
        public string $correlationId,
        public string $playlistId,
        public string $snapshotId,
        public int $trackCount,
        public int $unresolvedCount,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'status' => 'succeeded',
            'correlation_id' => $this->correlationId,
            'playlist_id' => $this->playlistId,
            'snapshot_id' => $this->snapshotId,
            'track_count' => $this->trackCount,
            'unresolved_count' => $this->unresolvedCount,
        ];
    }
}
