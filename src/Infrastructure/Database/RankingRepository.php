<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Application\Ranking\RankingEntry;
use App\Application\Ranking\RankingFilter;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;

final readonly class RankingRepository
{
    public function __construct(private PDO $connection) {}

    /** @return list<RankingEntry> */
    public function topSongs(
        DateTimeImmutable $fromUtc,
        DateTimeImmutable $toUtcExclusive,
        int $limit,
        ?RankingFilter $filter = null,
    ): array {
        $filter ??= new RankingFilter();
        $where = implode(' AND ', $this->conditions($filter));

        $query = $this->connection->prepare(
            <<<SQL
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
                WHERE {$where}
                GROUP BY s.id, s.artist, s.title, s.normalized_artist, s.normalized_title,
                    sm.spotify_track_id, sm.status
                ORDER BY play_count DESC, last_played_at_utc DESC,
                    s.normalized_artist ASC, s.normalized_title ASC
                LIMIT :limit
                SQL,
        );
        $this->bindWindowAndFilter($query, $fromUtc, $toUtcExclusive, $filter);
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

    /**
     * @param list<int> $songIds
     * @return array<int, list<DateTimeImmutable>>
     */
    public function playTimesBySong(
        DateTimeImmutable $fromUtc,
        DateTimeImmutable $toUtcExclusive,
        array $songIds,
        ?RankingFilter $filter = null,
    ): array {
        $songIds = array_values(array_unique($songIds));
        if ($songIds === []) {
            return [];
        }

        $filter ??= new RankingFilter();
        $placeholders = [];
        foreach ($songIds as $index => $songId) {
            $placeholders[] = ':song_id_' . $index;
        }
        $songIdPlaceholders = implode(', ', $placeholders);
        $where = implode(' AND ', $this->conditions($filter));
        $query = $this->connection->prepare(
            <<<SQL
                SELECT p.song_id, p.played_at_utc, p.source_offset_minutes
                FROM plays p
                WHERE {$where}
                    AND p.song_id IN ({$songIdPlaceholders})
                ORDER BY p.song_id, p.played_at_utc DESC
                SQL,
        );
        $this->bindWindowAndFilter($query, $fromUtc, $toUtcExclusive, $filter);
        foreach ($songIds as $index => $songId) {
            $query->bindValue('song_id_' . $index, $songId, PDO::PARAM_INT);
        }
        $query->execute();

        $playTimes = [];
        foreach ($songIds as $songId) {
            $playTimes[$songId] = [];
        }
        while (($row = $query->fetch()) !== false) {
            $sourceOffsetMinutes = (int) $row['source_offset_minutes'];
            $absoluteOffset = abs($sourceOffsetMinutes);
            $sourceTimezone = new DateTimeZone(\sprintf(
                '%s%02d:%02d',
                $sourceOffsetMinutes < 0 ? '-' : '+',
                intdiv($absoluteOffset, 60),
                $absoluteOffset % 60,
            ));
            $playTimes[(int) $row['song_id']][] = (new DateTimeImmutable(
                (string) $row['played_at_utc'],
                new DateTimeZone('UTC'),
            ))->setTimezone($sourceTimezone);
        }

        return $playTimes;
    }

    /** @return list<string> */
    private function conditions(RankingFilter $filter): array
    {
        $localPlayedAt = 'DATE_ADD(p.played_at_utc, INTERVAL p.source_offset_minutes MINUTE)';
        $conditions = [
            'p.played_at_utc >= :from_utc',
            'p.played_at_utc < :to_utc',
        ];
        if ($filter->weekdaysOnly) {
            $conditions[] = \sprintf('WEEKDAY(%s) < 5', $localPlayedAt);
        }
        if ($filter->localStartMinute !== null && $filter->localEndMinute !== null) {
            $localMinute = \sprintf('(HOUR(%1$s) * 60 + MINUTE(%1$s))', $localPlayedAt);
            $conditions[] = $localMinute . ' >= :local_start_minute';
            $conditions[] = $localMinute . ' < :local_end_minute';
        }

        return $conditions;
    }

    private function bindWindowAndFilter(
        PDOStatement $query,
        DateTimeImmutable $fromUtc,
        DateTimeImmutable $toUtcExclusive,
        RankingFilter $filter,
    ): void {
        $query->bindValue('from_utc', $fromUtc->format('Y-m-d H:i:s.u'));
        $query->bindValue('to_utc', $toUtcExclusive->format('Y-m-d H:i:s.u'));
        if ($filter->localStartMinute !== null && $filter->localEndMinute !== null) {
            $query->bindValue('local_start_minute', $filter->localStartMinute, PDO::PARAM_INT);
            $query->bindValue('local_end_minute', $filter->localEndMinute, PDO::PARAM_INT);
        }
    }
}
