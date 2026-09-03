<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Import\ImportService;
use App\Application\Ranking\RankingService;
use App\Application\Spotify\MatchDecision;
use App\Application\Spotify\MatchingEngine;
use App\Application\Spotify\MatchingService;
use App\Application\Spotify\PlaylistSyncService;
use App\Domain\RadioPlay;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\ImportRepository;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Database\PlaylistRepository;
use App\Infrastructure\Database\RankingRepository;
use App\Infrastructure\Database\SpotifyMatchRepository;
use App\Infrastructure\Spotify\SpotifyTrack;
use App\Support\Config;
use App\Support\JsonLogger;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeSpotifyGateway;
use Tests\Fakes\StaticSrfSource;

#[CoversClass(PlaylistSyncService::class)]
#[CoversClass(MatchingService::class)]
#[CoversClass(SpotifyMatchRepository::class)]
#[CoversClass(PlaylistRepository::class)]
final class PlaylistSyncServiceTest extends TestCase
{
    private const CHANNEL_ID = 'dd0fa1ba-4ff6-4e1a-ab74-d7e49057d96f';

    private PDO $connection;
    private string $syncLogPath;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(Config::fromEnvironment()))->create();
        (new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->syncLogPath = sys_get_temp_dir() . '/srf3spotify-sync-' . bin2hex(random_bytes(8)) . '.log';
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        @unlink($this->syncLogPath);
    }

    public function testCreatesAndSynchronizesRankedUniqueTracks(): void
    {
        $now = new DateTimeImmutable('2020-01-03T12:00:00+01:00');
        $this->importTestPlays($now);
        $spotify = new FakeSpotifyGateway();
        $spotify->searchResults = [
            'Song A|Artist A' => [$this->track('track000001', 'Song A', 'Artist A')],
            'Song B|Artist B' => [$this->track('track000002', 'Song B', 'Artist B')],
        ];
        $matchRepository = new SpotifyMatchRepository($this->connection);
        $service = new PlaylistSyncService(
            new RankingService(new RankingRepository($this->connection), new DateTimeZone('Europe/Zurich')),
            new MatchingService($spotify, new MatchingEngine(), $matchRepository),
            $matchRepository,
            new PlaylistRepository($this->connection),
            $spotify,
            new AdvisoryLock($this->connection),
            new JsonLogger($this->syncLogPath),
            new DateTimeZone('Europe/Zurich'),
        );

        $result = $service->synchronize('manual', $now);

        self::assertSame(2, $result->trackCount);
        self::assertSame(0, $result->unresolvedCount);
        self::assertSame(1, $spotify->createdPlaylists);
        self::assertSame([
            'spotify:track:track000001',
            'spotify:track:track000002',
        ], $spotify->replacements[0]);
        self::assertSame(2, (int) $this->fetchValue('SELECT COUNT(*) FROM sync_run_items'));
        self::assertSame(
            'fake-playlist-id',
            $this->fetchValue('SELECT spotify_playlist_id FROM playlists ORDER BY id LIMIT 1'),
        );
        $lines = file($this->syncLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        $record = json_decode((string) end($lines), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('spotify.sync.succeeded', $record['event']);
        self::assertSame('succeeded', $record['context']['status']);
        self::assertSame($result->correlationId, $record['context']['correlation_id']);
        self::assertSame(2, $record['context']['track_count']);
        self::assertIsInt($record['context']['duration_ms']);
    }

    public function testRecreatesDeletedConfiguredPlaylist(): void
    {
        $now = new DateTimeImmutable('2020-01-03T12:00:00+01:00');
        $this->importTestPlays($now);
        $this->connection->exec(
            "UPDATE playlists SET spotify_playlist_id = 'deleted-playlist-id', spotify_owner_id = 'fake-owner-id'",
        );
        $spotify = new FakeSpotifyGateway();
        $spotify->playlistExistence['deleted-playlist-id'] = false;
        $spotify->searchResults = [
            'Song A|Artist A' => [$this->track('track000001', 'Song A', 'Artist A')],
            'Song B|Artist B' => [$this->track('track000002', 'Song B', 'Artist B')],
        ];
        $matchRepository = new SpotifyMatchRepository($this->connection);
        $service = new PlaylistSyncService(
            new RankingService(new RankingRepository($this->connection), new DateTimeZone('Europe/Zurich')),
            new MatchingService($spotify, new MatchingEngine(), $matchRepository),
            $matchRepository,
            new PlaylistRepository($this->connection),
            $spotify,
            new AdvisoryLock($this->connection),
            new JsonLogger($this->syncLogPath),
            new DateTimeZone('Europe/Zurich'),
        );

        $result = $service->synchronize('manual', $now);

        self::assertSame(['deleted-playlist-id'], $spotify->playlistExistenceChecks);
        self::assertSame(1, $spotify->createdPlaylists);
        self::assertSame('fake-playlist-id', $result->playlistId);
        self::assertSame(['fake-playlist-id'], $spotify->replacementPlaylistIds);
        self::assertSame(
            'fake-playlist-id',
            $this->fetchValue('SELECT spotify_playlist_id FROM playlists ORDER BY id LIMIT 1'),
        );
    }

    public function testManualMatchCannotBeOverwrittenAutomatically(): void
    {
        $now = new DateTimeImmutable('2020-01-03T12:00:00+01:00');
        $this->importTestPlays($now);
        $songId = (int) $this->fetchValue("SELECT id FROM songs WHERE title = 'Song A'");
        $repository = new SpotifyMatchRepository($this->connection);
        $manual = $this->track('track000009', 'Manual Song', 'Manual Artist');

        $repository->saveManualTrack($songId, $manual);
        $repository->saveAutomatic(
            $songId,
            new MatchDecision('accepted', $this->track('track000001', 'Song A', 'Artist A'), 1.0, 0.0),
        );

        $stored = $repository->find($songId);
        self::assertNotNull($stored);
        self::assertSame('manual', $stored->source);
        self::assertSame('track000009', $stored->trackId);
    }

    public function testManualSpotifyUrlSelectionAndRejection(): void
    {
        $now = new DateTimeImmutable('2020-01-03T12:00:00+01:00');
        $this->importTestPlays($now);
        $songId = (int) $this->fetchValue("SELECT id FROM songs WHERE title = 'Song B'");
        $spotify = new FakeSpotifyGateway();
        $spotify->tracks['track000002'] = $this->track('track000002', 'Song B', 'Artist B');
        $service = new MatchingService(
            $spotify,
            new MatchingEngine(),
            new SpotifyMatchRepository($this->connection),
        );

        $selected = $service->selectManualTrack(
            $songId,
            'https://open.spotify.com/track/track000002?si=test',
        );
        $rejected = $service->reject($songId);

        self::assertSame('accepted', $selected->status);
        self::assertSame('track000002', $selected->trackId);
        self::assertSame('manual', $rejected->source);
        self::assertSame('rejected', $rejected->status);
        self::assertNull($rejected->trackId);
    }

    private function importTestPlays(DateTimeImmutable $now): void
    {
        $plays = [
            $this->play('2020-01-01T10:00:00Z', 'Artist A', 'Song A'),
            $this->play('2020-01-01T11:00:00Z', 'Artist A', 'Song A'),
            $this->play('2020-01-01T12:00:00Z', 'Artist B', 'Song B'),
        ];
        (new ImportService(
            new StaticSrfSource($plays),
            new ImportRepository($this->connection),
            new AdvisoryLock($this->connection),
            new JsonLogger(sys_get_temp_dir() . '/srf3spotify-sync-import-test.log'),
            self::CHANNEL_ID,
            new DateTimeZone('Europe/Zurich'),
        ))->import('2020-01-01', '2020-01-01', 'manual', $now);
    }

    private function play(string $date, string $artist, string $title): RadioPlay
    {
        return new RadioPlay(
            new DateTimeImmutable($date, new DateTimeZone('UTC')),
            60,
            180_000,
            $artist,
            $title,
            false,
        );
    }

    private function track(string $id, string $title, string $artist): SpotifyTrack
    {
        return new SpotifyTrack($id, 'spotify:track:' . $id, $title, [$artist], 180_000);
    }

    private function fetchValue(string $sql): mixed
    {
        $statement = $this->connection->query($sql);
        if ($statement === false) {
            self::fail('Unable to execute test query.');
        }

        return $statement->fetchColumn();
    }

    private function cleanup(): void
    {
        $this->connection->exec('DELETE FROM sync_run_items');
        $this->connection->exec('DELETE FROM sync_runs');
        $this->connection->exec('DELETE FROM spotify_matches');
        $this->connection->exec("DELETE FROM plays WHERE played_at_utc >= '2020-01-01' AND played_at_utc < '2020-01-03'");
        $this->connection->exec("DELETE FROM import_runs WHERE range_from_utc >= '2019-12-31' AND range_from_utc < '2020-01-03'");
        $this->connection->exec('DELETE FROM songs WHERE NOT EXISTS (SELECT 1 FROM plays WHERE plays.song_id = songs.id)');
        $this->connection->exec(
            "UPDATE playlists SET spotify_playlist_id = NULL, spotify_owner_id = NULL,
             ranking_days = 30, max_tracks = 50, is_public = 0",
        );
    }
}
