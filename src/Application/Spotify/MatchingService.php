<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use App\Application\Ranking\RankingEntry;
use App\Infrastructure\Database\SpotifyMatchRepository;
use App\Infrastructure\Database\StoredSpotifyMatch;
use App\Infrastructure\Spotify\SpotifyGateway;
use InvalidArgumentException;

final readonly class MatchingService
{
    public function __construct(
        private SpotifyGateway $spotify,
        private MatchingEngine $engine,
        private SpotifyMatchRepository $repository,
    ) {}

    public function resolve(RankingEntry $entry): StoredSpotifyMatch
    {
        $existing = $this->repository->find($entry->songId);
        if ($existing?->source === 'manual' || $existing?->status === 'accepted') {
            return $existing;
        }

        $searchArtist = trim(preg_replace('/\s*\(CH\)\s*/iu', ' ', $entry->artist) ?? $entry->artist);
        $candidates = $this->spotify->searchTracks($entry->title, $searchArtist);
        $decision = $this->engine->decide($entry->title, $entry->artist, $entry->durationMs, $candidates);

        return $this->repository->saveAutomatic($entry->songId, $decision);
    }

    public function selectManualTrack(int $songId, string $trackReference): StoredSpotifyMatch
    {
        $trackId = $this->extractTrackId($trackReference);
        $track = $this->spotify->getTrack($trackId);

        return $this->repository->saveManualTrack($songId, $track);
    }

    public function reject(int $songId): StoredSpotifyMatch
    {
        return $this->repository->saveManualRejection($songId);
    }

    private function extractTrackId(string $reference): string
    {
        $reference = trim($reference);
        $patterns = [
            '/^spotify:track:([A-Za-z0-9]{10,64})$/',
            '~^https://open\.spotify\.com/track/([A-Za-z0-9]{10,64})(?:\?.*)?$~',
            '/^([A-Za-z0-9]{10,64})$/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $reference, $matches) === 1) {
                return $matches[1];
            }
        }

        throw new InvalidArgumentException('Spotify track reference has invalid format.');
    }
}
