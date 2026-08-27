<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Maintenance\CleanupService;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\MaintenanceRepository;
use App\Infrastructure\Database\Migrator;
use App\Support\Config;
use App\Support\JsonLogger;
use App\Support\JsonLogPruner;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CleanupService::class)]
#[CoversClass(MaintenanceRepository::class)]
final class CleanupServiceTest extends TestCase
{
    private PDO $connection;
    private string $correlationId;
    private string $logPath;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(Config::fromEnvironment()))->create();
        (new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->correlationId = 'cleanup-' . bin2hex(random_bytes(8));
        $this->logPath = sys_get_temp_dir() . '/' . $this->correlationId . '.log';
    }

    protected function tearDown(): void
    {
        $query = $this->connection->prepare('DELETE FROM import_runs WHERE correlation_id = :correlation_id');
        $query->execute(['correlation_id' => $this->correlationId]);
        @unlink($this->logPath);
    }

    public function testDeletesOldRunWhileKeepingImportedPlay(): void
    {
        $runQuery = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO import_runs (
                    correlation_id, trigger_type, range_from_utc, range_to_utc, status, finished_at
                ) VALUES (:correlation_id, 'manual', '2025-01-01', '2025-01-02', 'succeeded', '2025-01-02')
                SQL,
        );
        $runQuery->execute(['correlation_id' => $this->correlationId]);
        $runId = (int) $this->connection->lastInsertId();
        $songHash = random_bytes(32);
        $songQuery = $this->connection->prepare(
            "INSERT INTO songs (identity_hash, artist, title, normalized_artist, normalized_title) VALUES (:hash, 'Cleanup Artist', 'Cleanup Song', 'cleanup artist', 'cleanup song')",
        );
        $songQuery->execute(['hash' => $songHash]);
        $songId = (int) $this->connection->lastInsertId();
        $channelId = $this->scalar('SELECT id FROM radio_channels LIMIT 1');
        $playQuery = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO plays (
                    event_hash, radio_channel_id, song_id, import_run_id, played_at_utc,
                    source_offset_minutes, duration_ms, was_playing_now
                ) VALUES (:event_hash, :channel_id, :song_id, :run_id, '2025-01-01', 60, 180000, 0)
                SQL,
        );
        $playQuery->execute([
            'event_hash' => random_bytes(32),
            'channel_id' => $channelId,
            'song_id' => $songId,
            'run_id' => $runId,
        ]);
        $playId = (int) $this->connection->lastInsertId();
        file_put_contents($this->logPath, "{\"timestamp\":\"2025-01-01T00:00:00Z\"}\n");

        $result = (new CleanupService(
            new MaintenanceRepository($this->connection),
            new JsonLogPruner($this->logPath),
            new JsonLogger($this->logPath),
        ))->cleanup(90, new DateTimeImmutable('2026-08-27T00:00:00Z'));

        self::assertSame(1, $result->deletedImportRuns);
        self::assertSame(1, $result->deletedLogRecords);
        $playLookup = $this->connection->prepare('SELECT import_run_id FROM plays WHERE id = :id');
        $playLookup->execute(['id' => $playId]);
        self::assertNull($playLookup->fetchColumn());

        $this->connection->prepare('DELETE FROM plays WHERE id = :id')->execute(['id' => $playId]);
        $this->connection->prepare('DELETE FROM songs WHERE id = :id')->execute(['id' => $songId]);
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
