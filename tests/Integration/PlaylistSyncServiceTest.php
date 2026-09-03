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
use RuntimeException;
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
        self::assertSame(2, $result->playlistCount);
        self::assertSame(2, $result->totalTrackCount);
        self::assertSame(0, $result->totalUnresolvedCount);
        self::assertSame(2, $spotify->createdPlaylists);
        self::assertSame(['SRF 3 - Top 50', 'SRF 3 - Der Morgen'], $spotify->createdPlaylistNames);
        $configurations = (new PlaylistRepository($this->connection))->configurations();
        self::assertCount(2, $configurations);
        self::assertSame('SRF 3 - Der Morgen', $configurations[1]->name);
        self::assertSame(30, $configurations[1]->rankingDays);
        self::assertSame(50, $configurations[1]->maxTracks);
        self::assertTrue($configurations[1]->rankingFilter->weekdaysOnly);
        self::assertSame(360, $configurations[1]->rankingFilter->localStartMinute);
        self::assertSame(600, $configurations[1]->rankingFilter->localEndMinute);
        self::assertFalse($configurations[1]->public);
        self::assertSame([
            'spotify:track:track000001',
            'spotify:track:track000002',
        ], $spotify->replacements[0]);
        self::assertSame([], $spotify->replacements[1]);
        self::assertSame(2, (int) $this->fetchValue('SELECT COUNT(*) FROM sync_run_items'));
        self::assertSame(2, (int) $this->fetchValue('SELECT COUNT(*) FROM sync_runs'));
        self::assertSame(
            'fake-playlist-id',
            $this->fetchValue('SELECT spotify_playlist_id FROM playlists ORDER BY id LIMIT 1'),
        );
        $lines = file($this->syncLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        $top50Record = json_decode($lines[0], true, 32, JSON_THROW_ON_ERROR);
        $morningRecord = json_decode($lines[1], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('spotify.sync.succeeded', $top50Record['event']);
        self::assertSame('succeeded', $top50Record['context']['status']);
        self::assertSame($result->correlationId, $top50Record['context']['correlation_id']);
        self::assertSame(2, $top50Record['context']['track_count']);
        self::assertSame('SRF 3 - Der Morgen', $morningRecord['context']['name']);
        self::assertSame(0, $morningRecord['context']['track_count']);
        self::assertIsInt($morningRecord['context']['duration_ms']);
    }

    public function testSynchronizesFilteredRankingToMorningPlaylist(): void
    {
        $now = new DateTimeImmutable('2020-01-06T12:00:00+01:00');
        $this->importPlays([
            $this->play('2020-01-04T05:00:00Z', 'Artist W', 'Weekend Song'),
            $this->play('2020-01-04T05:15:00Z', 'Artist W', 'Weekend Song'),
            $this->play('2020-01-04T05:30:00Z', 'Artist W', 'Weekend Song'),
            $this->play('2020-01-04T05:45:00Z', 'Artist W', 'Weekend Song'),
            $this->play('2020-01-01T10:00:00Z', 'Artist O', 'Outside Morning'),
            $this->play('2020-01-02T10:00:00Z', 'Artist O', 'Outside Morning'),
            $this->play('2020-01-03T10:00:00Z', 'Artist O', 'Outside Morning'),
            $this->play('2020-01-01T05:00:00Z', 'Artist M', 'Morning Song'),
            $this->play('2020-01-03T08:59:59Z', 'Artist M', 'Morning Song'),
        ], '2020-01-01', '2020-01-04', $now);
        $spotify = new FakeSpotifyGateway();
        $spotify->searchResults = [
            'Weekend Song|Artist W' => [$this->track('track000003', 'Weekend Song', 'Artist W')],
            'Outside Morning|Artist O' => [$this->track('track000002', 'Outside Morning', 'Artist O')],
            'Morning Song|Artist M' => [$this->track('track000001', 'Morning Song', 'Artist M')],
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

        self::assertSame(2, $result->playlistCount);
        self::assertSame(3, $result->trackCount);
        self::assertSame(4, $result->totalTrackCount);
        self::assertSame([
            [
                'spotify:track:track000003',
                'spotify:track:track000002',
                'spotify:track:track000001',
            ],
            ['spotify:track:track000001'],
        ], $spotify->replacements);
        self::assertSame('SRF 3 - Der Morgen', $result->playlists[1]->name);
        self::assertSame(1, $result->playlists[1]->trackCount);
        $serialized = $result->toArray();
        self::assertSame(3, $serialized['track_count']);
        self::assertSame(4, $serialized['total_track_count']);
        self::assertIsArray($serialized['playlists']);
        self::assertCount(2, $serialized['playlists']);
        $serializedMorning = $serialized['playlists'][1];
        self::assertSame('SRF 3 - Der Morgen', $serializedMorning['name']);
    }

    public function testRecreatesDeletedConfiguredPlaylist(): void
    {
        $now = new DateTimeImmutable('2020-01-03T12:00:00+01:00');
        $this->importTestPlays($now);
        $this->connection->exec(
            "UPDATE playlists SET spotify_playlist_id = 'deleted-playlist-id', spotify_owner_id = 'fake-owner-id' "
            . "WHERE name = 'SRF 3 - Top 50'",
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
        self::assertSame(2, $spotify->createdPlaylists);
        self::assertSame('fake-playlist-id', $result->playlistId);
        self::assertSame(['fake-playlist-id', 'fake-playlist-id-2'], $spotify->replacementPlaylistIds);
        self::assertSame(
            'fake-playlist-id',
            $this->fetchValue('SELECT spotify_playlist_id FROM playlists ORDER BY id LIMIT 1'),
        );
    }

    public function testAttemptsMorningPlaylistWhenTop50SynchronizationFails(): void
    {
        $now = new DateTimeImmutable('2020-01-03T12:00:00+01:00');
        $this->importTestPlays($now);
        $spotify = new FakeSpotifyGateway();
        $spotify->replacementFailures['fake-playlist-id'] = new RuntimeException('Top 50 write failed.');
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

        try {
            $service->synchronize('manual', $now);
            self::fail('Expected the Top 50 synchronization to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Top 50 write failed.', $exception->getMessage());
        }

        self::assertSame(['fake-playlist-id', 'fake-playlist-id-2'], $spotify->replacementPlaylistIds);
        $query = $this->connection->query(
            'SELECT p.name, sr.status FROM sync_runs sr INNER JOIN playlists p ON p.id = sr.playlist_id ORDER BY p.id',
        );
        self::assertNotFalse($query);
        self::assertSame([
            ['name' => 'SRF 3 - Top 50', 'status' => 'failed'],
            ['name' => 'SRF 3 - Der Morgen', 'status' => 'succeeded'],
        ], $query->fetchAll(PDO::FETCH_ASSOC));
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
        $this->importPlays([
            $this->play('2020-01-01T10:00:00Z', 'Artist A', 'Song A'),
            $this->play('2020-01-01T11:00:00Z', 'Artist A', 'Song A'),
            $this->play('2020-01-01T12:00:00Z', 'Artist B', 'Song B'),
        ], '2020-01-01', '2020-01-01', $now);
    }

    /** @param list<RadioPlay> $plays */
    private function importPlays(array $plays, string $fromDate, string $toDate, DateTimeImmutable $now): void
    {
        (new ImportService(
            new StaticSrfSource($plays),
            new ImportRepository($this->connection),
            new AdvisoryLock($this->connection),
            new JsonLogger(sys_get_temp_dir() . '/srf3spotify-sync-import-test.log'),
            self::CHANNEL_ID,
            new DateTimeZone('Europe/Zurich'),
        ))->import($fromDate, $toDate, 'manual', $now);
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
        $this->connection->exec("DELETE FROM plays WHERE played_at_utc >= '2020-01-01' AND played_at_utc < '2020-01-06'");
        $this->connection->exec("DELETE FROM import_runs WHERE range_from_utc >= '2019-12-31' AND range_from_utc < '2020-01-06'");
        $this->connection->exec('DELETE FROM songs WHERE NOT EXISTS (SELECT 1 FROM plays WHERE plays.song_id = songs.id)');
        $this->connection->exec(
            "UPDATE playlists SET spotify_playlist_id = NULL, spotify_owner_id = NULL,
             ranking_days = 30, max_tracks = 50, is_public = 0",
        );
    }
}
