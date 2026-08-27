<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Import\ImportService;
use App\Application\Ranking\RankingService;
use App\Domain\RadioPlay;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\ImportRepository;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Database\RankingRepository;
use App\Support\Config;
use App\Support\JsonLogger;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\StaticSrfSource;

#[CoversClass(ImportService::class)]
#[CoversClass(ImportRepository::class)]
#[CoversClass(AdvisoryLock::class)]
#[CoversClass(RankingService::class)]
#[CoversClass(RankingRepository::class)]
final class ImportAndRankingTest extends TestCase
{
    private const CHANNEL_ID = 'dd0fa1ba-4ff6-4e1a-ab74-d7e49057d96f';

    private PDO $connection;
    private string $logPath;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(Config::fromEnvironment()))->create();
        (new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->logPath = sys_get_temp_dir() . '/srf3spotify-import-' . bin2hex(random_bytes(8)) . '.log';
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        @unlink($this->logPath);
    }

    public function testImportIsIdempotentAndRankingCountsSeparatePlays(): void
    {
        $plays = [
            $this->play('2020-01-01T10:00:00Z', 'Artist A', 'Song A'),
            $this->play('2020-01-01T11:00:00Z', 'Artist A', 'Song A'),
            $this->play('2020-01-01T12:00:00Z', 'Artist B', 'Song B'),
        ];
        $service = new ImportService(
            new StaticSrfSource($plays),
            new ImportRepository($this->connection),
            new AdvisoryLock($this->connection),
            new JsonLogger($this->logPath),
            self::CHANNEL_ID,
            new DateTimeZone('Europe/Zurich'),
        );
        $now = new DateTimeImmutable('2020-01-03T12:00:00+01:00');

        $first = $service->import('2020-01-01', '2020-01-01', 'manual', $now);
        $second = $service->import('2020-01-01', '2020-01-01', 'manual', $now);

        self::assertSame(3, $first->inserted);
        self::assertSame(0, $first->duplicates);
        self::assertSame(0, $second->inserted);
        self::assertSame(3, $second->duplicates);

        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        $record = json_decode((string) end($lines), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('import.succeeded', $record['event']);
        self::assertSame('succeeded', $record['context']['status']);
        self::assertSame($second->correlationId, $record['context']['correlation_id']);
        self::assertSame(3, $record['context']['counts']['duplicates']);
        self::assertIsInt($record['context']['duration_ms']);

        $ranking = (new RankingService(
            new RankingRepository($this->connection),
            new DateTimeZone('Europe/Zurich'),
        ))->top(2, 50, $now);

        self::assertCount(2, $ranking);
        self::assertSame('Song A', $ranking[0]->title);
        self::assertSame(2, $ranking[0]->playCount);
        self::assertSame('Song B', $ranking[1]->title);
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

    private function cleanup(): void
    {
        $this->connection->exec("DELETE FROM plays WHERE played_at_utc >= '2020-01-01' AND played_at_utc < '2020-01-03'");
        $this->connection->exec("DELETE FROM import_runs WHERE range_from_utc >= '2019-12-31' AND range_from_utc < '2020-01-03'");
        $this->connection->exec('DELETE FROM songs WHERE NOT EXISTS (SELECT 1 FROM plays WHERE plays.song_id = songs.id)');
    }
}
