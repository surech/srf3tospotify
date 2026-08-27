<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

final readonly class StoredSpotifyMatch
{
    public function __construct(
        public int $id,
        public int $songId,
        public ?string $trackId,
        public ?string $uri,
        public string $source,
        public string $status,
        public ?float $confidence,
    ) {}
}
