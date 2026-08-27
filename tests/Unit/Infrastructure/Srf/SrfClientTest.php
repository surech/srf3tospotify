<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Srf;

use App\Domain\RadioPlay;
use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Srf\SrfClient;
use App\Infrastructure\Srf\SrfException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\QueueHttpClient;

#[CoversClass(SrfClient::class)]
final class SrfClientTest extends TestCase
{
    public function testParsesVerifiedPayloadAndEncodesQuery(): void
    {
        $http = new QueueHttpClient([$this->response([$this->item('2026-08-24T23:57:39+02:00')])]);
        $client = new SrfClient($http, 'https://srf.example/byChannel', 'channel-id');

        $plays = $client->fetch(
            new DateTimeImmutable('2026-08-24T00:00:00+02:00'),
            new DateTimeImmutable('2026-08-24T23:59:59+02:00'),
        );

        self::assertCount(1, $plays);
        self::assertInstanceOf(RadioPlay::class, $plays[0]);
        self::assertSame('RIANA (CH)', $plays[0]->artist);
        self::assertSame('2026-08-24T21:57:39+00:00', $plays[0]->playedAtUtc->format(DATE_ATOM));
        self::assertSame(120, $plays[0]->sourceOffsetMinutes);
        self::assertStringContainsString('from=2026-08-24T00%3A00%3A00%2B02%3A00', $http->requests[0]['url']);
        self::assertStringContainsString('pageSize=500', $http->requests[0]['url']);
    }

    public function testSplitsFullResultAndDeduplicatesBoundary(): void
    {
        $item = $this->item('2026-08-24T12:00:00+02:00');
        $http = new QueueHttpClient([
            $this->response([$item, $this->item('2026-08-24T13:00:00+02:00')]),
            $this->response([$item]),
            $this->response([$item]),
        ]);
        $client = new SrfClient($http, 'https://srf.example/byChannel', 'channel-id', 2);

        $plays = $client->fetch(
            new DateTimeImmutable('2026-08-24T00:00:00+02:00'),
            new DateTimeImmutable('2026-08-24T23:59:59+02:00'),
        );

        self::assertCount(1, $plays);
        self::assertCount(3, $http->requests);
    }

    public function testRejectsInvalidSchema(): void
    {
        $client = new SrfClient(
            new QueueHttpClient([new HttpResponse(200, ['content-type' => 'application/json'], '{"other":[]}')]),
            'https://srf.example/byChannel',
            'channel-id',
        );

        $this->expectException(SrfException::class);
        $this->expectExceptionMessage('songList');

        $client->fetch(
            new DateTimeImmutable('2026-08-24T00:00:00+02:00'),
            new DateTimeImmutable('2026-08-24T23:59:59+02:00'),
        );
    }

    public function testRejectsHttpFailure(): void
    {
        $client = new SrfClient(
            new QueueHttpClient([new HttpResponse(503, [], '')]),
            'https://srf.example/byChannel',
            'channel-id',
        );

        $this->expectException(SrfException::class);
        $this->expectExceptionMessage('HTTP 503');

        $client->fetch(
            new DateTimeImmutable('2026-08-24T00:00:00+02:00'),
            new DateTimeImmutable('2026-08-24T23:59:59+02:00'),
        );
    }

    /** @return array<string, mixed> */
    private function item(string $date): array
    {
        return [
            'isPlayingNow' => false,
            'date' => $date,
            'duration' => 139_990,
            'title' => 'DIS LÜCHTE',
            'artist' => ['name' => 'RIANA (CH)'],
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function response(array $items): HttpResponse
    {
        return new HttpResponse(
            200,
            ['content-type' => 'application/json;charset=UTF-8'],
            json_encode(['songList' => $items], JSON_THROW_ON_ERROR),
        );
    }
}
