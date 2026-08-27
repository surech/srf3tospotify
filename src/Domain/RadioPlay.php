<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RadioPlay
{
    public string $normalizedArtist;
    public string $normalizedTitle;

    public function __construct(
        public DateTimeImmutable $playedAtUtc,
        public int $sourceOffsetMinutes,
        public int $durationMs,
        public string $artist,
        public string $title,
        public bool $wasPlayingNow,
    ) {
        if ($durationMs < 0) {
            throw new InvalidArgumentException('Play duration cannot be negative.');
        }

        $this->normalizedArtist = TextNormalizer::normalize($artist);
        $this->normalizedTitle = TextNormalizer::normalize($title);
        if ($this->normalizedArtist === '' || $this->normalizedTitle === '') {
            throw new InvalidArgumentException('Play artist and title cannot be empty.');
        }
    }

    public function identityHash(): string
    {
        return hash('sha256', $this->normalizedArtist . "\0" . $this->normalizedTitle, true);
    }

    public function eventHash(string $channelId): string
    {
        return hash('sha256', implode("\0", [
            $channelId,
            $this->playedAtUtc->format('Y-m-d\TH:i:s.u\Z'),
            $this->normalizedArtist,
            $this->normalizedTitle,
            (string) $this->durationMs,
        ]), true);
    }
}
