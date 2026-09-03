<?php

declare(strict_types=1);

namespace App\Application\Ranking;

use App\Infrastructure\Database\RankingRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class RankingService
{
    public function __construct(
        private RankingRepository $repository,
        private DateTimeZone $timezone,
    ) {}

    /** @return list<RankingEntry> */
    public function top(
        int $days = 30,
        int $limit = 50,
        ?DateTimeImmutable $now = null,
        ?RankingFilter $filter = null,
    ): array {
        if ($days < 1 || $days > 3660) {
            throw new InvalidArgumentException('Ranking days must be between 1 and 3660.');
        }
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Ranking limit must be between 1 and 500.');
        }

        $toLocal = ($now ?? new DateTimeImmutable('now', $this->timezone))
            ->setTimezone($this->timezone)
            ->setTime(0, 0);
        $fromLocal = $toLocal->modify(\sprintf('-%d days', $days));
        $utc = new DateTimeZone('UTC');

        return $this->repository->topSongs(
            $fromLocal->setTimezone($utc),
            $toLocal->setTimezone($utc),
            $limit,
            $filter ?? new RankingFilter(),
        );
    }
}
