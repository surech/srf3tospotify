<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use App\Application\Import\ImportLocked;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Database\PlaylistConfiguration;
use App\Infrastructure\Database\PlaylistRepository;
use App\Infrastructure\Spotify\SpotifyGateway;
use App\Support\JsonLogger;
use App\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class PlaylistSyncService
{
    private const LOCK_NAME = 'srf3tospotify:spotify-sync';

    /** @param array<string, string> $playlistCoverImages */
    public function __construct(
        private PlaylistTargetService $targetService,
        private MatchingService $matchingService,
        private PlaylistRepository $playlistRepository,
        private SpotifyGateway $spotify,
        private AdvisoryLock $lock,
        private JsonLogger $logger,
        private DateTimeZone $timezone,
        private array $playlistCoverImages = [],
    ) {}

    public function synchronize(string $triggerType = 'manual', ?DateTimeImmutable $now = null): PlaylistSyncResult
    {
        $startedAt = hrtime(true);
        if (!\in_array($triggerType, ['manual', 'cron', 'http-cron'], true)) {
            throw new InvalidArgumentException('Unsupported sync trigger type.');
        }
        if (!$this->lock->acquire(self::LOCK_NAME)) {
            throw new ImportLocked('Another Spotify synchronization is already running.');
        }

        try {
            $exclusionSnapshotAt = $this->targetService->snapshotTime();
            $results = [];
            $effectiveNow = $now ?? new DateTimeImmutable('now', $this->timezone);
            $firstException = null;
            $configurations = $this->playlistRepository->configurations();
            $exclusions = $this->targetService->exclusionSnapshot(array_map(
                static fn(PlaylistConfiguration $configuration): int => $configuration->id,
                $configurations,
            ), $exclusionSnapshotAt);
            foreach ($configurations as $configuration) {
                try {
                    $results[] = $this->synchronizePlaylist(
                        $configuration,
                        $exclusions[$configuration->id],
                        $triggerType,
                        $effectiveNow,
                    );
                } catch (Throwable $exception) {
                    $firstException ??= $exception;
                }
            }
            if ($firstException !== null) {
                throw $firstException;
            }

            return new PlaylistSyncResult($results);
        } finally {
            $this->lock->release(self::LOCK_NAME);
        }
    }

    private function synchronizePlaylist(
        PlaylistConfiguration $configuration,
        PlaylistExclusions $exclusions,
        string $triggerType,
        DateTimeImmutable $effectiveNow,
    ): SynchronizedPlaylist {
        $startedAt = hrtime(true);
        $correlationId = Uuid::v4();
        $runId = null;
        try {
            [$fromUtc, $toUtcExclusive] = $this->targetService->window($configuration, $effectiveNow);
            $runId = $this->playlistRepository->startRun(
                $configuration->id,
                $correlationId,
                $triggerType,
                $fromUtc,
                $toUtcExclusive,
            );

            $target = $this->targetService->build(
                $configuration,
                $fromUtc,
                $toUtcExclusive,
                $exclusions,
                $this->matchingService->resolve(...),
            );
            $desired = $target->desired;
            $this->playlistRepository->saveDesiredItems($runId, $desired);

            $spotifyPlaylistId = $configuration->spotifyPlaylistId;
            if ($spotifyPlaylistId === null || !$this->spotify->playlistExists($spotifyPlaylistId)) {
                $created = $this->spotify->createPlaylist(
                    $configuration->name,
                    $configuration->description,
                    $configuration->public,
                );
                $spotifyPlaylistId = $created->id;
                $this->playlistRepository->saveSpotifyIdentity(
                    $configuration->id,
                    $created->id,
                    $created->ownerId,
                );
            } else {
                $this->spotify->updatePlaylistVisibility($spotifyPlaylistId, $configuration->public);
            }

            $coverImage = $this->playlistCoverImages[$configuration->name] ?? null;
            if ($coverImage !== null) {
                $this->spotify->uploadPlaylistCoverImage($spotifyPlaylistId, $coverImage);
            }

            $uris = array_map(static fn(array $item): string => (string) $item['match']->uri, $desired);
            $snapshotId = $this->spotify->replacePlaylistItems($spotifyPlaylistId, $uris);
            $unresolved = $target->missingMatchCount;
            $requestedCount = $configuration->targetTracks ?? \count($desired);
            $this->playlistRepository->finishRun($runId, $snapshotId, $requestedCount, $target);
            $result = new SynchronizedPlaylist(
                $configuration->name,
                $correlationId,
                $spotifyPlaylistId,
                $snapshotId,
                $requestedCount,
                \count($desired),
                $unresolved,
                $target->ignoredCount,
                $target->duplicateTrackCount,
            );
            $context = $result->toArray();
            $context['status'] = 'succeeded';
            $context['duration_ms'] = self::durationMilliseconds($startedAt);
            $this->logger->info('spotify.sync.succeeded', $context);

            return $result;
        } catch (Throwable $exception) {
            if ($runId !== null) {
                $this->playlistRepository->failRun($runId, $exception->getMessage());
            }
            $this->logger->error('spotify.sync.failed', [
                'status' => 'failed',
                'name' => $configuration->name,
                'correlation_id' => $correlationId,
                'duration_ms' => self::durationMilliseconds($startedAt),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private static function durationMilliseconds(int $startedAt): int
    {
        $elapsedNanoseconds = (int) (hrtime(true) - $startedAt);

        return intdiv(max(0, $elapsedNanoseconds), 1_000_000);
    }
}
