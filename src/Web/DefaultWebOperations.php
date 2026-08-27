<?php

declare(strict_types=1);

namespace App\Web;

use App\ApplicationFactory;
use App\Infrastructure\Database\DashboardRepository;

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
            'ranking' => array_map(
                static fn($entry): array => $entry->toArray(),
                $this->factory->rankingService()->top(30, 50),
            ),
            'unresolved_matches' => $this->dashboardRepository->unresolvedMatches(),
            'recent_imports' => $this->dashboardRepository->recentImports(),
            'recent_syncs' => $this->dashboardRepository->recentSyncs(),
        ];
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
}
