<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Spotify;

use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Spotify\CreatedPlaylist;
use App\Infrastructure\Spotify\SpotifyClient;
use App\Infrastructure\Spotify\SpotifyRateLimited;
use App\Infrastructure\Spotify\SpotifyTrack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\QueueHttpClient;
use Tests\Fakes\StaticAccessTokenProvider;

#[CoversClass(SpotifyClient::class)]
#[CoversClass(SpotifyTrack::class)]
#[CoversClass(CreatedPlaylist::class)]
#[CoversClass(SpotifyRateLimited::class)]
final class SpotifyClientTest extends TestCase
{
    public function testSearchesTenTracksInSwissMarket(): void
    {
        $http = new QueueHttpClient([$this->response(200, [
            'tracks' => ['items' => [$this->trackPayload('track000001', 'Song', 'Artist')]],
        ])]);
        $client = new SpotifyClient($http, new StaticAccessTokenProvider());

        $tracks = $client->searchTracks('Song', 'Artist');

        self::assertCount(1, $tracks);
        self::assertSame('track000001', $tracks[0]->id);
        self::assertSame('Artist', $tracks[0]->artistLabel());
        self::assertStringContainsString('market=CH', $http->requests[0]['url']);
        self::assertStringContainsString('limit=10', $http->requests[0]['url']);
        self::assertSame('Bearer test-access-token', $http->requests[0]['headers']['Authorization']);
    }

    public function testGetsTrackAndCreatesPlaylist(): void
    {
        $http = new QueueHttpClient([
            $this->response(200, $this->trackPayload('track000001', 'Song', 'Artist')),
            $this->response(201, ['id' => 'playlist-id', 'owner' => ['id' => 'owner-id']]),
        ]);
        $client = new SpotifyClient($http, new StaticAccessTokenProvider());

        $track = $client->getTrack('track000001');
        $playlist = $client->createPlaylist('Radio Top 50', 'Generated', false);

        self::assertSame('Song', $track->title);
        self::assertSame('playlist-id', $playlist->id);
        self::assertSame('owner-id', $playlist->ownerId);
        self::assertSame('POST', $http->requests[1]['method']);
        self::assertSame(
            ['name' => 'Radio Top 50', 'description' => 'Generated', 'public' => false],
            json_decode((string) $http->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testFindsPlaylistAcrossCurrentUserPlaylistPages(): void
    {
        $http = new QueueHttpClient([
            $this->response(200, [
                'items' => [['id' => 'another-playlist']],
                'next' => 'https://api.spotify.com/v1/me/playlists?limit=50&offset=50',
            ]),
            $this->response(200, [
                'items' => [['id' => 'playlist-id']],
                'next' => null,
            ]),
        ]);
        $client = new SpotifyClient($http, new StaticAccessTokenProvider());

        self::assertTrue($client->playlistExists('playlist-id'));
        self::assertStringContainsString('limit=50&offset=0', $http->requests[0]['url']);
        self::assertStringContainsString('limit=50&offset=50', $http->requests[1]['url']);
    }

    public function testReportsPlaylistMissingFromCurrentUserPlaylists(): void
    {
        $http = new QueueHttpClient([$this->response(200, [
            'items' => [['id' => 'another-playlist']],
            'next' => null,
        ])]);
        $client = new SpotifyClient($http, new StaticAccessTokenProvider());

        self::assertFalse($client->playlistExists('playlist-id'));
    }

    public function testUpdatesPlaylistVisibility(): void
    {
        $http = new QueueHttpClient([new HttpResponse(200, [], '')]);
        $client = new SpotifyClient($http, new StaticAccessTokenProvider());

        $client->updatePlaylistVisibility('playlist/id', true);

        self::assertSame('PUT', $http->requests[0]['method']);
        self::assertSame('https://api.spotify.com/v1/playlists/playlist%2Fid', $http->requests[0]['url']);
        self::assertSame(
            ['public' => true],
            json_decode((string) $http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testUploadsBase64EncodedPlaylistCoverImage(): void
    {
        $http = new QueueHttpClient([new HttpResponse(202, [], '')]);
        $client = new SpotifyClient($http, new StaticAccessTokenProvider());
        $jpeg = "\xFF\xD8cover-image\xFF\xD9";

        $client->uploadPlaylistCoverImage('playlist/id', $jpeg);

        self::assertSame('PUT', $http->requests[0]['method']);
        self::assertSame('https://api.spotify.com/v1/playlists/playlist%2Fid/images', $http->requests[0]['url']);
        self::assertSame('Bearer test-access-token', $http->requests[0]['headers']['Authorization']);
        self::assertSame('image/jpeg', $http->requests[0]['headers']['Content-Type']);
        self::assertSame(base64_encode($jpeg), $http->requests[0]['body']);
    }

    public function testReplacesThenAppendsInBatchesOfOneHundred(): void
    {
        $http = new QueueHttpClient([
            $this->response(200, ['snapshot_id' => 'snapshot-1']),
            $this->response(201, ['snapshot_id' => 'snapshot-2']),
            $this->response(201, ['snapshot_id' => 'snapshot-3']),
        ]);
        $client = new SpotifyClient($http, new StaticAccessTokenProvider());
        $uris = array_map(
            static fn(int $index): string => \sprintf('spotify:track:%010d', $index),
            range(1, 205),
        );

        $snapshot = $client->replacePlaylistItems('playlist-id', $uris);

        self::assertSame('snapshot-3', $snapshot);
        self::assertSame(['PUT', 'POST', 'POST'], array_column($http->requests, 'method'));
        self::assertCount(100, json_decode((string) $http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR)['uris']);
        self::assertCount(100, json_decode((string) $http->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR)['uris']);
        self::assertCount(5, json_decode((string) $http->requests[2]['body'], true, 512, JSON_THROW_ON_ERROR)['uris']);
    }

    public function testMapsRateLimitAndRetryAfter(): void
    {
        $client = new SpotifyClient(
            new QueueHttpClient([new HttpResponse(429, ['retry-after' => '17'], '{}')]),
            new StaticAccessTokenProvider(),
        );

        try {
            $client->searchTracks('Song', 'Artist');
            self::fail('Rate limit was not reported.');
        } catch (SpotifyRateLimited $exception) {
            self::assertSame(17, $exception->retryAfterSeconds);
        }
    }

    /** @return array<string, mixed> */
    private function trackPayload(string $id, string $title, string $artist): array
    {
        return [
            'id' => $id,
            'uri' => 'spotify:track:' . $id,
            'name' => $title,
            'duration_ms' => 180_000,
            'artists' => [['name' => $artist]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function response(int $status, array $payload): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
