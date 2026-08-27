<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use App\Application\Import\ImportLocked;
use App\Application\Ranking\RankingService;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Database\PlaylistRepository;
use App\Infrastructure\Database\SpotifyMatchRepository;
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

    public function __construct(
        private RankingService $rankingService,
        private MatchingService $matchingService,
        private SpotifyMatchRepository $matchRepository,
        private PlaylistRepository $playlistRepository,
        private SpotifyGateway $spotify,
        private AdvisoryLock $lock,
        private JsonLogger $logger,
        private DateTimeZone $timezone,
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

        $correlationId = Uuid::v4();
        $runId = null;
        try {
            $configuration = $this->playlistRepository->configuration();
            $toLocal = ($now ?? new DateTimeImmutable('now', $this->timezone))
                ->setTimezone($this->timezone)
                ->setTime(0, 0);
            $fromLocal = $toLocal->modify(\sprintf('-%d days', $configuration->rankingDays));
            $utc = new DateTimeZone('UTC');
            $runId = $this->playlistRepository->startRun(
                $configuration->id,
                $correlationId,
                $triggerType,
                $fromLocal->setTimezone($utc),
                $toLocal->setTimezone($utc),
            );

            $ranking = $this->rankingService->top(
                $configuration->rankingDays,
                $configuration->maxTracks,
                $now,
            );
            foreach ($ranking as $entry) {
                $this->matchingService->resolve($entry);
            }

            $matches = $this->matchRepository->findAccepted(
                array_map(static fn($entry): int => $entry->songId, $ranking),
            );
            $desired = [];
            foreach ($ranking as $entry) {
                $match = $matches[$entry->songId] ?? null;
                if ($match !== null && $match->trackId !== null && $match->uri !== null) {
                    $desired[] = ['ranking' => $entry, 'match' => $match];
                }
            }
            $this->playlistRepository->saveDesiredItems($runId, $desired);

            $spotifyPlaylistId = $configuration->spotifyPlaylistId;
            if ($spotifyPlaylistId === null) {
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
            }

            $uris = array_map(static fn(array $item): string => (string) $item['match']->uri, $desired);
            $snapshotId = $this->spotify->replacePlaylistItems($spotifyPlaylistId, $uris);
            $unresolved = \count($ranking) - \count($desired);
            $this->playlistRepository->finishRun($runId, $snapshotId, $unresolved);
            $result = new PlaylistSyncResult(
                $correlationId,
                $spotifyPlaylistId,
                $snapshotId,
                \count($desired),
                $unresolved,
            );
            $context = $result->toArray();
            $context['duration_ms'] = self::durationMilliseconds($startedAt);
            $this->logger->info('spotify.sync.succeeded', $context);

            return $result;
        } catch (Throwable $exception) {
            if ($runId !== null) {
                $this->playlistRepository->failRun($runId, $exception->getMessage());
            }
            $this->logger->error('spotify.sync.failed', [
                'status' => 'failed',
                'correlation_id' => $correlationId,
                'duration_ms' => self::durationMilliseconds($startedAt),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            $this->lock->release(self::LOCK_NAME);
        }
    }

    private static function durationMilliseconds(int $startedAt): int
    {
        $elapsedNanoseconds = (int) (hrtime(true) - $startedAt);

        return intdiv(max(0, $elapsedNanoseconds), 1_000_000);
    }
}
