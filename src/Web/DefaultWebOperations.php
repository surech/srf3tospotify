<?php

declare(strict_types=1);

namespace App\Web;

use App\Application\Ranking\RankingEntry;
use App\ApplicationFactory;
use App\Infrastructure\Database\DashboardRepository;
use App\Infrastructure\Database\PlaylistConfiguration;
use App\Infrastructure\Database\SongIgnoreRule;
use App\Infrastructure\Database\StoredSpotifyMatch;
use App\Infrastructure\Spotify\SpotifyGateway;
use App\Infrastructure\Spotify\SpotifyTrack;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class DefaultWebOperations implements WebOperations
{
    private const SPOTIFY_SEARCH_LIMIT = 10;
    private const SPOTIFY_SEARCH_MAX_LENGTH = 200;
    private const SPOTIFY_SEARCH_MAX_OFFSET = 1000;

    public function __construct(
        private ApplicationFactory $factory,
        private DashboardRepository $dashboardRepository,
        private ?SpotifyGateway $spotify = null,
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

        $targetService = $this->factory->playlistTargetService();
        [$fromUtc, $toUtcExclusive] = $targetService->window($configuration);
        $target = $targetService->build($configuration, $fromUtc, $toUtcExclusive);
        $entries = array_merge(
            array_map(static fn(array $item): RankingEntry => $item['ranking'], $target->desired),
            array_map(static fn(array $item): RankingEntry => $item['ranking'], $target->skipped),
        );
        $playTimes = $targetService->playTimes(
            $configuration,
            $fromUtc,
            $toUtcExclusive,
            array_map(static fn(RankingEntry $entry): int => $entry->songId, $entries),
        );
        $lastSyncAt = $this->factory->playlistRepository()->lastSuccessfulSyncAt($configuration->id);

        return [
            'playlist' => $this->playlistData($configuration) + [
                'max_tracks' => $configuration->maxTracks,
                'target_tracks' => $configuration->targetTracks,
                'last_sync_at' => $lastSyncAt?->setTimezone(new DateTimeZone('Europe/Zurich'))->format('d.m.Y, H:i'),
            ],
            'ranking' => array_map(
                fn(array $item): array => $this->rankingEntryData(
                    $item['ranking'],
                    $playTimes[$item['ranking']->songId] ?? [],
                    $item['match'],
                ),
                $target->desired,
            ),
            'skipped' => array_map(
                fn(array $item): array => $this->rankingEntryData(
                    $item['ranking'],
                    $playTimes[$item['ranking']->songId] ?? [],
                    $item['match'],
                ) + [
                    'skip_reason' => $item['reason'],
                    'airplay_rank' => $item['airplay_rank'],
                ],
                $target->skipped,
            ),
            'target' => [
                'requested_count' => $configuration->targetTracks,
                'track_count' => \count($target->desired),
                'ignored_count' => $target->ignoredCount,
                'missing_match_count' => $target->missingMatchCount,
                'duplicate_track_count' => $target->duplicateTrackCount,
            ],
        ];
    }

    public function ignoredSongs(bool $includeHistory): array
    {
        $groups = [];
        $activeRuleCount = 0;
        foreach ($this->factory->songIgnoreService()->rules($includeHistory) as $rule) {
            $groups[$rule->songId] ??= [
                'song_id' => $rule->songId,
                'artist' => $rule->artist,
                'title' => $rule->title,
                'rules' => [],
                'active_specific_playlists' => [],
                'has_active_global' => false,
                'active_rule_count' => 0,
            ];
            $groups[$rule->songId]['rules'][] = $this->ignoreRuleData($rule);
            if (!$rule->isActive()) {
                continue;
            }
            ++$activeRuleCount;
            ++$groups[$rule->songId]['active_rule_count'];
            if ($rule->isGlobal()) {
                $groups[$rule->songId]['has_active_global'] = true;
            } elseif ($rule->playlistName !== null) {
                $groups[$rule->songId]['active_specific_playlists'][] = $rule->playlistName;
            }
        }

        foreach ($groups as &$group) {
            $group['can_ignore_globally'] = $group['active_rule_count'] > 0 && !$group['has_active_global'];
        }
        unset($group);

        return [
            'songs' => array_values($groups),
            'song_count' => \count($groups),
            'active_rule_count' => $activeRuleCount,
            'include_history' => $includeHistory,
        ];
    }

    public function ignoreSong(int $songId, ?int $playlistId, ?string $reason): array
    {
        return $this->ignoreRuleData($this->factory->songIgnoreService()->ignore($songId, $playlistId, $reason));
    }

    public function reactivateSong(int $ruleId): array
    {
        return $this->ignoreRuleData($this->factory->songIgnoreService()->reactivate($ruleId));
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

    public function searchSpotifyTracks(string $title, string $artist, string $offset): array
    {
        $title = trim($title);
        $artist = trim($artist);
        if ($title === '' && $artist === '') {
            throw new InvalidArgumentException('Title or artist is required.');
        }
        if (mb_strlen($title) > self::SPOTIFY_SEARCH_MAX_LENGTH
            || mb_strlen($artist) > self::SPOTIFY_SEARCH_MAX_LENGTH
        ) {
            throw new InvalidArgumentException('Title and artist must not exceed 200 characters.');
        }
        $offset = trim($offset);
        if (preg_match('/^\d+$/D', $offset) !== 1
            || (int) $offset > self::SPOTIFY_SEARCH_MAX_OFFSET
            || (int) $offset % self::SPOTIFY_SEARCH_LIMIT !== 0
        ) {
            throw new InvalidArgumentException('Offset must be a multiple of 10 between 0 and 1000.');
        }
        $parsedOffset = (int) $offset;
        $tracks = ($this->spotify ?? $this->factory->spotifyClient())->searchTracks(
            $title,
            $artist,
            $parsedOffset,
        );

        return [
            'items' => array_map(static fn(SpotifyTrack $track): array => [
                'id' => $track->id,
                'title' => $track->title,
                'artists' => $track->artists,
                'artist' => $track->artistLabel(),
                'album' => $track->album,
                'release_year' => $track->releaseYear,
                'duration_ms' => $track->durationMs,
                'image_url' => $track->imageUrl,
                'external_url' => $track->externalUrl,
            ], $tracks),
            'offset' => $parsedOffset,
            'limit' => self::SPOTIFY_SEARCH_LIMIT,
            'has_more' => \count($tracks) === self::SPOTIFY_SEARCH_LIMIT
                && $parsedOffset < self::SPOTIFY_SEARCH_MAX_OFFSET,
        ];
    }

    public function selectMatch(int $songId, string $trackReference): array
    {
        $match = $this->factory->matchingService()->selectManualTrack($songId, $trackReference);

        return ['song_id' => $match->songId, 'status' => $match->status, 'track_id' => $match->trackId];
    }

    public function resetMatch(int $songId): array
    {
        $match = $this->factory->matchingService()->reset($songId);

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

    /** @param list<DateTimeImmutable> $playTimes
     *  @return array<string, mixed>
     */
    private function rankingEntryData(
        RankingEntry $entry,
        array $playTimes,
        ?StoredSpotifyMatch $match = null,
    ): array {
        $data = $entry->toArray();
        if ($match !== null) {
            $data['spotify_track_id'] = $match->trackId;
            $data['spotify_uri'] = $match->uri;
            $data['spotify_title'] = $match->title;
            $data['spotify_artist'] = $match->artist;
            $data['spotify_duration_ms'] = $match->durationMs;
            $data['match_source'] = $match->source;
            $data['match_status'] = $match->status;
        }

        return $data + [
            'play_times' => array_map(
                static fn(DateTimeImmutable $playedAt): array => [
                    'datetime' => $playedAt->format(DATE_ATOM),
                    'label' => $playedAt->format('d.m.Y, H:i'),
                ],
                $playTimes,
            ),
        ];
    }

    /** @return array<string, bool|int|string|null> */
    private function ignoreRuleData(SongIgnoreRule $rule): array
    {
        $timezone = new DateTimeZone('Europe/Zurich');

        return [
            'id' => $rule->id,
            'song_id' => $rule->songId,
            'artist' => $rule->artist,
            'title' => $rule->title,
            'playlist_id' => $rule->playlistId,
            'playlist_name' => $rule->playlistName,
            'is_global' => $rule->isGlobal(),
            'is_active' => $rule->isActive(),
            'reason' => $rule->reason,
            'ignored_at' => $rule->ignoredAt->setTimezone($timezone)->format('d.m.Y, H:i'),
            'reactivated_at' => $rule->reactivatedAt?->setTimezone($timezone)->format('d.m.Y, H:i'),
        ];
    }
}
