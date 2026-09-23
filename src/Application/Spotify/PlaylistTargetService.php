<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use App\Application\Ranking\RankingEntry;
use App\Application\Ranking\RankingService;
use App\Infrastructure\Database\PlaylistConfiguration;
use App\Infrastructure\Database\SongIgnoreRepository;
use App\Infrastructure\Database\SpotifyMatchRepository;
use App\Infrastructure\Database\StoredSpotifyMatch;
use DateTimeImmutable;

final readonly class PlaylistTargetService
{
    public function __construct(
        private RankingService $rankingService,
        private SpotifyMatchRepository $matchRepository,
        private SongIgnoreRepository $ignoreRepository,
    ) {}

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    public function window(
        PlaylistConfiguration $configuration,
        ?DateTimeImmutable $now = null,
    ): array {
        if ($configuration->fixedFromUtc !== null && $configuration->fixedToUtcExclusive !== null) {
            return [$configuration->fixedFromUtc, $configuration->fixedToUtcExclusive];
        }

        return $this->rankingService->window($configuration->rankingDays, $now);
    }

    /** @param null|callable(RankingEntry): StoredSpotifyMatch $resolver */
    public function build(
        PlaylistConfiguration $configuration,
        DateTimeImmutable $fromUtc,
        DateTimeImmutable $toUtcExclusive,
        ?PlaylistExclusions $exclusions = null,
        ?callable $resolver = null,
    ): PlaylistTarget {
        $ranking = $this->rankingService->allBetween(
            $fromUtc,
            $toUtcExclusive,
            $configuration->rankingFilter,
        );
        $exclusions ??= $this->exclusions($configuration->id);
        $builder = new PlaylistTargetBuilder(
            $configuration->targetTracks ?? $configuration->maxTracks,
            $exclusions->songIds,
            $exclusions->spotifyTrackIds,
        );
        $matches = $resolver === null
            ? $this->matchRepository->findBySongIds(array_map(
                static fn(RankingEntry $entry): int => $entry->songId,
                $ranking,
            ))
            : [];

        foreach ($ranking as $entry) {
            if (!$builder->needsMore()) {
                break;
            }
            $match = null;
            if (!$builder->isDirectlyIgnored($entry->songId)) {
                $match = $resolver === null
                    ? ($matches[$entry->songId] ?? null)
                    : $resolver($entry);
            }
            $builder->consider($entry, $match);
        }

        return $builder->result();
    }

    public function exclusions(int $playlistId): PlaylistExclusions
    {
        return $this->ignoreRepository->exclusionsForPlaylist($playlistId);
    }

    public function snapshotTime(): DateTimeImmutable
    {
        return $this->ignoreRepository->snapshotTime();
    }

    /**
     * @param list<int> $playlistIds
     * @return array<int, PlaylistExclusions>
     */
    public function exclusionSnapshot(
        array $playlistIds,
        ?DateTimeImmutable $effectiveAt = null,
    ): array {
        return $this->ignoreRepository->exclusionSnapshot($playlistIds, $effectiveAt);
    }

    /**
     * @param list<int> $songIds
     * @return array<int, list<DateTimeImmutable>>
     */
    public function playTimes(
        PlaylistConfiguration $configuration,
        DateTimeImmutable $fromUtc,
        DateTimeImmutable $toUtcExclusive,
        array $songIds,
    ): array {
        return $this->rankingService->playTimesBetween(
            $fromUtc,
            $toUtcExclusive,
            $songIds,
            $configuration->rankingFilter,
        );
    }
}
