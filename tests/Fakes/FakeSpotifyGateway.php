<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Infrastructure\Spotify\CreatedPlaylist;
use App\Infrastructure\Spotify\SpotifyGateway;
use App\Infrastructure\Spotify\SpotifyTrack;
use RuntimeException;
use Throwable;

final class FakeSpotifyGateway implements SpotifyGateway
{
    /** @var array<string, list<SpotifyTrack>> */
    public array $searchResults = [];

    /** @var array<string, SpotifyTrack> */
    public array $tracks = [];

    /** @var list<array{title: string, artist: string}> */
    public array $searches = [];

    /** @var list<list<string>> */
    public array $replacements = [];

    /** @var array<string, bool> */
    public array $playlistExistence = [];

    /** @var list<string> */
    public array $playlistExistenceChecks = [];

    /** @var list<string> */
    public array $replacementPlaylistIds = [];

    /** @var array<string, Throwable> */
    public array $replacementFailures = [];

    /** @var list<string> */
    public array $createdPlaylistNames = [];

    /** @var list<array{playlist_id: string, jpeg: string}> */
    public array $coverUploads = [];

    public int $createdPlaylists = 0;

    public function searchTracks(string $title, string $artist): array
    {
        $this->searches[] = compact('title', 'artist');

        return $this->searchResults[$title . '|' . $artist] ?? [];
    }

    public function getTrack(string $trackId): SpotifyTrack
    {
        return $this->tracks[$trackId] ?? throw new RuntimeException('Unknown fake Spotify track.');
    }

    public function createPlaylist(string $name, string $description, bool $public): CreatedPlaylist
    {
        $this->createdPlaylistNames[] = $name;
        ++$this->createdPlaylists;
        $playlistId = $this->createdPlaylists === 1
            ? 'fake-playlist-id'
            : 'fake-playlist-id-' . $this->createdPlaylists;

        return new CreatedPlaylist($playlistId, 'fake-owner-id');
    }

    public function playlistExists(string $playlistId): bool
    {
        $this->playlistExistenceChecks[] = $playlistId;

        return $this->playlistExistence[$playlistId] ?? true;
    }

    public function uploadPlaylistCoverImage(string $playlistId, string $jpeg): void
    {
        $this->coverUploads[] = ['playlist_id' => $playlistId, 'jpeg' => $jpeg];
    }

    public function replacePlaylistItems(string $playlistId, array $uris): string
    {
        $this->replacementPlaylistIds[] = $playlistId;
        $this->replacements[] = $uris;
        if (isset($this->replacementFailures[$playlistId])) {
            throw $this->replacementFailures[$playlistId];
        }

        return 'fake-snapshot-id';
    }
}
