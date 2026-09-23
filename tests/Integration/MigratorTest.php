<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Support\Config;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Migrator::class)]
#[CoversClass(ConnectionFactory::class)]
final class MigratorTest extends TestCase
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(Config::fromEnvironment()))->create();
    }

    public function testMigrationIsRepeatableAndCreatesCoreTables(): void
    {
        $migrator = new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations');

        $migrator->migrate();
        $secondRun = $migrator->migrate();

        self::assertSame([], $secondRun['applied']);
        self::assertContains('001_initial', $secondRun['skipped']);

        $statement = $this->connection->query("SHOW TABLES LIKE 'plays'");
        if ($statement === false) {
            self::fail('Unable to inspect migrated tables.');
        }
        $tables = $statement->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['plays'], $tables);

        $privatePlaylists = $this->connection->query('SELECT COUNT(*) FROM playlists WHERE is_public = 0');
        if ($privatePlaylists === false) {
            self::fail('Unable to inspect playlist visibility.');
        }
        self::assertSame(0, (int) $privatePlaylists->fetchColumn());

        $columnStatement = $this->connection->query("SHOW COLUMNS FROM playlists LIKE 'is_public'");
        if ($columnStatement === false) {
            self::fail('Unable to inspect playlist visibility default.');
        }
        $column = $columnStatement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($column);
        self::assertSame('1', $column['Default']);
    }

    public function testDiagnosticMigrationsRecoverAfterDdlWithoutRegistration(): void
    {
        $migrator = new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations');
        $migrator->migrate();
        $correlationId = 'migration-' . bin2hex(random_bytes(8));
        $songId = $this->createSong();
        $matchId = $this->createMatch($songId);
        $playlistId = $this->scalar("SELECT id FROM playlists WHERE name = 'SRF 3 - Top 50'");
        $runId = $this->createRun($playlistId, $correlationId);
        $this->createRunItem($runId, $songId, $matchId);

        try {
            $this->connection->exec(
                "DELETE FROM schema_migrations WHERE version IN ('008_sync_target_diagnostics', '009_playlist_target_count')",
            );

            $result = $migrator->migrate();

            self::assertContains('008_sync_target_diagnostics', $result['applied']);
            self::assertContains('009_playlist_target_count', $result['applied']);
            $query = $this->connection->prepare(
                'SELECT requested_count, track_count FROM sync_runs WHERE id = :id',
            );
            $query->execute(['id' => $runId]);
            self::assertSame([
                'requested_count' => 50,
                'track_count' => 1,
            ], $query->fetch(PDO::FETCH_ASSOC));

            $this->connection->exec("UPDATE playlists SET target_tracks = 7 WHERE id = {$playlistId}");
            $this->connection->exec(
                "UPDATE sync_runs SET requested_count = 7, track_count = 6 WHERE id = {$runId}",
            );
            $this->connection->exec(
                "DELETE FROM schema_migrations WHERE version IN ('008_sync_target_diagnostics', '009_playlist_target_count')",
            );

            $migrator->migrate();

            $query->execute(['id' => $runId]);
            self::assertSame([
                'requested_count' => 7,
                'track_count' => 6,
            ], $query->fetch(PDO::FETCH_ASSOC));
            self::assertSame(7, $this->scalar("SELECT target_tracks FROM playlists WHERE id = {$playlistId}"));
        } finally {
            $this->connection->prepare('DELETE FROM sync_runs WHERE id = :id')->execute(['id' => $runId]);
            $this->connection->prepare('DELETE FROM spotify_matches WHERE id = :id')->execute(['id' => $matchId]);
            $this->connection->prepare('DELETE FROM songs WHERE id = :id')->execute(['id' => $songId]);
            $this->connection->exec("UPDATE playlists SET target_tracks = 50 WHERE id = {$playlistId}");
            $migrator->migrate();
        }
    }

    private function createSong(): int
    {
        $query = $this->connection->prepare(
            "INSERT INTO songs (identity_hash, artist, title, normalized_artist, normalized_title) "
            . "VALUES (:hash, 'Migration Artist', 'Migration Song', 'migration artist', 'migration song')",
        );
        $query->execute(['hash' => random_bytes(32)]);

        return (int) $this->connection->lastInsertId();
    }

    private function createMatch(int $songId): int
    {
        $query = $this->connection->prepare(
            "INSERT INTO spotify_matches (song_id, spotify_track_id, spotify_uri, match_source, status) "
            . "VALUES (:song_id, 'migration-track', 'spotify:track:migration-track', 'manual', 'accepted')",
        );
        $query->execute(['song_id' => $songId]);

        return (int) $this->connection->lastInsertId();
    }

    private function createRun(int $playlistId, string $correlationId): int
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO sync_runs (
                    playlist_id, correlation_id, trigger_type, status,
                    window_from_utc, window_to_utc, finished_at
                ) VALUES (
                    :playlist_id, :correlation_id, 'manual', 'succeeded',
                    '2026-09-01', '2026-09-02', CURRENT_TIMESTAMP(6)
                )
                SQL,
        );
        $query->execute(['playlist_id' => $playlistId, 'correlation_id' => $correlationId]);

        return (int) $this->connection->lastInsertId();
    }

    private function createRunItem(int $runId, int $songId, int $matchId): void
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO sync_run_items (
                    sync_run_id, position, song_id, spotify_match_id, spotify_track_id, play_count
                ) VALUES (
                    :run_id, 0, :song_id, :match_id, 'migration-track', 1
                )
                SQL,
        );
        $query->execute(['run_id' => $runId, 'song_id' => $songId, 'match_id' => $matchId]);
    }

    private function scalar(string $sql): int
    {
        $query = $this->connection->query($sql);
        if ($query === false) {
            self::fail('Unable to read migration fixture dependency.');
        }

        return (int) $query->fetchColumn();
    }
}
