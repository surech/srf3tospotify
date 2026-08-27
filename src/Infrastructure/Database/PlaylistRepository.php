<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Application\Ranking\RankingEntry;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final readonly class PlaylistRepository
{
    public function __construct(private PDO $connection) {}

    public function configuration(): PlaylistConfiguration
    {
        $query = $this->connection->query(
            'SELECT id, spotify_playlist_id, spotify_owner_id, name, description, ranking_days, max_tracks, is_public '
            . 'FROM playlists ORDER BY id LIMIT 1',
        );
        $row = $query === false ? false : $query->fetch();
        if ($row === false) {
            throw new RuntimeException('Playlist configuration is missing; run database migrations.');
        }

        return new PlaylistConfiguration(
            (int) $row['id'],
            $row['spotify_playlist_id'] === null ? null : (string) $row['spotify_playlist_id'],
            $row['spotify_owner_id'] === null ? null : (string) $row['spotify_owner_id'],
            (string) $row['name'],
            (string) $row['description'],
            (int) $row['ranking_days'],
            (int) $row['max_tracks'],
            (bool) $row['is_public'],
        );
    }

    public function saveSpotifyIdentity(int $playlistId, string $spotifyPlaylistId, string $spotifyOwnerId): void
    {
        $query = $this->connection->prepare(
            'UPDATE playlists SET spotify_playlist_id = :spotify_playlist_id, spotify_owner_id = :spotify_owner_id '
            . 'WHERE id = :id',
        );
        $query->execute([
            'spotify_playlist_id' => $spotifyPlaylistId,
            'spotify_owner_id' => $spotifyOwnerId,
            'id' => $playlistId,
        ]);
    }

    public function startRun(
        int $playlistId,
        string $correlationId,
        string $triggerType,
        DateTimeImmutable $fromUtc,
        DateTimeImmutable $toUtc,
    ): int {
        $query = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO sync_runs (
                    playlist_id, correlation_id, trigger_type, status, window_from_utc, window_to_utc
                ) VALUES (
                    :playlist_id, :correlation_id, :trigger_type, 'running', :window_from_utc, :window_to_utc
                )
                SQL,
        );
        $query->execute([
            'playlist_id' => $playlistId,
            'correlation_id' => $correlationId,
            'trigger_type' => $triggerType,
            'window_from_utc' => $fromUtc->format('Y-m-d H:i:s.u'),
            'window_to_utc' => $toUtc->format('Y-m-d H:i:s.u'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /** @param list<array{ranking: RankingEntry, match: StoredSpotifyMatch}> $items */
    public function saveDesiredItems(int $runId, array $items): void
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO sync_run_items (
                    sync_run_id, position, song_id, spotify_match_id, spotify_track_id, play_count
                ) VALUES (
                    :sync_run_id, :position, :song_id, :spotify_match_id, :spotify_track_id, :play_count
                )
                SQL,
        );

        $this->connection->beginTransaction();
        try {
            foreach ($items as $position => $item) {
                $trackId = $item['match']->trackId;
                if ($trackId === null) {
                    throw new RuntimeException('Accepted Spotify match has no track ID.');
                }
                $query->execute([
                    'sync_run_id' => $runId,
                    'position' => $position,
                    'song_id' => $item['ranking']->songId,
                    'spotify_match_id' => $item['match']->id,
                    'spotify_track_id' => $trackId,
                    'play_count' => $item['ranking']->playCount,
                ]);
            }
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function finishRun(int $runId, string $snapshotId, int $unresolvedCount): void
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                UPDATE sync_runs
                SET status = 'succeeded', spotify_snapshot_id = :snapshot_id,
                    unresolved_count = :unresolved_count, finished_at = CURRENT_TIMESTAMP(6)
                WHERE id = :id
                SQL,
        );
        $query->execute([
            'snapshot_id' => $snapshotId,
            'unresolved_count' => $unresolvedCount,
            'id' => $runId,
        ]);
    }

    public function failRun(int $runId, string $message): void
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                UPDATE sync_runs
                SET status = 'failed', error_summary = :error_summary, finished_at = CURRENT_TIMESTAMP(6)
                WHERE id = :id
                SQL,
        );
        $query->execute(['error_summary' => mb_substr($message, 0, 1000), 'id' => $runId]);
    }
}
