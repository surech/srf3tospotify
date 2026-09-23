<?php

declare(strict_types=1);

namespace App\Application\Spotify;

final readonly class SynchronizedPlaylist
{
    public bool $hasWarning;

    public function __construct(
        public string $name,
        public string $correlationId,
        public string $playlistId,
        public string $snapshotId,
        public int $requestedCount,
        public int $trackCount,
        public int $unresolvedCount,
        public int $ignoredCount,
        public int $duplicateTrackCount,
    ) {
        $this->hasWarning = $trackCount < $requestedCount;
    }

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'correlation_id' => $this->correlationId,
            'playlist_id' => $this->playlistId,
            'snapshot_id' => $this->snapshotId,
            'requested_count' => $this->requestedCount,
            'track_count' => $this->trackCount,
            'unresolved_count' => $this->unresolvedCount,
            'ignored_count' => $this->ignoredCount,
            'duplicate_track_count' => $this->duplicateTrackCount,
            'has_warning' => $this->hasWarning,
        ];
    }
}
