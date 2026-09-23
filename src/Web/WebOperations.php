<?php

declare(strict_types=1);

namespace App\Web;

interface WebOperations
{
    /** @return array<string, mixed> */
    public function dashboard(): array;

    /** @return array<string, mixed>|null */
    public function playlist(int $playlistId): ?array;

    /** @return array<string, mixed> */
    public function ignoredSongs(bool $includeHistory): array;

    /** @return array<string, mixed> */
    public function ignoreSong(int $songId, ?int $playlistId, ?string $reason): array;

    /** @return array<string, mixed> */
    public function reactivateSong(int $ruleId): array;

    public function playlistCover(int $playlistId): ?string;

    /** @return array<string, mixed> */
    public function import(string $fromDate, string $toDate, string $trigger): array;

    /** @return array<string, mixed> */
    public function synchronize(string $trigger): array;

    /** @return array<string, mixed> */
    public function migrate(): array;

    /** @return array<string, mixed> */
    public function searchSpotifyTracks(string $title, string $artist, string $offset): array;

    /** @return array<string, mixed> */
    public function selectMatch(int $songId, string $trackReference): array;

    /** @return array<string, mixed> */
    public function resetMatch(int $songId): array;

    public function authorizationUrl(string $state, string $redirectUri): string;

    public function exchangeAuthorizationCode(string $code, string $redirectUri): void;
}
