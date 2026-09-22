<?php

declare(strict_types=1);

namespace App\Web;

use App\ApplicationFactory;
use App\Infrastructure\Database\DashboardRepository;
use App\Infrastructure\Database\PlaylistConfiguration;
use DateTimeImmutable;

final readonly class DefaultWebOperations implements WebOperations
{
    public function __construct(
        private ApplicationFactory $factory,
        private DashboardRepository $dashboardRepository,
    ) {}

    public function dashboard(): array
    {
        return [
            'statistics' => $this->dashboardRepository->statistics(),
            'playlists' => $this->playlists(),
            'unresolved_matches' => $this->dashboardRepository->unresolvedMatches(),
            'recent_imports' => $this->dashboardRepository->recentImports(),
            'recent_syncs' => $this->dashboardRepository->recentSyncs(),
        ];
    }

    public function playlist(int $playlistId): ?array
    {
        $configuration = $this->playlistConfiguration($playlistId);
        if ($configuration === null) {
            return null;
        }

        $rankingService = $this->factory->rankingService();
        if ($configuration->fixedFromUtc !== null && $configuration->fixedToUtcExclusive !== null) {
            $fromUtc = $configuration->fixedFromUtc;
            $toUtcExclusive = $configuration->fixedToUtcExclusive;
        } else {
            [$fromUtc, $toUtcExclusive] = $rankingService->window($configuration->rankingDays);
        }

        return [
            'playlist' => $this->playlistData($configuration),
            'ranking' => $this->rankingData($rankingService->topBetweenWithPlayTimes(
                $fromUtc,
                $toUtcExclusive,
                $configuration->maxTracks,
                $configuration->rankingFilter,
            )),
        ];
    }

    public function playlistCover(int $playlistId): ?string
    {
        $configuration = $this->playlistConfiguration($playlistId);
        if ($configuration === null) {
            return null;
        }
        $coverPath = $this->factory->playlistCoverPaths()[$configuration->name] ?? null;
        if ($coverPath === null || !is_file($coverPath) || !is_readable($coverPath)) {
            return null;
        }
        $cover = file_get_contents($coverPath);

        return $cover === false ? null : $cover;
    }

    public function import(string $fromDate, string $toDate, string $trigger): array
    {
        return $this->factory->importService()->import($fromDate, $toDate, $trigger)->toArray();
    }

    public function synchronize(string $trigger): array
    {
        return $this->factory->playlistSyncService()->synchronize($trigger)->toArray();
    }

    public function migrate(): array
    {
        return $this->factory->migrate();
    }

    public function selectMatch(int $songId, string $trackReference): array
    {
        $match = $this->factory->matchingService()->selectManualTrack($songId, $trackReference);

        return ['song_id' => $match->songId, 'status' => $match->status, 'track_id' => $match->trackId];
    }

    public function rejectMatch(int $songId): array
    {
        $match = $this->factory->matchingService()->reject($songId);

        return ['song_id' => $match->songId, 'status' => $match->status];
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return $this->factory->spotifyOAuth()->authorizationUrl($state, $redirectUri);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): void
    {
        $this->factory->spotifyOAuth()->exchangeCode($code, $redirectUri);
    }

    /** @return list<array{id: int, name: string, description: string, cover_url: string|null}> */
    private function playlists(): array
    {
        $configurations = $this->factory->playlistRepository()->configurations();
        usort(
            $configurations,
            static fn(PlaylistConfiguration $left, PlaylistConfiguration $right): int => strnatcasecmp(
                $left->name,
                $right->name,
            ),
        );

        return array_map($this->playlistData(...), $configurations);
    }

    private function playlistConfiguration(int $playlistId): ?PlaylistConfiguration
    {
        foreach ($this->factory->playlistRepository()->configurations() as $configuration) {
            if ($configuration->id === $playlistId) {
                return $configuration;
            }
        }

        return null;
    }

    /** @return array{id: int, name: string, description: string, cover_url: string|null} */
    private function playlistData(PlaylistConfiguration $configuration): array
    {
        $coverPath = $this->factory->playlistCoverPaths()[$configuration->name] ?? null;

        return [
            'id' => $configuration->id,
            'name' => $configuration->name,
            'description' => $configuration->description,
            'cover_url' => $coverPath !== null && is_file($coverPath) && is_readable($coverPath)
                ? '/playlists/' . $configuration->id . '/cover'
                : null,
        ];
    }

    /**
     * @param list<array{entry: \App\Application\Ranking\RankingEntry, play_times: list<DateTimeImmutable>}> $ranking
     * @return list<array<string, mixed>>
     */
    private function rankingData(array $ranking): array
    {
        return array_map(
            static fn(array $item): array => $item['entry']->toArray() + [
                'play_times' => array_map(
                    static fn(DateTimeImmutable $playedAt): array => [
                        'datetime' => $playedAt->format(DATE_ATOM),
                        'label' => $playedAt->format('d.m.Y, H:i'),
                    ],
                    $item['play_times'],
                ),
            ],
            $ranking,
        );
    }
}
