<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

use App\Infrastructure\Http\HttpClient;
use App\Infrastructure\Http\HttpResponse;
use JsonException;

final readonly class SpotifyClient implements SpotifyGateway
{
    private const API_URL = 'https://api.spotify.com/v1';

    public function __construct(
        private HttpClient $httpClient,
        private AccessTokenProvider $tokenProvider,
    ) {}

    public function searchTracks(string $title, string $artist): array
    {
        $query = http_build_query([
            'q' => \sprintf('track:%s artist:%s', $title, $artist),
            'type' => 'track',
            'market' => 'CH',
            'limit' => 10,
        ], '', '&', PHP_QUERY_RFC3986);
        $payload = $this->requestJson('GET', self::API_URL . '/search?' . $query, null, [200]);
        $items = $payload['tracks']['items'] ?? null;
        if (!\is_array($items)) {
            throw new SpotifyException('Spotify search response has no track items.');
        }

        $tracks = [];
        foreach ($items as $item) {
            $tracks[] = $this->parseTrack($item);
        }

        return $tracks;
    }

    public function getTrack(string $trackId): SpotifyTrack
    {
        if (preg_match('/^[A-Za-z0-9]{10,64}$/', $trackId) !== 1) {
            throw new SpotifyException('Spotify track ID has invalid format.');
        }
        $payload = $this->requestJson(
            'GET',
            self::API_URL . '/tracks/' . rawurlencode($trackId) . '?market=CH',
            null,
            [200],
        );

        return $this->parseTrack($payload);
    }

    public function createPlaylist(string $name, string $description, bool $public): CreatedPlaylist
    {
        $payload = $this->requestJson(
            'POST',
            self::API_URL . '/me/playlists',
            ['name' => $name, 'description' => $description, 'public' => $public],
            [201],
        );
        $id = $payload['id'] ?? null;
        $ownerId = $payload['owner']['id'] ?? null;
        if (!\is_string($id) || !\is_string($ownerId)) {
            throw new SpotifyException('Spotify playlist response is incomplete.');
        }

        return new CreatedPlaylist($id, $ownerId);
    }

    public function playlistExists(string $playlistId): bool
    {
        if ($playlistId === '') {
            throw new SpotifyException('Spotify playlist ID is required.');
        }

        $offset = 0;
        do {
            $query = http_build_query(['limit' => 50, 'offset' => $offset], '', '&', PHP_QUERY_RFC3986);
            $payload = $this->requestJson('GET', self::API_URL . '/me/playlists?' . $query, null, [200]);
            $items = $payload['items'] ?? null;
            $next = \array_key_exists('next', $payload) ? $payload['next'] : false;
            if (!\is_array($items) || ($next !== null && !\is_string($next))) {
                throw new SpotifyException('Spotify playlists response is incomplete.');
            }
            foreach ($items as $item) {
                $id = \is_array($item) ? ($item['id'] ?? null) : null;
                if (!\is_string($id)) {
                    throw new SpotifyException('Spotify playlist response is incomplete.');
                }
                if ($id === $playlistId) {
                    return true;
                }
            }
            $offset += 50;
        } while ($next !== null);

        return false;
    }

    public function updatePlaylistVisibility(string $playlistId, bool $public): void
    {
        if ($playlistId === '') {
            throw new SpotifyException('Spotify playlist ID is required.');
        }

        $response = $this->httpClient->request(
            'PUT',
            self::API_URL . '/playlists/' . rawurlencode($playlistId),
            [
                'Authorization' => 'Bearer ' . $this->tokenProvider->accessToken(),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            json_encode(['public' => $public], JSON_THROW_ON_ERROR),
        );
        $this->assertStatus($response, [200]);
    }

    public function uploadPlaylistCoverImage(string $playlistId, string $jpeg): void
    {
        if ($playlistId === '') {
            throw new SpotifyException('Spotify playlist ID is required.');
        }
        if ($jpeg === '') {
            throw new SpotifyException('Spotify playlist cover image is required.');
        }

        $payload = base64_encode($jpeg);
        if (\strlen($payload) > 256 * 1024) {
            throw new SpotifyException('Spotify playlist cover image exceeds the 256 KB payload limit.');
        }

        $response = $this->httpClient->request(
            'PUT',
            self::API_URL . '/playlists/' . rawurlencode($playlistId) . '/images',
            [
                'Authorization' => 'Bearer ' . $this->tokenProvider->accessToken(),
                'Content-Type' => 'image/jpeg',
            ],
            $payload,
        );
        $this->assertStatus($response, [202]);
    }

    public function replacePlaylistItems(string $playlistId, array $uris): string
    {
        if ($playlistId === '') {
            throw new SpotifyException('Spotify playlist ID is required.');
        }
        if (\count($uris) !== \count(array_unique($uris))) {
            throw new SpotifyException('Desired Spotify playlist contains duplicate URIs.');
        }

        $batches = array_chunk($uris, 100);
        $firstBatch = array_shift($batches) ?? [];
        $payload = $this->requestJson(
            'PUT',
            self::API_URL . '/playlists/' . rawurlencode($playlistId) . '/items',
            ['uris' => $firstBatch],
            [200],
        );
        $snapshotId = $this->snapshotId($payload);

        foreach ($batches as $batch) {
            $payload = $this->requestJson(
                'POST',
                self::API_URL . '/playlists/' . rawurlencode($playlistId) . '/items',
                ['uris' => $batch],
                [201],
            );
            $snapshotId = $this->snapshotId($payload);
        }

        return $snapshotId;
    }

    /** @param array<string, mixed>|null $body
     *  @param list<int> $expectedStatuses
     *  @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, ?array $body, array $expectedStatuses): array
    {
        $response = $this->httpClient->request(
            $method,
            $url,
            [
                'Authorization' => 'Bearer ' . $this->tokenProvider->accessToken(),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
        $this->assertStatus($response, $expectedStatuses);

        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SpotifyException('Spotify returned malformed JSON.', previous: $exception);
        }
        if (!\is_array($payload)) {
            throw new SpotifyException('Spotify returned an unsupported response.');
        }

        return $payload;
    }

    /** @param list<int> $expectedStatuses */
    private function assertStatus(HttpResponse $response, array $expectedStatuses): void
    {
        if ($response->status === 429) {
            $retryAfter = filter_var($response->header('retry-after'), FILTER_VALIDATE_INT);
            throw new SpotifyRateLimited($retryAfter === false ? 60 : max(1, $retryAfter));
        }
        if (!\in_array($response->status, $expectedStatuses, true)) {
            throw new SpotifyException(\sprintf('Spotify request failed with HTTP %d.', $response->status));
        }
    }

    /** @param mixed $item */
    private function parseTrack(mixed $item): SpotifyTrack
    {
        if (!\is_array($item)) {
            throw new SpotifyException('Spotify track response must be an object.');
        }
        $id = $item['id'] ?? null;
        $uri = $item['uri'] ?? null;
        $title = $item['name'] ?? null;
        $duration = $item['duration_ms'] ?? null;
        $artistItems = $item['artists'] ?? null;
        if (
            !\is_string($id)
            || !\is_string($uri)
            || !\is_string($title)
            || !\is_int($duration)
            || !\is_array($artistItems)
        ) {
            throw new SpotifyException('Spotify track response is incomplete.');
        }
        $artists = [];
        foreach ($artistItems as $artist) {
            $name = \is_array($artist) ? ($artist['name'] ?? null) : null;
            if (!\is_string($name) || $name === '') {
                throw new SpotifyException('Spotify track artist is incomplete.');
            }
            $artists[] = $name;
        }
        if ($artists === []) {
            throw new SpotifyException('Spotify track has no artist.');
        }

        return new SpotifyTrack($id, $uri, $title, $artists, $duration);
    }

    /** @param array<string, mixed> $payload */
    private function snapshotId(array $payload): string
    {
        $snapshotId = $payload['snapshot_id'] ?? null;
        if (!\is_string($snapshotId) || $snapshotId === '') {
            throw new SpotifyException('Spotify playlist response has no snapshot ID.');
        }

        return $snapshotId;
    }
}
