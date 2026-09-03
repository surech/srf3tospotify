<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Spotify;

use App\Application\Spotify\MatchDecision;
use App\Application\Spotify\MatchingEngine;
use App\Infrastructure\Spotify\SpotifyTrack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatchingEngine::class)]
#[CoversClass(MatchDecision::class)]
final class MatchingEngineTest extends TestCase
{
    public function testAcceptsClearExactMatchAndIgnoresSwissMarker(): void
    {
        $decision = (new MatchingEngine())->decide(
            'Dis Lüchte',
            'Riana (CH)',
            180_000,
            [
                $this->track('track000001', 'Dis Lüchte', 'Riana', 180_000),
                $this->track('track000002', 'Different Song', 'Other Artist', 220_000),
            ],
        );

        self::assertTrue($decision->accepted());
        self::assertSame('track000001', $decision->track?->id);
        self::assertSame(1.0, $decision->confidence);
    }

    public function testAcceptsFirstOfIdenticalSpotifyResults(): void
    {
        $decision = (new MatchingEngine())->decide(
            'Song',
            'Artist',
            180_000,
            [
                $this->track('track000001', 'Song', 'Artist', 180_000),
                $this->track('track000002', 'Song', 'Artist', 180_000),
            ],
        );

        self::assertTrue($decision->accepted());
        self::assertSame('track000001', $decision->track?->id);
        self::assertSame(0.0, $decision->runnerUpConfidence);
    }

    public function testRoutesAmbiguousVersionsToReview(): void
    {
        $decision = (new MatchingEngine())->decide(
            'Song',
            'Artist',
            180_000,
            [
                $this->track('track000001', 'Song', 'Artist', 180_000),
                $this->track('track000002', 'Song', 'Artist', 181_001),
            ],
        );

        self::assertFalse($decision->accepted());
        self::assertSame('review', $decision->status);
        self::assertGreaterThan(0.90, $decision->runnerUpConfidence);
    }

    public function testTreatsCloseDurationAsDuplicatePreservingFirstCandidate(): void
    {
        $decision = (new MatchingEngine())->decide(
            'VERTIGO',
            'TIFFANY ARIS',
            117_796,
            [
                $this->track('track000001', 'Vertigo', 'Tiffany Aris', 139_000),
                $this->track('track000002', 'Vertigo', 'Tiffany Aris', 139_653),
            ],
        );

        self::assertTrue($decision->accepted());
        self::assertSame('track000001', $decision->track?->id);
        self::assertGreaterThan(0.90, $decision->confidence);
        self::assertSame(0.0, $decision->runnerUpConfidence);
    }

    public function testRoutesNoCandidateToReview(): void
    {
        $decision = (new MatchingEngine())->decide('Song', 'Artist', 180_000, []);

        self::assertSame('review', $decision->status);
        self::assertNull($decision->track);
    }

    private function track(string $id, string $title, string $artist, int $duration): SpotifyTrack
    {
        return new SpotifyTrack($id, 'spotify:track:' . $id, $title, [$artist], $duration);
    }
}
