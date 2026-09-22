<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Application\Ranking\RankingFilter;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PlaylistConfiguration
{
    public function __construct(
        public int $id,
        public ?string $spotifyPlaylistId,
        public ?string $spotifyOwnerId,
        public string $name,
        public string $description,
        public int $rankingDays,
        public int $maxTracks,
        public ?int $targetTracks,
        public RankingFilter $rankingFilter,
        public bool $public,
        public ?DateTimeImmutable $fixedFromUtc = null,
        public ?DateTimeImmutable $fixedToUtcExclusive = null,
    ) {
        if (($fixedFromUtc === null) !== ($fixedToUtcExclusive === null)) {
            throw new InvalidArgumentException('Fixed ranking start and end must be configured together.');
        }
        if ($fixedFromUtc !== null && $fixedToUtcExclusive !== null && $fixedFromUtc >= $fixedToUtcExclusive) {
            throw new InvalidArgumentException('Fixed ranking start must precede end.');
        }
        if ($targetTracks !== null && ($targetTracks < 1 || $targetTracks > $maxTracks)) {
            throw new InvalidArgumentException('Playlist target tracks must be within the configured maximum.');
        }
    }
}
