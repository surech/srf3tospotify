<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use App\Infrastructure\Database\MaintenanceRepository;
use App\Support\JsonLogger;
use App\Support\JsonLogPruner;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CleanupService
{
    public function __construct(
        private MaintenanceRepository $repository,
        private JsonLogPruner $logPruner,
        private JsonLogger $logger,
    ) {}

    public function cleanup(int $retentionDays = 90, ?DateTimeImmutable $now = null): CleanupResult
    {
        if ($retentionDays < 1 || $retentionDays > 3650) {
            throw new InvalidArgumentException('Retention days must be between 1 and 3650.');
        }

        $startedAt = hrtime(true);
        $utc = new DateTimeZone('UTC');
        $reference = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $cutoff = $reference->modify(\sprintf('-%d days', $retentionDays));
        $deletedRuns = $this->repository->deleteFinishedRunsBefore($cutoff);
        $deletedLogRecords = $this->logPruner->pruneBefore($cutoff);
        $result = new CleanupResult(
            $deletedRuns['import_runs'],
            $deletedRuns['sync_runs'],
            $deletedLogRecords,
        );
        $context = $result->toArray();
        $elapsedNanoseconds = (int) (hrtime(true) - $startedAt);
        $context['duration_ms'] = intdiv(max(0, $elapsedNanoseconds), 1_000_000);
        $this->logger->info('maintenance.cleanup.succeeded', $context);

        return $result;
    }
}
