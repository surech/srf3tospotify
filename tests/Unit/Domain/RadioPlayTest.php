<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\RadioPlay;
use App\Domain\TextNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RadioPlay::class)]
#[CoversClass(TextNormalizer::class)]
final class RadioPlayTest extends TestCase
{
    public function testIdentityIgnoresCaseAndWhitespaceButEventIncludesTime(): void
    {
        $first = $this->play('2026-08-24T20:00:00Z', '  Riana  ', "Dis\tLüchte");
        $second = $this->play('2026-08-24T21:00:00Z', 'riana', 'dis lüchte');

        self::assertSame($first->identityHash(), $second->identityHash());
        self::assertNotSame($first->eventHash('channel'), $second->eventHash('channel'));
    }

    public function testRejectsNegativeDuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('negative');

        new RadioPlay(
            new DateTimeImmutable('2026-08-24T20:00:00Z'),
            120,
            -1,
            'Artist',
            'Title',
            false,
        );
    }

    public function testRejectsWhitespaceOnlyIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        new RadioPlay(
            new DateTimeImmutable('2026-08-24T20:00:00Z'),
            120,
            1,
            ' ',
            'Title',
            false,
        );
    }

    private function play(string $date, string $artist, string $title): RadioPlay
    {
        return new RadioPlay(
            new DateTimeImmutable($date, new DateTimeZone('UTC')),
            120,
            180_000,
            $artist,
            $title,
            false,
        );
    }
}
