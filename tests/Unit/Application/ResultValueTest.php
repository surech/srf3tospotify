<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Import\ImportResult;
use App\Application\Ranking\RankingEntry;
use App\Infrastructure\Http\HttpResponse;
use App\Support\Uuid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportResult::class)]
#[CoversClass(RankingEntry::class)]
#[CoversClass(HttpResponse::class)]
#[CoversClass(Uuid::class)]
final class ResultValueTest extends TestCase
{
    public function testSerializesImportResult(): void
    {
        $result = new ImportResult('correlation', 10, 7, 3);

        self::assertSame(7, $result->toArray()['counts']['inserted']);
        self::assertSame('correlation', $result->toArray()['correlation_id']);
    }

    public function testSerializesRankingEntry(): void
    {
        $entry = new RankingEntry(
            42,
            'Artist',
            'Title',
            5,
            180_000,
            new DateTimeImmutable('2026-08-24T20:00:00Z'),
            null,
            'pending',
        );

        self::assertSame(42, $entry->toArray()['song_id']);
        self::assertSame('2026-08-24T20:00:00+00:00', $entry->toArray()['last_played_at_utc']);
    }

    public function testResponseHeadersAreCaseInsensitiveAtLookup(): void
    {
        $response = new HttpResponse(200, ['content-type' => 'application/json'], '{}');

        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertNull($response->header('Missing'));
    }

    public function testGeneratesRfc4122VersionFourUuid(): void
    {
        $first = Uuid::v4();
        $second = Uuid::v4();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first,
        );
        self::assertNotSame($first, $second);
    }
}
