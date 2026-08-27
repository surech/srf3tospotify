<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LocalDateRange;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalDateRange::class)]
final class LocalDateRangeTest extends TestCase
{
    public function testSpringDstDayContainsTwentyThreeHours(): void
    {
        $range = LocalDateRange::parse(
            '2026-03-29',
            '2026-03-29',
            new DateTimeZone('Europe/Zurich'),
            new DateTimeImmutable('2026-04-01T12:00:00+02:00'),
        );

        self::assertSame(23 * 3600, $range->toUtcExclusive()->getTimestamp() - $range->fromUtc()->getTimestamp());
    }

    public function testAutumnDstDayContainsTwentyFiveHours(): void
    {
        $range = LocalDateRange::parse(
            '2026-10-25',
            '2026-10-25',
            new DateTimeZone('Europe/Zurich'),
            new DateTimeImmutable('2026-11-01T12:00:00+01:00'),
        );

        self::assertSame(25 * 3600, $range->toUtcExclusive()->getTimestamp() - $range->fromUtc()->getTimestamp());
    }

    public function testRejectsCurrentIncompleteDay(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('complete past day');

        LocalDateRange::parse(
            '2026-08-26',
            '2026-08-26',
            new DateTimeZone('Europe/Zurich'),
            new DateTimeImmutable('2026-08-26T12:00:00+02:00'),
        );
    }

    public function testBuildsOneWindowPerCalendarDay(): void
    {
        $range = LocalDateRange::parse(
            '2026-08-23',
            '2026-08-24',
            new DateTimeZone('Europe/Zurich'),
            new DateTimeImmutable('2026-08-26T12:00:00+02:00'),
        );

        $windows = $range->dailyWindows();

        self::assertCount(2, $windows);
        self::assertSame('2026-08-23T00:00:00+02:00', $windows[0]['from']->format(DATE_ATOM));
        self::assertSame('2026-08-24T23:59:59+02:00', $windows[1]['to']->format(DATE_ATOM));
    }

    public function testRejectsInvalidAndReversedDates(): void
    {
        $timezone = new DateTimeZone('Europe/Zurich');
        $now = new DateTimeImmutable('2026-08-26T12:00:00+02:00');

        try {
            LocalDateRange::parse('24.08.2026', '2026-08-24', $timezone, $now);
            self::fail('Invalid date format was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('YYYY-MM-DD', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not follow');
        LocalDateRange::parse('2026-08-24', '2026-08-23', $timezone, $now);
    }
}
