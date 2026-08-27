<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use App\Infrastructure\Spotify\SpotifyTrack;

final readonly class MatchDecision
{
    public function __construct(
        public string $status,
        public ?SpotifyTrack $track,
        public float $confidence,
        public float $runnerUpConfidence,
    ) {}

    public function accepted(): bool
    {
        return $this->status === 'accepted';
    }
}
