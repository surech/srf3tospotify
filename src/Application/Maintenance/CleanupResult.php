<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

final readonly class CleanupResult
{
    public function __construct(
        public int $deletedImportRuns,
        public int $deletedSyncRuns,
        public int $deletedLogRecords,
    ) {}

    /** @return array{status: string, deleted: array{import_runs: int, sync_runs: int, log_records: int}} */
    public function toArray(): array
    {
        return [
            'status' => 'succeeded',
            'deleted' => [
                'import_runs' => $this->deletedImportRuns,
                'sync_runs' => $this->deletedSyncRuns,
                'log_records' => $this->deletedLogRecords,
            ],
        ];
    }
}
