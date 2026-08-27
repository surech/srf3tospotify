<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

final readonly class DashboardRepository
{
    public function __construct(private PDO $connection) {}

    /** @return list<array<string, int|string|null>> */
    public function recentImports(int $limit = 8): array
    {
        $query = $this->connection->prepare(
            'SELECT correlation_id, trigger_type, status, received_count, inserted_count, duplicate_count, '
            . 'error_summary, started_at, finished_at FROM import_runs ORDER BY started_at DESC LIMIT :limit',
        );
        $query->bindValue('limit', $limit, PDO::PARAM_INT);
        $query->execute();

        return $this->rows($query);
    }

    /** @return list<array<string, int|string|null>> */
    public function recentSyncs(int $limit = 8): array
    {
        $query = $this->connection->prepare(
            'SELECT correlation_id, trigger_type, status, unresolved_count, spotify_snapshot_id, '
            . 'error_summary, started_at, finished_at FROM sync_runs ORDER BY started_at DESC LIMIT :limit',
        );
        $query->bindValue('limit', $limit, PDO::PARAM_INT);
        $query->execute();

        return $this->rows($query);
    }

    /** @return list<array<string, int|string|null>> */
    public function unresolvedMatches(int $limit = 30): array
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                SELECT s.id AS song_id, s.artist, s.title,
                    COUNT(p.id) AS play_count,
                    COALESCE(sm.status, 'pending') AS status,
                    sm.confidence
                FROM songs s
                INNER JOIN plays p ON p.song_id = s.id
                LEFT JOIN spotify_matches sm ON sm.song_id = s.id
                WHERE sm.id IS NULL OR sm.status IN ('pending', 'review')
                GROUP BY s.id, s.artist, s.title, sm.status, sm.confidence
                ORDER BY play_count DESC, s.normalized_artist, s.normalized_title
                LIMIT :limit
                SQL,
        );
        $query->bindValue('limit', $limit, PDO::PARAM_INT);
        $query->execute();

        return $this->rows($query);
    }

    /** @return array{plays: int, songs: int, unresolved: int, last_import: string|null, last_sync: string|null} */
    public function statistics(): array
    {
        $query = $this->connection->query(
            <<<'SQL'
                SELECT
                    (SELECT COUNT(*) FROM plays) AS plays,
                    (SELECT COUNT(*) FROM songs) AS songs,
                    (SELECT COUNT(*) FROM songs s LEFT JOIN spotify_matches sm ON sm.song_id = s.id
                        WHERE sm.id IS NULL OR sm.status IN ('pending', 'review')) AS unresolved,
                    (SELECT MAX(finished_at) FROM import_runs WHERE status = 'succeeded') AS last_import,
                    (SELECT MAX(finished_at) FROM sync_runs WHERE status = 'succeeded') AS last_sync
                SQL,
        );
        $row = $query === false ? false : $query->fetch();
        if ($row === false) {
            return ['plays' => 0, 'songs' => 0, 'unresolved' => 0, 'last_import' => null, 'last_sync' => null];
        }

        return [
            'plays' => (int) $row['plays'],
            'songs' => (int) $row['songs'],
            'unresolved' => (int) $row['unresolved'],
            'last_import' => $row['last_import'] === null ? null : (string) $row['last_import'],
            'last_sync' => $row['last_sync'] === null ? null : (string) $row['last_sync'],
        ];
    }

    /** @return list<array<string, int|string|null>> */
    private function rows(\PDOStatement $query): array
    {
        $rows = [];
        while (($row = $query->fetch()) !== false) {
            $mapped = [];
            foreach ($row as $key => $value) {
                if (\is_string($key) && (\is_string($value) || \is_int($value) || $value === null)) {
                    $mapped[$key] = $value;
                }
            }
            $rows[] = $mapped;
        }

        return $rows;
    }
}
