<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\DatabaseLoginRateLimiter;
use App\Infrastructure\Database\Migrator;
use App\Support\Config;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseLoginRateLimiter::class)]
final class DatabaseLoginRateLimiterTest extends TestCase
{
    private const APP_KEY = 'integration-rate-limit-key';
    private const CLIENT_ADDRESS = '192.0.2.45';

    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(Config::fromEnvironment()))->create();
        (new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    public function testLimitsFiveFailuresForFifteenMinutesWithoutStoringAddress(): void
    {
        $limiter = new DatabaseLoginRateLimiter($this->connection, self::APP_KEY);
        $startedAt = new DateTimeImmutable('2026-09-24T10:00:00Z', new DateTimeZone('UTC'));

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            self::assertSame(0, $limiter->retryAfterSeconds(self::CLIENT_ADDRESS, $startedAt));
            $limiter->recordFailure(self::CLIENT_ADDRESS, $startedAt);
        }

        self::assertSame(900, $limiter->retryAfterSeconds(self::CLIENT_ADDRESS, $startedAt));
        self::assertSame(0, $limiter->retryAfterSeconds('198.51.100.8', $startedAt));
        $storedKeyQuery = $this->connection->query('SELECT client_key FROM login_attempts');
        self::assertNotFalse($storedKeyQuery);
        $storedKey = $storedKeyQuery->fetchColumn();
        self::assertSame(hash_hmac('sha256', self::CLIENT_ADDRESS, self::APP_KEY), $storedKey);
        self::assertStringNotContainsString(self::CLIENT_ADDRESS, (string) $storedKey);

        $afterWindow = $startedAt->modify('+15 minutes');
        self::assertSame(0, $limiter->retryAfterSeconds(self::CLIENT_ADDRESS, $afterWindow));
        $limiter->recordFailure(self::CLIENT_ADDRESS, $afterWindow);
        self::assertSame(0, $limiter->retryAfterSeconds(self::CLIENT_ADDRESS, $afterWindow));

        $limiter->clear(self::CLIENT_ADDRESS);
        $remainingQuery = $this->connection->query('SELECT COUNT(*) FROM login_attempts');
        self::assertNotFalse($remainingQuery);
        self::assertSame(0, (int) $remainingQuery->fetchColumn());
    }

    private function cleanup(): void
    {
        $query = $this->connection->prepare(
            'DELETE FROM login_attempts WHERE client_key = :client_key',
        );
        $query->execute([
            'client_key' => hash_hmac('sha256', self::CLIENT_ADDRESS, self::APP_KEY),
        ]);
    }
}
