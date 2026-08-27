<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Application\Ranking\RankingEntry;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class RankingRepository
{
    public function __construct(private PDO $connection) {}

    /** @return list<RankingEntry> */
    public function topSongs(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtcExclusive, int $limit): array
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                SELECT
                    s.id AS song_id,
                    s.artist,
                    s.title,
                    COUNT(*) AS play_count,
                    ROUND(AVG(p.duration_ms)) AS duration_ms,
                    MAX(p.played_at_utc) AS last_played_at_utc,
                    sm.spotify_track_id,
                    COALESCE(sm.status, 'pending') AS match_status
                FROM plays p
                INNER JOIN songs s ON s.id = p.song_id
                LEFT JOIN spotify_matches sm ON sm.song_id = s.id
                WHERE p.played_at_utc >= :from_utc AND p.played_at_utc < :to_utc
                GROUP BY s.id, s.artist, s.title, s.normalized_artist, s.normalized_title,
                    sm.spotify_track_id, sm.status
                ORDER BY play_count DESC, last_played_at_utc DESC,
                    s.normalized_artist ASC, s.normalized_title ASC
                LIMIT :limit
                SQL,
        );
        $query->bindValue('from_utc', $fromUtc->format('Y-m-d H:i:s.u'));
        $query->bindValue('to_utc', $toUtcExclusive->format('Y-m-d H:i:s.u'));
        $query->bindValue('limit', $limit, PDO::PARAM_INT);
        $query->execute();

        $entries = [];
        while (($row = $query->fetch()) !== false) {
            $entries[] = new RankingEntry(
                (int) $row['song_id'],
                (string) $row['artist'],
                (string) $row['title'],
                (int) $row['play_count'],
                (int) $row['duration_ms'],
                new DateTimeImmutable((string) $row['last_played_at_utc'], new DateTimeZone('UTC')),
                $row['spotify_track_id'] === null ? null : (string) $row['spotify_track_id'],
                (string) $row['match_status'],
            );
        }

        return $entries;
    }
}
