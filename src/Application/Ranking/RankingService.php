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
        $this->validateLimit($limit);
        [$fromUtc, $toUtcExclusive] = $this->window($days, $now);

        return $this->repository->topSongs(
            $fromUtc,
            $toUtcExclusive,
            $limit,
            $filter ?? new RankingFilter(),
        );
    }

    /** @return list<array{entry: RankingEntry, play_times: list<DateTimeImmutable>}> */
    public function topWithPlayTimes(
        int $days = 30,
        int $limit = 50,
        ?DateTimeImmutable $now = null,
        ?RankingFilter $filter = null,
    ): array {
        $this->validateLimit($limit);
        [$fromUtc, $toUtcExclusive] = $this->window($days, $now);
        $filter ??= new RankingFilter();
        $entries = $this->repository->topSongs($fromUtc, $toUtcExclusive, $limit, $filter);
        $playTimes = $this->repository->playTimesBySong(
            $fromUtc,
            $toUtcExclusive,
            array_map(static fn(RankingEntry $entry): int => $entry->songId, $entries),
            $filter,
        );

        return array_map(
            static fn(RankingEntry $entry): array => [
                'entry' => $entry,
                'play_times' => $playTimes[$entry->songId] ?? [],
            ],
            $entries,
        );
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private function window(int $days, ?DateTimeImmutable $now): array
    {
        if ($days < 1 || $days > 3660) {
            throw new InvalidArgumentException('Ranking days must be between 1 and 3660.');
        }

        $toLocal = ($now ?? new DateTimeImmutable('now', $this->timezone))
            ->setTimezone($this->timezone)
            ->setTime(0, 0);
        $fromLocal = $toLocal->modify(\sprintf('-%d days', $days));
        $utc = new DateTimeZone('UTC');

        return [$fromLocal->setTimezone($utc), $toLocal->setTimezone($utc)];
    }

    private function validateLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Ranking limit must be between 1 and 500.');
        }
    }
}
