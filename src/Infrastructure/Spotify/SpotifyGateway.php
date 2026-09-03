<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

interface SpotifyGateway
{
    /** @return list<SpotifyTrack> */
    public function searchTracks(string $title, string $artist): array;

    public function getTrack(string $trackId): SpotifyTrack;

    public function createPlaylist(string $name, string $description, bool $public): CreatedPlaylist;

    public function playlistExists(string $playlistId): bool;

    public function updatePlaylistVisibility(string $playlistId, bool $public): void;

    public function uploadPlaylistCoverImage(string $playlistId, string $jpeg): void;

    /** @param list<string> $uris */
    public function replacePlaylistItems(string $playlistId, array $uris): string;
}
