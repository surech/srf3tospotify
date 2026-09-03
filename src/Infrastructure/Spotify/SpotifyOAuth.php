<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

use App\Infrastructure\Http\HttpClient;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

final readonly class SpotifyOAuth implements AccessTokenProvider
{
    private const ACCOUNTS_URL = 'https://accounts.spotify.com';

    public function __construct(
        private HttpClient $httpClient,
        private OAuthTokenStore $tokenStore,
        private string $clientId,
        private string $clientSecret,
    ) {
        if ($clientId === '' || $clientSecret === '') {
            throw new SpotifyException('Spotify client ID and secret are not configured.');
        }
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        if ($state === '' || $redirectUri === '') {
            throw new SpotifyException('Spotify OAuth state and redirect URI are required.');
        }

        return self::ACCOUNTS_URL . '/authorize?' . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => 'playlist-modify-private playlist-modify-public playlist-read-private',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): void
    {
        if ($code === '' || $redirectUri === '') {
            throw new SpotifyException('Spotify authorization code and redirect URI are required.');
        }
        $this->requestTokens([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ], null);
    }

    public function accessToken(): string
    {
        $tokens = $this->tokenStore->load();
        if ($tokens === null) {
            throw new SpotifyNotAuthorized('Spotify authorization is required.');
        }

        $refreshThreshold = new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC'));
        if ($tokens->expiresAt > $refreshThreshold) {
            return $tokens->accessToken;
        }

        return $this->requestTokens([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens->refreshToken,
        ], $tokens->refreshToken)->accessToken;
    }

    /** @param array<string, string> $form */
    private function requestTokens(array $form, ?string $existingRefreshToken): TokenSet
    {
        $response = $this->httpClient->request(
            'POST',
            self::ACCOUNTS_URL . '/api/token',
            [
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            http_build_query($form, '', '&', PHP_QUERY_RFC3986),
        );
        if ($response->status !== 200) {
            throw new SpotifyException(\sprintf('Spotify token request failed with HTTP %d.', $response->status));
        }

        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SpotifyException('Spotify token response is malformed.', previous: $exception);
        }
        if (
            !\is_array($payload)
            || !\is_string($payload['access_token'] ?? null)
            || !\is_int($payload['expires_in'] ?? null)
        ) {
            throw new SpotifyException('Spotify token response is incomplete.');
        }
        $refreshToken = $payload['refresh_token'] ?? $existingRefreshToken;
        if (!\is_string($refreshToken) || $refreshToken === '') {
            throw new SpotifyException('Spotify token response has no refresh token.');
        }

        $tokens = new TokenSet(
            $payload['access_token'],
            $refreshToken,
            new DateTimeImmutable(\sprintf('+%d seconds', $payload['expires_in']), new DateTimeZone('UTC')),
        );
        $this->tokenStore->save($tokens);

        return $tokens;
    }
}
