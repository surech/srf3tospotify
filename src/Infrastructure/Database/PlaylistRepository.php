<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Application\Ranking\RankingEntry;
use App\Application\Ranking\RankingFilter;
use App\Application\Spotify\PlaylistTarget;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final readonly class PlaylistRepository
{
    public function __construct(private PDO $connection) {}

    public function configuration(): PlaylistConfiguration
    {
        return $this->configurations()[0];
    }

    /** @return non-empty-list<PlaylistConfiguration> */
    public function configurations(): array
    {
        $query = $this->connection->query(
            'SELECT id, spotify_playlist_id, spotify_owner_id, name, description, ranking_days, max_tracks, target_tracks, '
            . 'weekdays_only, local_start_minute, local_end_minute, is_public, fixed_from_utc, fixed_to_utc '
            . 'FROM playlists ORDER BY id',
        );
        $rows = $query === false ? [] : $query->fetchAll();
        if ($rows === []) {
            throw new RuntimeException('Playlist configuration is missing; run database migrations.');
        }

        return array_values(array_map(
            static fn(array $row): PlaylistConfiguration => new PlaylistConfiguration(
                (int) $row['id'],
                $row['spotify_playlist_id'] === null ? null : (string) $row['spotify_playlist_id'],
                $row['spotify_owner_id'] === null ? null : (string) $row['spotify_owner_id'],
                (string) $row['name'],
                (string) $row['description'],
                (int) $row['ranking_days'],
                (int) $row['max_tracks'],
                $row['target_tracks'] === null ? null : (int) $row['target_tracks'],
                new RankingFilter(
                    (bool) $row['weekdays_only'],
                    $row['local_start_minute'] === null ? null : (int) $row['local_start_minute'],
                    $row['local_end_minute'] === null ? null : (int) $row['local_end_minute'],
                ),
                (bool) $row['is_public'],
                $row['fixed_from_utc'] === null
                    ? null
                    : new DateTimeImmutable((string) $row['fixed_from_utc'], new DateTimeZone('UTC')),
                $row['fixed_to_utc'] === null
                    ? null
                    : new DateTimeImmutable((string) $row['fixed_to_utc'], new DateTimeZone('UTC')),
            ),
            $rows,
        ));
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

    public function lastSuccessfulSyncAt(int $playlistId): ?DateTimeImmutable
    {
        $query = $this->connection->prepare(
            "SELECT MAX(finished_at) FROM sync_runs WHERE playlist_id = :playlist_id AND status = 'succeeded'",
        );
        $query->execute(['playlist_id' => $playlistId]);
        $value = $query->fetchColumn();

        return $value === false || $value === null
            ? null
            : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    /**
     * @return list<array{
     *     id: int,
     *     configured_name: string,
     *     name: string,
     *     description: string,
     *     spotify_playlist_id: string,
     *     synced_at: DateTimeImmutable,
     *     tracks: list<array{position: int, spotify_track_id: string, title: string, artist: string}>
     * }>
     */
    public function publishedPlaylists(): array
    {
        $query = $this->connection->query(
            <<<'SQL'
                SELECT p.id, p.name AS configured_name,
                    sr.published_name, sr.published_description, sr.published_spotify_playlist_id,
                    sr.finished_at, sri.position, sri.spotify_track_id, sri.spotify_title, sri.spotify_artist
                FROM playlists p
                INNER JOIN (
                    SELECT playlist_id, MAX(id) AS sync_run_id
                    FROM sync_runs
                    WHERE status = 'succeeded'
                    GROUP BY playlist_id
                ) latest ON latest.playlist_id = p.id
                INNER JOIN sync_runs sr ON sr.id = latest.sync_run_id
                INNER JOIN sync_run_items sri ON sri.sync_run_id = sr.id
                WHERE p.is_public = 1
                    AND p.spotify_playlist_id IS NOT NULL
                    AND p.spotify_playlist_id = sr.published_spotify_playlist_id
                    AND sr.published_public = 1
                    AND sr.track_count > 0
                ORDER BY p.id, sri.position
                SQL,
        );
        if ($query === false) {
            throw new RuntimeException('Unable to load published playlists.');
        }

        $playlists = [];
        while (($row = $query->fetch()) !== false) {
            $playlistId = (int) $row['id'];
            if (!isset($playlists[$playlistId])) {
                foreach (['published_name', 'published_description', 'published_spotify_playlist_id', 'finished_at'] as $field) {
                    if (!\is_string($row[$field])) {
                        throw new RuntimeException('Published playlist snapshot is incomplete.');
                    }
                }
                $playlists[$playlistId] = [
                    'id' => $playlistId,
                    'configured_name' => (string) $row['configured_name'],
                    'name' => $row['published_name'],
                    'description' => $row['published_description'],
                    'spotify_playlist_id' => $row['published_spotify_playlist_id'],
                    'synced_at' => new DateTimeImmutable($row['finished_at'], new DateTimeZone('UTC')),
                    'tracks' => [],
                ];
            }
            if (!\is_string($row['spotify_title']) || !\is_string($row['spotify_artist'])) {
                throw new RuntimeException('Published track snapshot is incomplete.');
            }
            $playlists[$playlistId]['tracks'][] = [
                'position' => (int) $row['position'] + 1,
                'spotify_track_id' => (string) $row['spotify_track_id'],
                'title' => $row['spotify_title'],
                'artist' => $row['spotify_artist'],
            ];
        }

        $result = array_values($playlists);
        usort(
            $result,
            static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']),
        );

        return $result;
    }

    public function isPublished(int $playlistId): bool
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM playlists p
                    INNER JOIN sync_runs sr ON sr.id = (
                        SELECT MAX(latest.id)
                        FROM sync_runs latest
                        WHERE latest.playlist_id = p.id AND latest.status = 'succeeded'
                    )
                    WHERE p.id = :playlist_id
                        AND p.is_public = 1
                        AND p.spotify_playlist_id IS NOT NULL
                        AND p.spotify_playlist_id = sr.published_spotify_playlist_id
                        AND sr.published_public = 1
                        AND sr.track_count > 0
                        AND EXISTS (SELECT 1 FROM sync_run_items sri WHERE sri.sync_run_id = sr.id)
                )
                SQL,
        );
        $query->execute(['playlist_id' => $playlistId]);

        return (bool) $query->fetchColumn();
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
                    sync_run_id, position, song_id, spotify_match_id, spotify_track_id,
                    spotify_title, spotify_artist, play_count
                ) VALUES (
                    :sync_run_id, :position, :song_id, :spotify_match_id, :spotify_track_id,
                    :spotify_title, :spotify_artist, :play_count
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
                    'spotify_title' => $item['match']->title ?? $item['ranking']->title,
                    'spotify_artist' => $item['match']->artist ?? $item['ranking']->artist,
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

    public function finishRun(
        int $runId,
        string $snapshotId,
        int $requestedCount,
        PlaylistTarget $target,
        PlaylistConfiguration $configuration,
        string $spotifyPlaylistId,
    ): void {
        $query = $this->connection->prepare(
            <<<'SQL'
                UPDATE sync_runs
                SET status = 'succeeded', spotify_snapshot_id = :snapshot_id,
                    published_name = :published_name, published_description = :published_description,
                    published_spotify_playlist_id = :published_spotify_playlist_id,
                    published_public = :published_public,
                    unresolved_count = :unresolved_count, requested_count = :requested_count,
                    track_count = :track_count, ignored_count = :ignored_count,
                    duplicate_track_count = :duplicate_track_count, finished_at = CURRENT_TIMESTAMP(6)
                WHERE id = :id
                SQL,
        );
        $query->execute([
            'snapshot_id' => $snapshotId,
            'published_name' => $configuration->name,
            'published_description' => $configuration->description,
            'published_spotify_playlist_id' => $spotifyPlaylistId,
            'published_public' => (int) $configuration->public,
            'unresolved_count' => $target->missingMatchCount,
            'requested_count' => $requestedCount,
            'track_count' => \count($target->desired),
            'ignored_count' => $target->ignoredCount,
            'duplicate_track_count' => $target->duplicateTrackCount,
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
