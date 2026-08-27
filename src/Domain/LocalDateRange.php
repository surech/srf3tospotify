<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class LocalDateRange
{
    private function __construct(
        public DateTimeImmutable $fromLocal,
        public DateTimeImmutable $toLocalExclusive,
    ) {}

    public static function parse(
        string $from,
        string $to,
        DateTimeZone $timezone,
        ?DateTimeImmutable $now = null,
    ): self {
        $fromDate = self::parseDate($from, $timezone);
        $toDate = self::parseDate($to, $timezone);
        if ($fromDate > $toDate) {
            throw new InvalidArgumentException('Import start date must not follow end date.');
        }

        $difference = $fromDate->diff($toDate)->days;
        if ($difference >= 31) {
            throw new InvalidArgumentException('Import range cannot exceed 31 days.');
        }

        $today = ($now ?? new DateTimeImmutable('now', $timezone))
            ->setTimezone($timezone)
            ->setTime(0, 0);
        if ($toDate >= $today) {
            throw new InvalidArgumentException('Import end date must be a complete past day.');
        }

        return new self($fromDate, $toDate->modify('+1 day'));
    }

    /** @return list<array{from: DateTimeImmutable, to: DateTimeImmutable}> */
    public function dailyWindows(): array
    {
        $windows = [];
        $cursor = $this->fromLocal;
        while ($cursor < $this->toLocalExclusive) {
            $next = $cursor->modify('+1 day');
            $windows[] = ['from' => $cursor, 'to' => $next->modify('-1 second')];
            $cursor = $next;
        }

        return $windows;
    }

    public function fromUtc(): DateTimeImmutable
    {
        return $this->fromLocal->setTimezone(new DateTimeZone('UTC'));
    }

    public function toUtcExclusive(): DateTimeImmutable
    {
        return $this->toLocalExclusive->setTimezone(new DateTimeZone('UTC'));
    }

    private static function parseDate(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD format.');
        }

        return $date;
    }
}
