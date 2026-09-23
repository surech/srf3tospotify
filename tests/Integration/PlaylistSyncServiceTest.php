<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Import\ImportService;
use App\Application\Ranking\RankingService;
use App\Application\Spotify\MatchDecision;
use App\Application\Spotify\MatchingEngine;
use App\Application\Spotify\MatchingService;
use App\Application\Spotify\PlaylistSyncService;
use App\Application\Spotify\PlaylistTargetService;
use App\Application\Spotify\SongIgnoreService;
use App\Domain\RadioPlay;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\ImportRepository;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Database\PlaylistRepository;
use App\Infrastructure\Database\RankingRepository;
use App\Infrastructure\Database\SongIgnoreRepository;
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
        $top50Cover = "\xFF\xD8top-50-cover\xFF\xD9";
        $morningCover = "\xFF\xD8morning-cover\xFF\xD9";
        $musiktagCover = "\xFF\xD8musiktag-cover\xFF\xD9";
        $service = $this->playlistSyncService($spotify, [
            'SRF 3 - Top 50' => $top50Cover,
            'SRF 3 - Der Morgen' => $morningCover,
            'SRF 3 - Schweizer Musiktag 2026' => $musiktagCover,
        ]);

        $result = $service->synchronize('manual', $now);

        self::assertSame(2, $result->trackCount);
        self::assertSame(0, $result->unresolvedCount);
        self::assertSame(3, $result->playlistCount);
        self::assertSame(2, $result->totalTrackCount);
        self::assertSame(0, $result->totalUnresolvedCount);
        self::assertSame(3, $spotify->createdPlaylists);
        self::assertSame([
            'SRF 3 - Top 50',
            'SRF 3 - Der Morgen',
            'SRF 3 - Schweizer Musiktag 2026',
        ], $spotify->createdPlaylistNames);
        self::assertSame([true, true, true], $spotify->createdPlaylistPublicStates);
        self::assertSame([
            ['playlist_id' => 'fake-playlist-id', 'jpeg' => $top50Cover],
            ['playlist_id' => 'fake-playlist-id-2', 'jpeg' => $morningCover],
            ['playlist_id' => 'fake-playlist-id-3', 'jpeg' => $musiktagCover],
        ], $spotify->coverUploads);
        $configurations = (new PlaylistRepository($this->connection))->configurations();
        self::assertCount(3, $configurations);
        self::assertSame('SRF 3 - Der Morgen', $configurations[1]->name);
        self::assertSame(30, $configurations[1]->rankingDays);
        self::assertSame(50, $configurations[1]->maxTracks);
        self::assertSame(50, $configurations[1]->targetTracks);
        self::assertTrue($configurations[1]->rankingFilter->weekdaysOnly);
        self::assertSame(360, $configurations[1]->rankingFilter->localStartMinute);
        self::assertSame(600, $configurations[1]->rankingFilter->localEndMinute);
        self::assertTrue($configurations[1]->public);
        self::assertSame('SRF 3 - Schweizer Musiktag 2026', $configurations[2]->name);
        self::assertSame(500, $configurations[2]->maxTracks);
        self::assertNull($configurations[2]->targetTracks);
        self::assertSame('2026-09-17T03:00:00+00:00', $configurations[2]->fixedFromUtc?->format(DATE_ATOM));
        self::assertSame('2026-09-17T22:00:00+00:00', $configurations[2]->fixedToUtcExclusive?->format(DATE_ATOM));
        self::assertSame([
            'spotify:track:track000001',
            'spotify:track:track000002',
        ], $spotify->replacements[0]);
        self::assertSame([], $spotify->replacements[1]);
        self::assertSame([], $spotify->replacements[2]);
        self::assertSame(2, (int) $this->fetchValue('SELECT COUNT(*) FROM sync_run_items'));
        self::assertSame(3, (int) $this->fetchValue('SELECT COUNT(*) FROM sync_runs'));
        self::assertSame(
            'fake-playlist-id',
            $this->fetchValue('SELECT spotify_playlist_id FROM playlists ORDER BY id LIMIT 1'),
        );
        $lines = file($this->syncLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount(3, $lines);
        $top50Record = json_decode($lines[0], true, 32, JSON_THROW_ON_ERROR);
        $morningRecord = json_decode($lines[1], true, 32, JSON_THROW_ON_ERROR);
        $musiktagRecord = json_decode($lines[2], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('spotify.sync.succeeded', $top50Record['event']);
        self::assertSame('succeeded', $top50Record['context']['status']);
        self::assertSame($result->correlationId, $top50Record['context']['correlation_id']);
        self::assertSame(2, $top50Record['context']['track_count']);
        self::assertSame('SRF 3 - Der Morgen', $morningRecord['context']['name']);
        self::assertSame(0, $morningRecord['context']['track_count']);
        self::assertIsInt($morningRecord['context']['duration_ms']);
        self::assertSame('SRF 3 - Schweizer Musiktag 2026', $musiktagRecord['context']['name']);
        self::assertSame(0, $musiktagRecord['context']['track_count']);
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
        $service = $this->playlistSyncService($spotify);

        $result = $service->synchronize('manual', $now);

        self::assertSame(3, $result->playlistCount);
        self::assertSame(3, $result->trackCount);
        self::assertSame(4, $result->totalTrackCount);
        self::assertSame([
            [
                'spotify:track:track000003',
                'spotify:track:track000002',
                'spotify:track:track000001',
            ],
            ['spotify:track:track000001'],
            [],
        ], $spotify->replacements);
        self::assertSame('SRF 3 - Der Morgen', $result->playlists[1]->name);
        self::assertSame(1, $result->playlists[1]->trackCount);
        $serialized = $result->toArray();
        self::assertSame(3, $serialized['track_count']);
        self::assertSame(4, $serialized['total_track_count']);
        self::assertIsArray($serialized['playlists']);
        self::assertCount(3, $serialized['playlists']);
        $serializedMorning = $serialized['playlists'][1];
        self::assertSame('SRF 3 - Der Morgen', $serializedMorning['name']);
    }

    public function testUpdatesVisibilityOfExistingPlaylists(): void
    {
        $this->connection->exec(
            "UPDATE playlists SET spotify_playlist_id = CASE name
                WHEN 'SRF 3 - Top 50' THEN 'existing-top-50'
                WHEN 'SRF 3 - Der Morgen' THEN 'existing-morning'
                WHEN 'SRF 3 - Schweizer Musiktag 2026' THEN 'existing-musiktag'
             END, spotify_owner_id = 'fake-owner-id'",
        );
        $spotify = new FakeSpotifyGateway();
        $service = $this->playlistSyncService($spotify);

        $service->synchronize('manual', new DateTimeImmutable('2020-01-03T12:00:00+01:00'));

        self::assertSame(0, $spotify->createdPlaylists);
        self::assertSame([
            'existing-top-50',
            'existing-morning',
            'existing-musiktag',
        ], $spotify->playlistExistenceChecks);
        self::assertSame([
            ['playlist_id' => 'existing-top-50', 'public' => true],
            ['playlist_id' => 'existing-morning', 'public' => true],
            ['playlist_id' => 'existing-musiktag', 'public' => true],
        ], $spotify->visibilityUpdates);
    }

    public function testSynchronizesSchweizerMusiktagWithinFixedSwissWindow(): void
    {
        $now = new DateTimeImmutable('2026-09-22T12:00:00+02:00');
        $this->importPlays([
            $this->play('2026-09-17T02:59:59Z', 'Artist Before', 'Before Window', 120),
            $this->play('2026-09-17T03:00:00Z', 'Artist Opening', 'Opening Song', 120),
            $this->play('2026-09-17T21:59:59Z', 'Artist Closing', 'Closing Song', 120),
            $this->play('2026-09-17T22:00:00Z', 'Artist After', 'After Window', 120),
        ], '2026-09-17', '2026-09-17', $now);
        $spotify = new FakeSpotifyGateway();
        $spotify->searchResults = [
            'Before Window|Artist Before' => [$this->track('track-before', 'Before Window', 'Artist Before')],
            'Opening Song|Artist Opening' => [$this->track('track-opening', 'Opening Song', 'Artist Opening')],
            'Closing Song|Artist Closing' => [$this->track('track-closing', 'Closing Song', 'Artist Closing')],
            'After Window|Artist After' => [$this->track('track-after', 'After Window', 'Artist After')],
        ];
        $service = $this->playlistSyncService($spotify);

        $result = $service->synchronize('manual', $now);

        self::assertSame(3, $result->playlistCount);
        self::assertSame('SRF 3 - Schweizer Musiktag 2026', $result->playlists[2]->name);
        self::assertSame(2, $result->playlists[2]->trackCount);
        self::assertSame([
            'spotify:track:track-closing',
            'spotify:track:track-opening',
        ], $spotify->replacements[2]);
        $query = $this->connection->query(
            "SELECT sr.window_from_utc, sr.window_to_utc
             FROM sync_runs sr
             INNER JOIN playlists p ON p.id = sr.playlist_id
             WHERE p.name = 'SRF 3 - Schweizer Musiktag 2026'",
        );
        self::assertNotFalse($query);
        self::assertSame([
            'window_from_utc' => '2026-09-17 03:00:00.000000',
            'window_to_utc' => '2026-09-17 22:00:00.000000',
        ], $query->fetch(PDO::FETCH_ASSOC));
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
        $service = $this->playlistSyncService($spotify);

        $result = $service->synchronize('manual', $now);

        self::assertSame(['deleted-playlist-id'], $spotify->playlistExistenceChecks);
        self::assertSame(3, $spotify->createdPlaylists);
        self::assertSame('fake-playlist-id', $result->playlistId);
        self::assertSame([
            'fake-playlist-id',
            'fake-playlist-id-2',
            'fake-playlist-id-3',
        ], $spotify->replacementPlaylistIds);
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
        $service = $this->playlistSyncService($spotify);

        try {
            $service->synchronize('manual', $now);
            self::fail('Expected the Top 50 synchronization to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Top 50 write failed.', $exception->getMessage());
        }

        self::assertSame([
            'fake-playlist-id',
            'fake-playlist-id-2',
            'fake-playlist-id-3',
        ], $spotify->replacementPlaylistIds);
        $query = $this->connection->query(
            'SELECT p.name, sr.status FROM sync_runs sr INNER JOIN playlists p ON p.id = sr.playlist_id ORDER BY p.id',
        );
        self::assertNotFalse($query);
        self::assertSame([
            ['name' => 'SRF 3 - Top 50', 'status' => 'failed'],
            ['name' => 'SRF 3 - Der Morgen', 'status' => 'succeeded'],
            ['name' => 'SRF 3 - Schweizer Musiktag 2026', 'status' => 'succeeded'],
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

    public function testIgnoredLeaderIsReplacedByNextEligibleSong(): void
    {
        $now = new DateTimeImmutable('2020-01-03T12:00:00+01:00');
        $this->importPlays([
            $this->play('2020-01-01T10:00:00Z', 'Artist A', 'Ignored Leader'),
            $this->play('2020-01-01T11:00:00Z', 'Artist A', 'Ignored Leader'),
            $this->play('2020-01-01T12:00:00Z', 'Artist A', 'Ignored Leader'),
            $this->play('2020-01-01T13:00:00Z', 'Artist B', 'First Eligible'),
            $this->play('2020-01-01T14:00:00Z', 'Artist B', 'First Eligible'),
            $this->play('2020-01-01T15:00:00Z', 'Artist D', 'Must Not Overflow'),
            $this->play('2020-01-01T16:00:00Z', 'Artist C', 'Backfill'),
        ], '2020-01-01', '2020-01-01', $now);
        $playlistId = (int) $this->fetchValue("SELECT id FROM playlists WHERE name = 'SRF 3 - Top 50'");
        $ignoredSongId = (int) $this->fetchValue("SELECT id FROM songs WHERE title = 'Ignored Leader'");
        $this->connection->exec(
            "UPDATE playlists SET max_tracks = 5, target_tracks = 2 WHERE name = 'SRF 3 - Top 50'",
        );
        (new SongIgnoreService(new SongIgnoreRepository($this->connection)))
            ->ignore($ignoredSongId, $playlistId, 'Test exclusion');
        $spotify = new FakeSpotifyGateway();
        $spotify->searchResults = [
            'First Eligible|Artist B' => [$this->track('track-first', 'First Eligible', 'Artist B')],
            'Backfill|Artist C' => [$this->track('track-backfill', 'Backfill', 'Artist C')],
            'Must Not Overflow|Artist D' => [$this->track('track-overflow', 'Must Not Overflow', 'Artist D')],
        ];

        $result = $this->playlistSyncService($spotify)->synchronize('manual', $now);

        self::assertSame(2, $result->trackCount);
        self::assertSame([
            'spotify:track:track-first',
            'spotify:track:track-backfill',
        ], $spotify->replacements[0]);
    }

    public function testDuplicateSpotifyTrackIsReplacedByNextUniqueSong(): void
    {
        $now = new DateTimeImmutable('2020-01-03T12:00:00+01:00');
        $this->importPlays([
            $this->play('2020-01-01T10:00:00Z', 'Artist A', 'First Alias'),
            $this->play('2020-01-01T11:00:00Z', 'Artist A', 'First Alias'),
            $this->play('2020-01-01T12:00:00Z', 'Artist A', 'First Alias'),
            $this->play('2020-01-01T13:00:00Z', 'Artist B', 'Second Alias'),
            $this->play('2020-01-01T14:00:00Z', 'Artist B', 'Second Alias'),
            $this->play('2020-01-01T15:00:00Z', 'Artist C', 'Unique Backfill'),
        ], '2020-01-01', '2020-01-01', $now);
        $this->connection->exec(
            "UPDATE playlists SET max_tracks = 2, target_tracks = 2 WHERE name = 'SRF 3 - Top 50'",
        );
        $spotify = new FakeSpotifyGateway();
        $spotify->searchResults = [
            'First Alias|Artist A' => [$this->track('track-shared', 'First Alias', 'Artist A')],
            'Second Alias|Artist B' => [$this->track('track-shared', 'Second Alias', 'Artist B')],
            'Unique Backfill|Artist C' => [$this->track('track-unique', 'Unique Backfill', 'Artist C')],
        ];

        $result = $this->playlistSyncService($spotify)->synchronize('manual', $now);

        self::assertSame([
            'spotify:track:track-shared',
            'spotify:track:track-unique',
        ], $spotify->replacements[0]);
        self::assertSame(1, $result->playlists[0]->duplicateTrackCount);
        self::assertFalse($result->playlists[0]->hasWarning);
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

    private function play(
        string $date,
        string $artist,
        string $title,
        int $sourceOffsetMinutes = 60,
    ): RadioPlay {
        return new RadioPlay(
            new DateTimeImmutable($date, new DateTimeZone('UTC')),
            $sourceOffsetMinutes,
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

    /** @param array<string, string> $coverImages */
    private function playlistSyncService(FakeSpotifyGateway $spotify, array $coverImages = []): PlaylistSyncService
    {
        $matchRepository = new SpotifyMatchRepository($this->connection);

        return new PlaylistSyncService(
            new PlaylistTargetService(
                new RankingService(new RankingRepository($this->connection), new DateTimeZone('Europe/Zurich')),
                $matchRepository,
                new SongIgnoreRepository($this->connection),
            ),
            new MatchingService($spotify, new MatchingEngine(), $matchRepository),
            new PlaylistRepository($this->connection),
            $spotify,
            new AdvisoryLock($this->connection),
            new JsonLogger($this->syncLogPath),
            new DateTimeZone('Europe/Zurich'),
            $coverImages,
        );
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
        $this->connection->exec('DELETE FROM song_ignore_rules');
        $this->connection->exec('DELETE FROM spotify_matches');
        $this->connection->exec("DELETE FROM plays WHERE played_at_utc >= '2020-01-01' AND played_at_utc < '2020-01-06'");
        $this->connection->exec("DELETE FROM plays WHERE played_at_utc >= '2026-09-17' AND played_at_utc < '2026-09-18'");
        $this->connection->exec("DELETE FROM import_runs WHERE range_from_utc >= '2019-12-31' AND range_from_utc < '2020-01-06'");
        $this->connection->exec("DELETE FROM import_runs WHERE range_from_utc >= '2026-09-16' AND range_from_utc < '2026-09-19'");
        $this->connection->exec('DELETE FROM songs WHERE NOT EXISTS (SELECT 1 FROM plays WHERE plays.song_id = songs.id)');
        $this->connection->exec(
            "UPDATE playlists SET spotify_playlist_id = NULL, spotify_owner_id = NULL,
             ranking_days = CASE WHEN name = 'SRF 3 - Schweizer Musiktag 2026' THEN 1 ELSE 30 END,
             max_tracks = CASE WHEN name = 'SRF 3 - Schweizer Musiktag 2026' THEN 500 ELSE 50 END,
             target_tracks = CASE WHEN name = 'SRF 3 - Schweizer Musiktag 2026' THEN NULL ELSE 50 END,
             is_public = 1",
        );
    }
}
