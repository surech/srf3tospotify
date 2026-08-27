<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Domain\RadioPlay;
use App\Infrastructure\Srf\SrfSource;
use DateTimeImmutable;

final readonly class StaticSrfSource implements SrfSource
{
    /** @param list<RadioPlay> $plays */
    public function __construct(private array $plays) {}

    public function fetch(DateTimeImmutable $fromLocal, DateTimeImmutable $toLocal): array
    {
        return $this->plays;
    }
}
