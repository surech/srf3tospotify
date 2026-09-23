<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Spotify;

use App\Application\Ranking\RankingEntry;
use App\Application\Spotify\PlaylistTarget;
use App\Application\Spotify\PlaylistTargetBuilder;
use App\Infrastructure\Database\StoredSpotifyMatch;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaylistTarget::class)]
#[CoversClass(PlaylistTargetBuilder::class)]
final class PlaylistTargetBuilderTest extends TestCase
{
    public function testFillsTargetAfterIgnoredMissingAndDuplicateCandidates(): void
    {
        $builder = new PlaylistTargetBuilder(2, [1]);
        $entries = [
            $this->entry(1, 'Ignored'),
            $this->entry(2, 'Missing'),
            $this->entry(3, 'First'),
            $this->entry(4, 'Duplicate'),
            $this->entry(5, 'Second'),
        ];
        $matches = [
            3 => $this->match(3, 'track-a'),
            4 => $this->match(4, 'track-a'),
            5 => $this->match(5, 'track-b'),
        ];

        foreach ($entries as $entry) {
            $builder->consider($entry, $builder->isDirectlyIgnored($entry->songId) ? null : ($matches[$entry->songId] ?? null));
            if (!$builder->needsMore()) {
                break;
            }
        }

        $target = $builder->result();

        self::assertSame([3, 5], array_map(static fn(array $item): int => $item['ranking']->songId, $target->desired));
        self::assertSame([2, 4], array_map(static fn(array $item): int => $item['ranking']->songId, $target->skipped));
        self::assertSame([
            PlaylistTarget::SKIPPED_MISSING_MATCH,
            PlaylistTarget::SKIPPED_DUPLICATE_TRACK,
        ], array_column($target->skipped, 'reason'));
        self::assertSame(1, $target->ignoredCount);
        self::assertSame(1, $target->missingMatchCount);
        self::assertSame(1, $target->duplicateTrackCount);
        self::assertSame(5, $target->examinedCount);
    }

    public function testExcludesAliasesUsingCurrentSpotifyTrackId(): void
    {
        $builder = new PlaylistTargetBuilder(1, [], ['track-a']);

        $builder->consider($this->entry(1, 'Alias'), $this->match(1, 'track-a'));
        $builder->consider($this->entry(2, 'Allowed'), $this->match(2, 'track-b'));

        $target = $builder->result();

        self::assertSame([2], array_map(static fn(array $item): int => $item['ranking']->songId, $target->desired));
        self::assertSame(1, $target->ignoredCount);
        self::assertSame([], $target->skipped);
    }

    private function entry(int $songId, string $title): RankingEntry
    {
        return new RankingEntry(
            $songId,
            'Artist',
            $title,
            10 - $songId,
            180_000,
            new DateTimeImmutable('2020-01-01T12:00:00Z', new DateTimeZone('UTC')),
            null,
            'pending',
        );
    }

    private function match(int $songId, string $trackId): StoredSpotifyMatch
    {
        return new StoredSpotifyMatch(
            $songId,
            $songId,
            $trackId,
            'spotify:track:' . $trackId,
            'automatic',
            'accepted',
            1.0,
        );
    }
}
