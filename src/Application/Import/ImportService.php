<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Domain\LocalDateRange;
use App\Infrastructure\Database\AdvisoryLock;
use App\Infrastructure\Database\ImportRepository;
use App\Infrastructure\Srf\SrfSource;
use App\Support\JsonLogger;
use App\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class ImportService
{
    private const LOCK_NAME = 'srf3tospotify:import';

    public function __construct(
        private SrfSource $source,
        private ImportRepository $repository,
        private AdvisoryLock $lock,
        private JsonLogger $logger,
        private string $channelId,
        private DateTimeZone $timezone,
    ) {}

    public function import(
        string $fromDate,
        string $toDate,
        string $triggerType = 'manual',
        ?DateTimeImmutable $now = null,
    ): ImportResult {
        $startedAt = hrtime(true);
        if (!\in_array($triggerType, ['manual', 'cron', 'http-cron'], true)) {
            throw new InvalidArgumentException('Unsupported import trigger type.');
        }
        $range = LocalDateRange::parse($fromDate, $toDate, $this->timezone, $now);
        if (!$this->lock->acquire(self::LOCK_NAME)) {
            throw new ImportLocked('Another import is already running.');
        }

        $correlationId = Uuid::v4();
        $runId = null;
        try {
            $channelDatabaseId = $this->repository->channelDatabaseId($this->channelId);
            $runId = $this->repository->startRun(
                $correlationId,
                $triggerType,
                $range->fromUtc(),
                $range->toUtcExclusive(),
            );

            $unique = [];
            foreach ($range->dailyWindows() as $window) {
                foreach ($this->source->fetch($window['from'], $window['to']) as $play) {
                    $unique[bin2hex($play->eventHash($this->channelId))] = $play;
                }
            }
            $plays = array_values($unique);
            $counts = $this->repository->persistPlays($runId, $channelDatabaseId, $this->channelId, $plays);
            $this->repository->finishRun($runId, \count($plays), $counts['inserted'], $counts['duplicates']);
            $result = new ImportResult(
                $correlationId,
                \count($plays),
                $counts['inserted'],
                $counts['duplicates'],
            );
            $context = $result->toArray();
            $context['duration_ms'] = self::durationMilliseconds($startedAt);
            $this->logger->info('import.succeeded', $context);

            return $result;
        } catch (Throwable $exception) {
            if ($runId !== null) {
                $this->repository->failRun($runId, $exception->getMessage());
            }
            $this->logger->error('import.failed', [
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
