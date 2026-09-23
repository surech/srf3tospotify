<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Spotify\SongIgnoreService;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Database\SongIgnoreRepository;
use App\Support\Config;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SongIgnoreService::class)]
#[CoversClass(SongIgnoreRepository::class)]
final class SongIgnoreServiceTest extends TestCase
{
    private PDO $connection;

    /** @var list<int> */
    private array $songIds = [];

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(Config::fromEnvironment()))->create();
        (new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    }

    protected function tearDown(): void
    {
        if ($this->songIds === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, \count($this->songIds), '?'));
        $this->connection->prepare("DELETE FROM song_ignore_rules WHERE song_id IN ({$placeholders})")
            ->execute($this->songIds);
        $this->connection->prepare("DELETE FROM spotify_matches WHERE song_id IN ({$placeholders})")
            ->execute($this->songIds);
        $this->connection->prepare("DELETE FROM songs WHERE id IN ({$placeholders})")
            ->execute($this->songIds);
    }

    public function testCreatesIndependentGlobalAndPlaylistRules(): void
    {
        [$firstPlaylistId, $secondPlaylistId] = $this->playlistIds();
        $globalSongId = $this->createSong('Global Song');
        $localSongId = $this->createSong('Local Song');
        $repository = new SongIgnoreRepository($this->connection);
        $service = new SongIgnoreService($repository);

        $local = $service->ignore($localSongId, $firstPlaylistId, '  Not for mornings  ');
        $global = $service->ignore($globalSongId, null, null);

        self::assertSame('Not for mornings', $local->reason);
        self::assertTrue($global->isGlobal());
        self::assertSame([$globalSongId, $localSongId], $repository->exclusionsForPlaylist($firstPlaylistId)->songIds);
        self::assertSame([$globalSongId], $repository->exclusionsForPlaylist($secondPlaylistId)->songIds);
        $snapshot = $repository->exclusionSnapshot([$firstPlaylistId, $secondPlaylistId]);
        self::assertSame([$globalSongId, $localSongId], $snapshot[$firstPlaylistId]->songIds);
        self::assertSame([$globalSongId], $snapshot[$secondPlaylistId]->songIds);
        self::assertCount(2, $service->rules());
    }

    public function testGlobalRuleBlocksNewSpecificAndDuplicateRules(): void
    {
        [$playlistId] = $this->playlistIds();
        $songId = $this->createSong('Blocked Song');
        $service = new SongIgnoreService(new SongIgnoreRepository($this->connection));
        $service->ignore($songId, null, null);

        try {
            $service->ignore($songId, $playlistId, null);
            self::fail('Expected a specific rule below an active global rule to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('Song is already ignored globally.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Song is already ignored in this scope.');
        $service->ignore($songId, null, null);
    }

    public function testReactivationKeepsHistoryAndAllowsNewRulePeriod(): void
    {
        [$playlistId] = $this->playlistIds();
        $songId = $this->createSong('History Song');
        $service = new SongIgnoreService(new SongIgnoreRepository($this->connection));
        $first = $service->ignore($songId, $playlistId, 'First reason');

        $reactivated = $service->reactivate($first->id);
        $second = $service->ignore($songId, $playlistId, 'Second reason');

        self::assertFalse($reactivated->isActive());
        self::assertNotSame($first->id, $second->id);
        self::assertCount(1, $service->rules());
        self::assertCount(2, $service->rules(true));
    }

    public function testExclusionsFollowCurrentAcceptedSpotifyMatch(): void
    {
        [$playlistId] = $this->playlistIds();
        $songId = $this->createSong('Remapped Song');
        $repository = new SongIgnoreRepository($this->connection);
        (new SongIgnoreService($repository))->ignore($songId, null, null);
        $this->saveAcceptedMatch($songId, 'track-before');
        $snapshot = $repository->exclusionSnapshot([$playlistId]);

        self::assertSame(['track-before'], $repository->exclusionsForPlaylist($playlistId)->spotifyTrackIds);

        $this->saveAcceptedMatch($songId, 'track-after');

        self::assertSame(['track-before'], $snapshot[$playlistId]->spotifyTrackIds);
        self::assertSame(['track-after'], $repository->exclusionsForPlaylist($playlistId)->spotifyTrackIds);
    }

    public function testSnapshotUsesRuleStateAtItsEffectiveTime(): void
    {
        [$playlistId] = $this->playlistIds();
        $reactivatedSongId = $this->createSong('Reactivated After Snapshot');
        $createdSongId = $this->createSong('Created After Snapshot');
        $repository = new SongIgnoreRepository($this->connection);
        $service = new SongIgnoreService($repository);
        $reactivatedRule = $service->ignore($reactivatedSongId, null, null);
        $this->connection->prepare(
            "UPDATE song_ignore_rules SET ignored_at = '2020-01-01 00:00:00.000000' WHERE id = :id",
        )->execute(['id' => $reactivatedRule->id]);
        $service->reactivate($reactivatedRule->id);
        $service->ignore($createdSongId, null, null);

        $snapshot = $repository->exclusionSnapshot(
            [$playlistId],
            new \DateTimeImmutable('2020-01-02T00:00:00Z'),
        );

        self::assertSame([$reactivatedSongId], $snapshot[$playlistId]->songIds);
    }

    /** @return non-empty-list<int> */
    private function playlistIds(): array
    {
        $query = $this->connection->query('SELECT id FROM playlists ORDER BY id');
        self::assertNotFalse($query);
        $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
        if ($ids === []) {
            self::fail('Expected at least one playlist configuration.');
        }

        return array_values($ids);
    }

    private function createSong(string $title): int
    {
        $identity = hash('sha256', $title . random_bytes(8), true);
        $query = $this->connection->prepare(
            'INSERT INTO songs (identity_hash, artist, title, normalized_artist, normalized_title) '
            . 'VALUES (:identity_hash, :artist, :title, :normalized_artist, :normalized_title)',
        );
        $query->execute([
            'identity_hash' => $identity,
            'artist' => 'Ignore Test Artist',
            'title' => $title,
            'normalized_artist' => 'ignore test artist',
            'normalized_title' => mb_strtolower($title),
        ]);
        $songId = (int) $this->connection->lastInsertId();
        $this->songIds[] = $songId;

        return $songId;
    }

    private function saveAcceptedMatch(int $songId, string $trackId): void
    {
        $query = $this->connection->prepare(
            <<<'SQL'
                INSERT INTO spotify_matches (
                    song_id, spotify_track_id, spotify_uri, match_source, status
                ) VALUES (
                    :song_id, :track_id, :uri, 'manual', 'accepted'
                ) ON DUPLICATE KEY UPDATE
                    spotify_track_id = VALUES(spotify_track_id),
                    spotify_uri = VALUES(spotify_uri),
                    status = 'accepted'
                SQL,
        );
        $query->execute([
            'song_id' => $songId,
            'track_id' => $trackId,
            'uri' => 'spotify:track:' . $trackId,
        ]);
    }
}
