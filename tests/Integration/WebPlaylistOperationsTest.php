<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Import\ImportService;
use App\Application\Spotify\PlaylistTarget;
use App\ApplicationFactory;
use App\Domain\RadioPlay;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\DashboardRepository;
use App\Infrastructure\Database\ImportRepository;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Database\PlaylistRepository;
use App\Infrastructure\Spotify\SpotifyTrack;
use App\Support\Config;
use App\Support\JsonLogger;
use App\Web\DefaultWebOperations;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeSpotifyGateway;
use Tests\Fakes\StaticSrfSource;

#[CoversClass(DefaultWebOperations::class)]
final class WebPlaylistOperationsTest extends TestCase
{
    private const CHANNEL_ID = 'dd0fa1ba-4ff6-4e1a-ab74-d7e49057d96f';
    private const FALLBACK_PLAYLIST = 'AAA Playlist ohne Cover';

    private PDO $connection;
    private DefaultWebOperations $operations;
    private FakeSpotifyGateway $spotify;
    private string $logPath;

    protected function setUp(): void
    {
        $config = Config::fromEnvironment();
        $this->connection = (new ConnectionFactory($config))->create();
        (new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->logPath = sys_get_temp_dir() . '/srf3spotify-web-playlists-' . bin2hex(random_bytes(8)) . '.log';
        $this->cleanup();
        $this->connection->exec(
            "INSERT INTO playlists (name, description, ranking_days, max_tracks, is_public) "
            . "VALUES ('" . self::FALLBACK_PLAYLIST . "', 'Testbeschreibung', 30, 50, 0)",
        );
        $factory = new ApplicationFactory($config, \dirname(__DIR__, 2));
        $this->spotify = new FakeSpotifyGateway();
        $this->operations = new DefaultWebOperations(
            $factory,
            new DashboardRepository($factory->connection()),
            $this->spotify,
        );
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        @unlink($this->logPath);
    }

    public function testDashboardListsAllPlaylistsAlphabeticallyWithCoverFallback(): void
    {
        $dashboard = $this->operations->dashboard();
        $playlists = $dashboard['playlists'];
        self::assertIsArray($playlists);
        /** @var list<array<string, mixed>> $playlists */

        $names = array_column($playlists, 'name');
        $sortedNames = $names;
        sort($sortedNames, SORT_NATURAL | SORT_FLAG_CASE);
        self::assertSame($sortedNames, $names);
        self::assertContains(self::FALLBACK_PLAYLIST, $names);

        $fallback = $this->playlistByName($playlists, self::FALLBACK_PLAYLIST);
        self::assertNull($fallback['cover_url']);

        $top50 = $this->playlistByName($playlists, 'SRF 3 - Top 50');
        self::assertSame('/admin/playlists/' . $top50['id'] . '/cover', $top50['cover_url']);
        self::assertStringStartsWith("\x89PNG", (string) $this->operations->playlistCover($top50['id']));
    }

    public function testPublicHomepageUsesLatestEligibleSuccessfulSnapshot(): void
    {
        $playlistId = $this->configurationId('SRF 3 - Top 50');
        $this->createPublicSnapshot($playlistId, 'web-public-success', 'succeeded', 1);
        $this->createPublicSnapshot($playlistId, 'web-public-failed', 'failed', 1);

        $page = $this->operations->publicHomepage();

        self::assertCount(1, $page['playlists']);
        $playlist = $page['playlists'][0];
        self::assertSame('Öffentliche Playlist', $playlist['name']);
        self::assertSame('https://open.spotify.com/playlist/public-playlist', $playlist['spotify_url']);
        self::assertSame('/playlist-covers/' . $playlistId, $playlist['cover_url']);
        self::assertSame('24.09.2026, 12:00', $playlist['synced_at']);
        self::assertSame(1, $playlist['track_count']);
        self::assertSame([[
            'position' => 1,
            'title' => 'Snapshot Song',
            'artist' => 'Snapshot Artist',
            'spotify_url' => 'https://open.spotify.com/track/public-track',
        ]], $playlist['tracks']);
        self::assertStringStartsWith("\x89PNG", (string) $this->operations->publicPlaylistCover($playlistId));

        $this->createPublicSnapshot($playlistId, 'web-public-empty', 'succeeded', 0);
        self::assertSame([], $this->operations->publicHomepage()['playlists']);
        self::assertNull($this->operations->publicPlaylistCover($playlistId));

        $this->connection->exec("DELETE FROM sync_runs WHERE correlation_id = 'web-public-empty'");
        $this->connection->exec("UPDATE playlists SET is_public = 0 WHERE id = {$playlistId}");
        self::assertSame([], $this->operations->publicHomepage()['playlists']);
        self::assertNull($this->operations->publicPlaylistCover($playlistId));
    }

    public function testPlaylistDetailUsesFixedWindowAndReturnsNullForUnknownId(): void
    {
        $this->importMusicDayPlays();
        $musicDay = $this->configurationId('SRF 3 - Schweizer Musiktag 2026');

        $detail = $this->operations->playlist($musicDay);

        self::assertIsArray($detail);
        self::assertSame('SRF 3 - Schweizer Musiktag 2026', $detail['playlist']['name']);
        self::assertCount(0, $detail['ranking']);
        self::assertCount(1, $detail['skipped']);
        self::assertSame('Music Day Song', $detail['skipped'][0]['title']);
        self::assertSame(2, $detail['skipped'][0]['play_count']);
        self::assertSame('pending', $detail['skipped'][0]['match_status']);
        self::assertSame(1, $detail['skipped'][0]['airplay_rank']);
        self::assertSame(PlaylistTarget::SKIPPED_MISSING_MATCH, $detail['skipped'][0]['skip_reason']);
        self::assertSame(
            ['17.09.2026, 23:59', '17.09.2026, 05:00'],
            array_column($detail['skipped'][0]['play_times'], 'label'),
        );

        $reviewMatch = $this->connection->prepare(
            "INSERT INTO spotify_matches (song_id, spotify_track_id, spotify_uri, spotify_title, spotify_artist, "
            . "duration_ms, match_source, status, confidence) VALUES (:song_id, 'review-track', "
            . "'spotify:track:review-track', 'Review Song', 'Review Artist', 181000, 'automatic', 'review', 0.75)",
        );
        $reviewMatch->execute(['song_id' => $detail['skipped'][0]['song_id']]);
        $detailWithReview = $this->operations->playlist($musicDay);

        self::assertIsArray($detailWithReview);
        self::assertSame([], $detailWithReview['ranking']);
        self::assertSame('review-track', $detailWithReview['skipped'][0]['spotify_track_id']);
        self::assertSame('Review Song', $detailWithReview['skipped'][0]['spotify_title']);
        self::assertSame('review', $detailWithReview['skipped'][0]['match_status']);
        self::assertNull($this->operations->playlist(999_999));
        self::assertNull($this->operations->playlistCover(999_999));
    }

    public function testIgnoredSongIsHiddenImmediatelyAndHistoryIsRetained(): void
    {
        $this->importMusicDayPlays();
        $playlistId = $this->configurationId('SRF 3 - Schweizer Musiktag 2026');
        $songQuery = $this->connection->query("SELECT id FROM songs WHERE title = 'Music Day Song'");
        self::assertNotFalse($songQuery);
        $songId = (int) $songQuery->fetchColumn();

        $created = $this->operations->ignoreSong($songId, $playlistId, '  Nicht passend  ');
        $detail = $this->operations->playlist($playlistId);
        $active = $this->operations->ignoredSongs(false);

        self::assertSame('Nicht passend', $created['reason']);
        self::assertIsArray($detail);
        self::assertSame([], $detail['ranking']);
        self::assertSame([], $detail['skipped']);
        self::assertSame(1, $detail['target']['ignored_count']);
        self::assertSame(1, $active['song_count']);
        self::assertSame(1, $active['active_rule_count']);

        $this->operations->reactivateSong((int) $created['id']);
        $history = $this->operations->ignoredSongs(true);

        self::assertSame(0, $history['active_rule_count']);
        self::assertFalse($history['songs'][0]['rules'][0]['is_active']);
        self::assertNotNull($history['songs'][0]['rules'][0]['reactivated_at']);
    }

    public function testSpotifySearchReturnsMetadataAndConservativePagination(): void
    {
        $this->spotify->searchResults['Song|Artist'] = array_map(
            static fn(int $index): SpotifyTrack => new SpotifyTrack(
                \sprintf('track%06d', $index),
                \sprintf('spotify:track:track%06d', $index),
                'Song ' . $index,
                ['Artist', 'Guest'],
                180_000 + $index,
                'Album',
                '2024',
                'https://images.example/cover.jpg',
                'https://open.spotify.com/track/' . \sprintf('track%06d', $index),
            ),
            range(1, 10),
        );

        $result = $this->operations->searchSpotifyTracks(' Song ', ' Artist ', '20');

        self::assertSame(20, $result['offset']);
        self::assertSame(10, $result['limit']);
        self::assertTrue($result['has_more']);
        self::assertCount(10, $result['items']);
        self::assertSame([
            'id' => 'track000001',
            'title' => 'Song 1',
            'artists' => ['Artist', 'Guest'],
            'artist' => 'Artist, Guest',
            'album' => 'Album',
            'release_year' => '2024',
            'duration_ms' => 180_001,
            'image_url' => 'https://images.example/cover.jpg',
            'external_url' => 'https://open.spotify.com/track/track000001',
        ], $result['items'][0]);
        self::assertSame([
            ['title' => 'Song', 'artist' => 'Artist', 'offset' => 20],
        ], $this->spotify->searches);

        $lastPage = $this->operations->searchSpotifyTracks('Song', 'Artist', '1000');

        self::assertFalse($lastPage['has_more']);
    }

    public function testSpotifySearchAcceptsOneFieldAndValidatesInput(): void
    {
        $artistOnly = $this->operations->searchSpotifyTracks('', ' Artist ', '0');

        self::assertSame([], $artistOnly['items']);
        self::assertSame([
            ['title' => '', 'artist' => 'Artist', 'offset' => 0],
        ], $this->spotify->searches);

        foreach ([
            ['', '', '0'],
            [str_repeat('x', 201), '', '0'],
            ['Song', '', '-1'],
            ['Song', '', '999'],
            ['Song', '', '1001'],
            ['Song', '', 'invalid'],
        ] as [$title, $artist, $offset]) {
            try {
                $this->operations->searchSpotifyTracks($title, $artist, $offset);
                self::fail('Expected invalid Spotify search input to be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $playlists
     * @return array{id: int, name: string, description: string, cover_url: string|null}
     */
    private function playlistByName(array $playlists, string $name): array
    {
        foreach ($playlists as $playlist) {
            if (($playlist['name'] ?? null) === $name) {
                $coverUrl = $playlist['cover_url'] ?? null;

                return [
                    'id' => (int) ($playlist['id'] ?? 0),
                    'name' => (string) $playlist['name'],
                    'description' => (string) ($playlist['description'] ?? ''),
                    'cover_url' => \is_string($coverUrl) ? $coverUrl : null,
                ];
            }
        }

        self::fail('Playlist not found: ' . $name);
    }

    private function configurationId(string $name): int
    {
        foreach ((new PlaylistRepository($this->connection))->configurations() as $configuration) {
            if ($configuration->name === $name) {
                return $configuration->id;
            }
        }

        self::fail('Playlist configuration not found: ' . $name);
    }

    private function importMusicDayPlays(): void
    {
        $plays = [
            $this->play('2026-09-17T02:59:59Z'),
            $this->play('2026-09-17T03:00:00Z'),
            $this->play('2026-09-17T21:59:59Z'),
            $this->play('2026-09-17T22:00:00Z'),
        ];
        $service = new ImportService(
            new StaticSrfSource($plays),
            new ImportRepository($this->connection),
            new AdvisoryLock($this->connection),
            new JsonLogger($this->logPath),
            self::CHANNEL_ID,
            new DateTimeZone('Europe/Zurich'),
        );
        $service->import(
            '2026-09-17',
            '2026-09-17',
            'manual',
            new DateTimeImmutable('2026-09-18T12:00:00+02:00'),
        );
    }

    private function createPublicSnapshot(int $playlistId, string $correlationId, string $status, int $trackCount): void
    {
        $this->connection->exec(
            "UPDATE playlists SET spotify_playlist_id = 'public-playlist', spotify_owner_id = 'public-owner', "
            . "is_public = 1 WHERE id = {$playlistId}",
        );
        $run = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO sync_runs (
                    playlist_id, correlation_id, trigger_type, status, window_from_utc, window_to_utc,
                    spotify_snapshot_id, published_name, published_description,
                    published_spotify_playlist_id, published_public, track_count, finished_at
                ) VALUES (
                    :playlist_id, :correlation_id, 'manual', :status, '2026-09-01', '2026-09-24',
                    'public-snapshot', 'Öffentliche Playlist', 'Öffentliche Beschreibung',
                    'public-playlist', 1, :track_count, '2026-09-24 10:00:00'
                )
                SQL,
        );
        $run->execute([
            'playlist_id' => $playlistId,
            'correlation_id' => $correlationId,
            'status' => $status,
            'track_count' => $trackCount,
        ]);
        $runId = (int) $this->connection->lastInsertId();
        if ($trackCount === 0) {
            return;
        }
        $songId = $this->publicSongId();
        $matchId = $this->publicMatchId($songId);
        $item = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO sync_run_items (
                    sync_run_id, position, song_id, spotify_match_id, spotify_track_id,
                    spotify_title, spotify_artist, play_count
                ) VALUES (
                    :sync_run_id, 0, :song_id, :spotify_match_id, 'public-track',
                    'Snapshot Song', 'Snapshot Artist', 4
                )
                SQL,
        );
        $item->execute([
            'sync_run_id' => $runId,
            'song_id' => $songId,
            'spotify_match_id' => $matchId,
        ]);
    }

    private function publicSongId(): int
    {
        $query = $this->connection->query("SELECT id FROM songs WHERE title = 'Public Snapshot Song'");
        if ($query !== false && ($songId = $query->fetchColumn()) !== false) {
            return (int) $songId;
        }
        $insert = $this->connection->prepare(
            "INSERT INTO songs (identity_hash, artist, title, normalized_artist, normalized_title) "
            . "VALUES (:identity_hash, 'Public Snapshot Artist', 'Public Snapshot Song', "
            . "'public snapshot artist', 'public snapshot song')",
        );
        $insert->execute(['identity_hash' => random_bytes(32)]);

        return (int) $this->connection->lastInsertId();
    }

    private function publicMatchId(int $songId): int
    {
        $query = $this->connection->prepare('SELECT id FROM spotify_matches WHERE song_id = :song_id');
        $query->execute(['song_id' => $songId]);
        $matchId = $query->fetchColumn();
        if ($matchId !== false) {
            return (int) $matchId;
        }
        $insert = $this->connection->prepare(
            "INSERT INTO spotify_matches (song_id, spotify_track_id, spotify_uri, spotify_title, spotify_artist, "
            . "match_source, status) VALUES (:song_id, 'public-track', 'spotify:track:public-track', "
            . "'Snapshot Song', 'Snapshot Artist', 'manual', 'accepted')",
        );
        $insert->execute(['song_id' => $songId]);

        return (int) $this->connection->lastInsertId();
    }

    private function play(string $playedAt): RadioPlay
    {
        return new RadioPlay(
            new DateTimeImmutable($playedAt, new DateTimeZone('UTC')),
            120,
            180_000,
            'Music Day Artist',
            'Music Day Song',
            false,
        );
    }

    private function cleanup(): void
    {
        $this->connection->exec("DELETE FROM sync_runs WHERE correlation_id LIKE 'web-public-%'");
        $this->connection->exec(
            "DELETE FROM spotify_matches WHERE song_id IN (SELECT id FROM songs WHERE title = 'Public Snapshot Song')",
        );
        $this->connection->exec("DELETE FROM songs WHERE title = 'Public Snapshot Song'");
        $this->connection->exec(
            "UPDATE playlists SET spotify_playlist_id = NULL, spotify_owner_id = NULL, is_public = 1 "
            . "WHERE name = 'SRF 3 - Top 50' AND spotify_playlist_id = 'public-playlist'",
        );
        $this->connection->exec('DELETE FROM song_ignore_rules');
        $this->connection->exec("DELETE FROM plays WHERE played_at_utc >= '2026-09-17' AND played_at_utc < '2026-09-18'");
        $this->connection->exec("DELETE FROM import_runs WHERE range_from_utc >= '2026-09-16' AND range_from_utc < '2026-09-18'");
        $this->connection->exec('DELETE FROM songs WHERE NOT EXISTS (SELECT 1 FROM plays WHERE plays.song_id = songs.id)');
        $query = $this->connection->prepare('DELETE FROM playlists WHERE name = :name');
        $query->execute(['name' => self::FALLBACK_PLAYLIST]);
    }
}
