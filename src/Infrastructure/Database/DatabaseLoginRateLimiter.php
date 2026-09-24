<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Web\LoginRateLimiter;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final readonly class DatabaseLoginRateLimiter implements LoginRateLimiter
{
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 900;

    public function __construct(
        private PDO $connection,
        private string $applicationKey,
    ) {
        if ($applicationKey === '') {
            throw new RuntimeException('APP_KEY is required for login rate limiting.');
        }
    }

    public function retryAfterSeconds(string $clientAddress, ?DateTimeImmutable $now = null): int
    {
        $query = $this->connection->prepare(
            'SELECT failure_count, window_started_at FROM login_attempts WHERE client_key = :client_key',
        );
        $query->execute(['client_key' => $this->clientKey($clientAddress)]);
        $row = $query->fetch();
        if ($row === false || (int) $row['failure_count'] < self::MAX_FAILURES) {
            return 0;
        }

        $reference = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $windowStartedAt = new DateTimeImmutable((string) $row['window_started_at'], new DateTimeZone('UTC'));
        $elapsed = max(0, $reference->getTimestamp() - $windowStartedAt->getTimestamp());

        return max(0, self::WINDOW_SECONDS - $elapsed);
    }

    public function recordFailure(string $clientAddress, ?DateTimeImmutable $now = null): void
    {
        $reference = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $clientKey = $this->clientKey($clientAddress);
        $this->connection->beginTransaction();
        try {
            $insert = $this->connection->prepare(
                <<<'SQL'
                    INSERT INTO login_attempts (client_key, failure_count, window_started_at, updated_at)
                        VALUES (:client_key, 0, :window_started_at, :updated_at)
                    ON DUPLICATE KEY UPDATE client_key = VALUES(client_key)
                    SQL,
            );
            $insert->execute([
                'client_key' => $clientKey,
                'window_started_at' => $reference->format('Y-m-d H:i:s.u'),
                'updated_at' => $reference->format('Y-m-d H:i:s.u'),
            ]);

            $select = $this->connection->prepare(
                'SELECT failure_count, window_started_at FROM login_attempts '
                . 'WHERE client_key = :client_key FOR UPDATE',
            );
            $select->execute(['client_key' => $clientKey]);
            $row = $select->fetch();
            if ($row === false) {
                throw new RuntimeException('Unable to lock login attempt record.');
            }
            $windowStartedAt = new DateTimeImmutable((string) $row['window_started_at'], new DateTimeZone('UTC'));
            $expired = $reference->getTimestamp() - $windowStartedAt->getTimestamp() >= self::WINDOW_SECONDS;
            $update = $this->connection->prepare(
                'UPDATE login_attempts SET failure_count = :failure_count, '
                . 'window_started_at = :window_started_at, updated_at = :updated_at '
                . 'WHERE client_key = :client_key',
            );
            $update->execute([
                'failure_count' => $expired ? 1 : (int) $row['failure_count'] + 1,
                'window_started_at' => ($expired ? $reference : $windowStartedAt)->format('Y-m-d H:i:s.u'),
                'updated_at' => $reference->format('Y-m-d H:i:s.u'),
                'client_key' => $clientKey,
            ]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    public function clear(string $clientAddress): void
    {
        $query = $this->connection->prepare('DELETE FROM login_attempts WHERE client_key = :client_key');
        $query->execute(['client_key' => $this->clientKey($clientAddress)]);
    }

    private function clientKey(string $clientAddress): string
    {
        return hash_hmac('sha256', $clientAddress, $this->applicationKey);
    }
}
