<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use DateTimeImmutable;

final readonly class SongIgnoreRule
{
    public function __construct(
        public int $id,
        public int $songId,
        public string $artist,
        public string $title,
        public ?int $playlistId,
        public ?string $playlistName,
        public ?string $reason,
        public DateTimeImmutable $ignoredAt,
        public ?DateTimeImmutable $reactivatedAt,
    ) {}

    public function isGlobal(): bool
    {
        return $this->playlistId === null;
    }

    public function isActive(): bool
    {
        return $this->reactivatedAt === null;
    }
}
