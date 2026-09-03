<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use App\Domain\TextNormalizer;
use App\Infrastructure\Spotify\SpotifyTrack;

final class MatchingEngine
{
    private const DUPLICATE_DURATION_TOLERANCE_MS = 1_000;

    /** @param list<SpotifyTrack> $candidates */
    public function decide(string $title, string $artist, int $durationMs, array $candidates): MatchDecision
    {
        $scored = [];
        foreach ($candidates as $candidate) {
            $duplicate = false;
            foreach ($scored as $existing) {
                if (
                    $existing['track']->title === $candidate->title
                    && $existing['track']->artists === $candidate->artists
                    && abs($existing['track']->durationMs - $candidate->durationMs)
                        <= self::DUPLICATE_DURATION_TOLERANCE_MS
                ) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $scored[] = [
                    'track' => $candidate,
                    'score' => $this->score($title, $artist, $durationMs, $candidate),
                ];
            }
        }
        usort($scored, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);

        if ($scored === []) {
            return new MatchDecision('review', null, 0.0, 0.0);
        }
        $best = $scored[0];
        $runnerUp = $scored[1]['score'] ?? 0.0;
        $accepted = $best['score'] >= 0.90 && $best['score'] - $runnerUp >= 0.10;

        return new MatchDecision(
            $accepted ? 'accepted' : 'review',
            $best['track'],
            $best['score'],
            $runnerUp,
        );
    }

    private function score(string $title, string $artist, int $durationMs, SpotifyTrack $candidate): float
    {
        $targetTitle = $this->searchText($title);
        $candidateTitle = $this->searchText($candidate->title);
        $targetArtist = $this->searchText($artist);
        $candidateArtist = $this->searchText($candidate->artistLabel());

        $titleScore = $this->similarity($targetTitle, $candidateTitle);
        $artistScore = $this->similarity($targetArtist, $candidateArtist);
        $durationDifference = abs($durationMs - $candidate->durationMs);
        $durationScore = max(0.0, 1.0 - ($durationDifference / 30_000));
        $versionPenalty = $this->hasUnrequestedVersion($targetTitle, $candidateTitle) ? 0.15 : 0.0;

        return max(0.0, min(1.0, 0.55 * $titleScore + 0.35 * $artistScore + 0.10 * $durationScore - $versionPenalty));
    }

    private function searchText(string $value): string
    {
        $withoutCountryMarker = preg_replace('/\s*\(ch\)\s*/iu', ' ', $value) ?? $value;
        $plain = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $withoutCountryMarker) ?? $withoutCountryMarker;

        return TextNormalizer::normalize($plain);
    }

    private function similarity(string $left, string $right): float
    {
        if ($left === $right) {
            return 1.0;
        }
        $leftLength = mb_strlen($left);
        $rightLength = mb_strlen($right);
        $maximum = max($leftLength, $rightLength);
        if ($maximum === 0) {
            return 1.0;
        }

        similar_text($left, $right, $percent);

        return $percent / 100;
    }

    private function hasUnrequestedVersion(string $targetTitle, string $candidateTitle): bool
    {
        foreach (['live', 'karaoke', 'tribute', 'remix', 'acoustic'] as $marker) {
            if (!str_contains($targetTitle, $marker) && str_contains($candidateTitle, $marker)) {
                return true;
            }
        }

        return false;
    }
}
