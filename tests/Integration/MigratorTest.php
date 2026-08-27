<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Support\Config;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Migrator::class)]
#[CoversClass(ConnectionFactory::class)]
final class MigratorTest extends TestCase
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = (new ConnectionFactory(Config::fromEnvironment()))->create();
    }

    public function testMigrationIsRepeatableAndCreatesCoreTables(): void
    {
        $migrator = new Migrator($this->connection, \dirname(__DIR__, 2) . '/database/migrations');

        $migrator->migrate();
        $secondRun = $migrator->migrate();

        self::assertSame([], $secondRun['applied']);
        self::assertContains('001_initial', $secondRun['skipped']);

        $statement = $this->connection->query("SHOW TABLES LIKE 'plays'");
        if ($statement === false) {
            self::fail('Unable to inspect migrated tables.');
        }
        $tables = $statement->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['plays'], $tables);
    }
}
