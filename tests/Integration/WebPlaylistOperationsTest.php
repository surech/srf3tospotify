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
use App\Support\Config;
use App\Support\JsonLogger;
use App\Web\DefaultWebOperations;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\StaticSrfSource;

#[CoversClass(DefaultWebOperations::class)]
final class WebPlaylistOperationsTest extends TestCase
{
    private const CHANNEL_ID = 'dd0fa1ba-4ff6-4e1a-ab74-d7e49057d96f';
    private const FALLBACK_PLAYLIST = 'AAA Playlist ohne Cover';

    private PDO $connection;
    private DefaultWebOperations $operations;
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
        $this->operations = new DefaultWebOperations(
            $factory,
            new DashboardRepository($factory->connection()),
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
        self::assertSame('/playlists/' . $top50['id'] . '/cover', $top50['cover_url']);
        self::assertStringStartsWith("\x89PNG", (string) $this->operations->playlistCover($top50['id']));
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
        $this->connection->exec('DELETE FROM song_ignore_rules');
        $this->connection->exec("DELETE FROM plays WHERE played_at_utc >= '2026-09-17' AND played_at_utc < '2026-09-18'");
        $this->connection->exec("DELETE FROM import_runs WHERE range_from_utc >= '2026-09-16' AND range_from_utc < '2026-09-18'");
        $this->connection->exec('DELETE FROM songs WHERE NOT EXISTS (SELECT 1 FROM plays WHERE plays.song_id = songs.id)');
        $query = $this->connection->prepare('DELETE FROM playlists WHERE name = :name');
        $query->execute(['name' => self::FALLBACK_PLAYLIST]);
    }
}
