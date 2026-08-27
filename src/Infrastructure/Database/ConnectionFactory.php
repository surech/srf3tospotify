<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Support\Config;
use PDO;

final readonly class ConnectionFactory
{
    public function __construct(private Config $config) {}

    public function create(): PDO
    {
        $connection = new PDO(
            $this->config->databaseDsn(),
            $this->config->required('DB_USER'),
            $this->config->required('DB_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
        );
        $connection->exec("SET time_zone = '+00:00'");

        return $connection;
    }
}
