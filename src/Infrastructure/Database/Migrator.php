<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use RuntimeException;

final readonly class Migrator
{
    public function __construct(
        private PDO $connection,
        private string $migrationDirectory,
    ) {}

    /** @return array{applied: list<string>, skipped: list<string>} */
    public function migrate(): array
    {
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version VARCHAR(190) PRIMARY KEY, '
            . 'applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $files = glob(rtrim($this->migrationDirectory, '/') . '/*.php');
        if ($files === false) {
            throw new RuntimeException('Unable to list database migrations.');
        }
        sort($files, SORT_STRING);

        $applied = [];
        $skipped = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if ($this->isApplied($version)) {
                $skipped[] = $version;
                continue;
            }

            $statements = require $file;
            if (!\is_array($statements)) {
                throw new RuntimeException(\sprintf('Migration %s must return SQL statements.', $version));
            }

            foreach ($statements as $statement) {
                if (!\is_string($statement) || trim($statement) === '') {
                    throw new RuntimeException(\sprintf('Migration %s contains invalid SQL.', $version));
                }
                $this->connection->exec($statement);
            }

            $query = $this->connection->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
            $query->execute(['version' => $version]);
            $applied[] = $version;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function isApplied(string $version): bool
    {
        $query = $this->connection->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $query->execute(['version' => $version]);

        return $query->fetchColumn() !== false;
    }
}
