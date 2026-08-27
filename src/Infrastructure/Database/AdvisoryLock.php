<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

final readonly class AdvisoryLock
{
    public function __construct(private PDO $connection) {}

    public function acquire(string $name): bool
    {
        $query = $this->connection->prepare('SELECT GET_LOCK(:name, 0)');
        $query->execute(['name' => $name]);

        return (int) $query->fetchColumn() === 1;
    }

    public function release(string $name): void
    {
        $query = $this->connection->prepare('SELECT RELEASE_LOCK(:name)');
        $query->execute(['name' => $name]);
    }
}
