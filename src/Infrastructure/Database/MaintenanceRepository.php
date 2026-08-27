<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use DateTimeImmutable;
use PDO;
use Throwable;

final readonly class MaintenanceRepository
{
    public function __construct(private PDO $connection) {}

    /** @return array{import_runs: int, sync_runs: int} */
    public function deleteFinishedRunsBefore(DateTimeImmutable $cutoff): array
    {
        $this->connection->beginTransaction();
        try {
            $parameters = ['cutoff' => $cutoff->format('Y-m-d H:i:s.u')];
            $syncQuery = $this->connection->prepare(
                'DELETE FROM sync_runs WHERE finished_at IS NOT NULL AND finished_at < :cutoff',
            );
            $syncQuery->execute($parameters);

            $importQuery = $this->connection->prepare(
                'DELETE FROM import_runs WHERE finished_at IS NOT NULL AND finished_at < :cutoff',
            );
            $importQuery->execute($parameters);

            $result = [
                'import_runs' => $importQuery->rowCount(),
                'sync_runs' => $syncQuery->rowCount(),
            ];
            $this->connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
