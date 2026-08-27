<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\DashboardRepository;
use App\Infrastructure\Database\Migrator;
use App\Support\Config;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DashboardRepository::class)]
final class DashboardRepositoryTest extends TestCase
{
    private PDO $connection;
    private string $correlationId;
    private int $songId;
    private int $playId;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(Config::fromEnvironment()))->create();
        (new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->correlationId = 'dashboard-' . bin2hex(random_bytes(8));

        $import = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO import_runs (
                    correlation_id, trigger_type, range_from_utc, range_to_utc, status,
                    received_count, inserted_count, finished_at
                ) VALUES (:correlation_id, 'manual', '2026-08-01', '2026-08-02', 'succeeded', 1, 1, CURRENT_TIMESTAMP(6))
                SQL,
        );
        $import->execute(['correlation_id' => $this->correlationId]);
        $importRunId = (int) $this->connection->lastInsertId();

        $song = $this->connection->prepare(
            "INSERT INTO songs (identity_hash, artist, title, normalized_artist, normalized_title) VALUES (:hash, 'Dashboard Artist', 'Dashboard Song', 'dashboard artist', 'dashboard song')",
        );
        $song->execute(['hash' => random_bytes(32)]);
        $this->songId = (int) $this->connection->lastInsertId();
        $channelId = $this->scalar('SELECT id FROM radio_channels LIMIT 1');

        $play = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO plays (
                    event_hash, radio_channel_id, song_id, import_run_id, played_at_utc,
                    source_offset_minutes, duration_ms, was_playing_now
                ) VALUES (:event_hash, :channel_id, :song_id, :run_id, '2026-08-01', 120, 180000, 0)
                SQL,
        );
        $play->execute([
            'event_hash' => random_bytes(32),
            'channel_id' => $channelId,
            'song_id' => $this->songId,
            'run_id' => $importRunId,
        ]);
        $this->playId = (int) $this->connection->lastInsertId();

        $playlistId = $this->scalar('SELECT id FROM playlists LIMIT 1');
        $sync = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO sync_runs (
                    playlist_id, correlation_id, trigger_type, status,
                    window_from_utc, window_to_utc, finished_at
                ) VALUES (:playlist_id, :correlation_id, 'manual', 'succeeded', '2026-08-01', '2026-08-02', CURRENT_TIMESTAMP(6))
                SQL,
        );
        $sync->execute([
            'playlist_id' => $playlistId,
            'correlation_id' => $this->correlationId,
        ]);
    }

    protected function tearDown(): void
    {
        $deleteSync = $this->connection->prepare('DELETE FROM sync_runs WHERE correlation_id = :correlation_id');
        $deleteSync->execute(['correlation_id' => $this->correlationId]);
        $this->connection->prepare('DELETE FROM plays WHERE id = :id')->execute(['id' => $this->playId]);
        $deleteImport = $this->connection->prepare('DELETE FROM import_runs WHERE correlation_id = :correlation_id');
        $deleteImport->execute(['correlation_id' => $this->correlationId]);
        $this->connection->prepare('DELETE FROM songs WHERE id = :id')->execute(['id' => $this->songId]);
    }

    public function testReturnsStatisticsUnresolvedMatchesAndRunHistory(): void
    {
        $repository = new DashboardRepository($this->connection);

        $statistics = $repository->statistics();
        self::assertGreaterThanOrEqual(1, $statistics['plays']);
        self::assertGreaterThanOrEqual(1, $statistics['unresolved']);

        $imports = $repository->recentImports(100);
        self::assertContains($this->correlationId, array_column($imports, 'correlation_id'));

        $syncs = $repository->recentSyncs(100);
        self::assertContains($this->correlationId, array_column($syncs, 'correlation_id'));

        $matches = $repository->unresolvedMatches(10_000);
        self::assertContains($this->songId, array_map(
            static fn(array $row): int => (int) ($row['song_id'] ?? 0),
            $matches,
        ));
    }

    private function scalar(string $sql): int
    {
        $statement = $this->connection->query($sql);
        if ($statement === false) {
            self::fail('Unable to read integration fixture dependency.');
        }

        return (int) $statement->fetchColumn();
    }
}
