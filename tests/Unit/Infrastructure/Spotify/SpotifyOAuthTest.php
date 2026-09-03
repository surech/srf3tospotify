<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Spotify;

use App\Infrastructure\Http\HttpResponse;
use App\Infrastructure\Spotify\SpotifyOAuth;
use App\Infrastructure\Spotify\TokenSet;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryOAuthTokenStore;
use Tests\Fakes\QueueHttpClient;

#[CoversClass(SpotifyOAuth::class)]
#[CoversClass(TokenSet::class)]
final class SpotifyOAuthTest extends TestCase
{
    public function testBuildsAuthorizationUrl(): void
    {
        $oauth = new SpotifyOAuth(new QueueHttpClient([]), new InMemoryOAuthTokenStore(), 'client', 'secret');

        $url = $oauth->authorizationUrl('state-value', 'https://app.example/spotify/callback');

        self::assertStringStartsWith('https://accounts.spotify.com/authorize?', $url);
        self::assertStringContainsString('state=state-value', $url);
        self::assertStringContainsString('playlist-modify-private%20playlist-modify-public', $url);
        self::assertStringContainsString('playlist-read-private', $url);
    }

    public function testExchangesCodeAndReturnsStoredAccessToken(): void
    {
        $http = new QueueHttpClient([$this->tokenResponse('access-1', 'refresh-1', 3600)]);
        $store = new InMemoryOAuthTokenStore();
        $oauth = new SpotifyOAuth($http, $store, 'client', 'secret');

        $oauth->exchangeCode('code-value', 'https://app.example/spotify/callback');

        self::assertSame('access-1', $oauth->accessToken());
        self::assertSame('refresh-1', $store->tokens?->refreshToken);
        self::assertStringContainsString('grant_type=authorization_code', (string) $http->requests[0]['body']);
        self::assertSame('Basic ' . base64_encode('client:secret'), $http->requests[0]['headers']['Authorization']);
    }

    public function testRefreshesExpiredTokenAndKeepsExistingRefreshToken(): void
    {
        $http = new QueueHttpClient([$this->tokenResponse('access-2', null, 3600)]);
        $store = new InMemoryOAuthTokenStore(new TokenSet(
            'expired-access',
            'refresh-1',
            new DateTimeImmutable('-1 minute'),
        ));
        $oauth = new SpotifyOAuth($http, $store, 'client', 'secret');

        self::assertSame('access-2', $oauth->accessToken());
        self::assertSame('refresh-1', $store->tokens?->refreshToken);
        self::assertStringContainsString('grant_type=refresh_token', (string) $http->requests[0]['body']);
    }

    private function tokenResponse(string $accessToken, ?string $refreshToken, int $expiresIn): HttpResponse
    {
        $payload = ['access_token' => $accessToken, 'expires_in' => $expiresIn];
        if ($refreshToken !== null) {
            $payload['refresh_token'] = $refreshToken;
        }

        return new HttpResponse(200, ['content-type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
