<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Application\Spotify\PlaylistExclusions;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final readonly class SongIgnoreRepository
{
    public function __construct(private PDO $connection) {}

    public function create(int $songId, ?int $playlistId, ?string $reason): SongIgnoreRule
    {
        $this->connection->beginTransaction();
        try {
            $this->lockSong($songId);
            if ($playlistId !== null) {
                $this->requirePlaylist($playlistId);
            }
            $activeRules = $this->activeScopesForSong($songId);
            foreach ($activeRules as $activePlaylistId) {
                if ($activePlaylistId === $playlistId) {
                    throw new InvalidArgumentException('Song is already ignored in this scope.');
                }
                if ($playlistId !== null && $activePlaylistId === null) {
                    throw new InvalidArgumentException('Song is already ignored globally.');
                }
            }

            $query = $this->connection->prepare(
                'INSERT INTO song_ignore_rules (song_id, playlist_id, reason) '
                . 'VALUES (:song_id, :playlist_id, :reason)',
            );
            $query->bindValue('song_id', $songId, PDO::PARAM_INT);
            $query->bindValue('playlist_id', $playlistId, $playlistId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $query->bindValue('reason', $reason, $reason === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $query->execute();
            $ruleId = (int) $this->connection->lastInsertId();
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $this->find($ruleId) ?? throw new RuntimeException('Unable to reload ignored song rule.');
    }

    public function reactivate(int $ruleId): SongIgnoreRule
    {
        $this->connection->beginTransaction();
        try {
            $query = $this->connection->prepare(
                'SELECT reactivated_at FROM song_ignore_rules WHERE id = :id FOR UPDATE',
            );
            $query->execute(['id' => $ruleId]);
            $row = $query->fetch();
            if ($row === false) {
                throw new InvalidArgumentException('Ignored song rule does not exist.');
            }
            if ($row['reactivated_at'] !== null) {
                throw new InvalidArgumentException('Ignored song rule is already inactive.');
            }

            $update = $this->connection->prepare(
                'UPDATE song_ignore_rules SET reactivated_at = CURRENT_TIMESTAMP(6) WHERE id = :id',
            );
            $update->execute(['id' => $ruleId]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $this->find($ruleId) ?? throw new RuntimeException('Unable to reload reactivated song rule.');
    }

    public function exclusionsForPlaylist(int $playlistId): PlaylistExclusions
    {
        return $this->exclusionSnapshot([$playlistId])[$playlistId] ?? new PlaylistExclusions([], []);
    }

    public function snapshotTime(): DateTimeImmutable
    {
        $query = $this->connection->query('SELECT CURRENT_TIMESTAMP(6)');
        $value = $query === false ? false : $query->fetchColumn();
        if (!\is_string($value)) {
            throw new RuntimeException('Unable to capture ignored-song snapshot time.');
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /**
     * @param list<int> $playlistIds
     * @return array<int, PlaylistExclusions>
     */
    public function exclusionSnapshot(
        array $playlistIds,
        ?DateTimeImmutable $effectiveAt = null,
    ): array {
        $playlistIds = array_values(array_unique($playlistIds));
        if ($playlistIds === []) {
            return [];
        }

        $effectiveAt ??= $this->snapshotTime();
        $placeholders = [];
        $parameters = [
            'ignored_at_cutoff' => $effectiveAt->format('Y-m-d H:i:s.u'),
            'reactivated_at_cutoff' => $effectiveAt->format('Y-m-d H:i:s.u'),
        ];
        foreach ($playlistIds as $index => $playlistId) {
            $parameter = 'playlist_id_' . $index;
            $placeholders[] = ':' . $parameter;
            $parameters[$parameter] = $playlistId;
        }
        $query = $this->connection->prepare(\sprintf(
            <<<'SQL'
                SELECT DISTINCT p.id AS playlist_id, r.song_id, sm.spotify_track_id
                FROM playlists p
                INNER JOIN song_ignore_rules r
                    ON r.ignored_at <= :ignored_at_cutoff
                    AND (r.reactivated_at IS NULL OR r.reactivated_at > :reactivated_at_cutoff)
                    AND (r.playlist_id IS NULL OR r.playlist_id = p.id)
                LEFT JOIN spotify_matches sm
                    ON sm.song_id = r.song_id AND sm.status = 'accepted'
                WHERE p.id IN (%s)
                ORDER BY p.id, r.song_id
                SQL,
            implode(', ', $placeholders),
        ));
        $query->execute($parameters);
        $songIds = array_fill_keys($playlistIds, []);
        $spotifyTrackIds = array_fill_keys($playlistIds, []);
        while (($row = $query->fetch()) !== false) {
            $playlistId = (int) $row['playlist_id'];
            $songIds[$playlistId][] = (int) $row['song_id'];
            if ($row['spotify_track_id'] !== null) {
                $spotifyTrackIds[$playlistId][] = (string) $row['spotify_track_id'];
            }
        }

        $snapshot = [];
        foreach ($playlistIds as $playlistId) {
            $snapshot[$playlistId] = new PlaylistExclusions(
                array_values(array_unique($songIds[$playlistId])),
                array_values(array_unique($spotifyTrackIds[$playlistId])),
            );
        }

        return $snapshot;
    }

    /** @return list<SongIgnoreRule> */
    public function rules(bool $includeHistory = false): array
    {
        $where = $includeHistory ? '' : 'WHERE r.reactivated_at IS NULL';
        $query = $this->connection->query(
            <<<SQL
                SELECT r.id, r.song_id, s.artist, s.title, r.playlist_id, p.name AS playlist_name,
                    r.reason, r.ignored_at, r.reactivated_at
                FROM song_ignore_rules r
                INNER JOIN songs s ON s.id = r.song_id
                LEFT JOIN playlists p ON p.id = r.playlist_id
                {$where}
                ORDER BY r.ignored_at DESC, r.id DESC
                SQL,
        );
        if ($query === false) {
            return [];
        }

        return array_values(array_map($this->map(...), $query->fetchAll()));
    }

    public function find(int $ruleId): ?SongIgnoreRule
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                SELECT r.id, r.song_id, s.artist, s.title, r.playlist_id, p.name AS playlist_name,
                    r.reason, r.ignored_at, r.reactivated_at
                FROM song_ignore_rules r
                INNER JOIN songs s ON s.id = r.song_id
                LEFT JOIN playlists p ON p.id = r.playlist_id
                WHERE r.id = :id
                SQL,
        );
        $query->execute(['id' => $ruleId]);
        $row = $query->fetch();

        return $row === false ? null : $this->map($row);
    }

    private function lockSong(int $songId): void
    {
        $query = $this->connection->prepare('SELECT id FROM songs WHERE id = :id FOR UPDATE');
        $query->execute(['id' => $songId]);
        if ($query->fetchColumn() === false) {
            throw new InvalidArgumentException('Song does not exist.');
        }
    }

    private function requirePlaylist(int $playlistId): void
    {
        $query = $this->connection->prepare('SELECT id FROM playlists WHERE id = :id');
        $query->execute(['id' => $playlistId]);
        if ($query->fetchColumn() === false) {
            throw new InvalidArgumentException('Playlist does not exist.');
        }
    }

    /** @return list<int|null> */
    private function activeScopesForSong(int $songId): array
    {
        $query = $this->connection->prepare(
            'SELECT playlist_id FROM song_ignore_rules '
            . 'WHERE song_id = :song_id AND reactivated_at IS NULL FOR UPDATE',
        );
        $query->execute(['song_id' => $songId]);

        return array_values(array_map(
            static fn(array $row): ?int => $row['playlist_id'] === null ? null : (int) $row['playlist_id'],
            $query->fetchAll(),
        ));
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): SongIgnoreRule
    {
        $utc = new DateTimeZone('UTC');

        return new SongIgnoreRule(
            (int) $row['id'],
            (int) $row['song_id'],
            (string) $row['artist'],
            (string) $row['title'],
            $row['playlist_id'] === null ? null : (int) $row['playlist_id'],
            $row['playlist_name'] === null ? null : (string) $row['playlist_name'],
            $row['reason'] === null ? null : (string) $row['reason'],
            new DateTimeImmutable((string) $row['ignored_at'], $utc),
            $row['reactivated_at'] === null ? null : new DateTimeImmutable((string) $row['reactivated_at'], $utc),
        );
    }
}
