<?php

declare(strict_types=1);

namespace App\Infrastructure\Srf;

use App\Domain\RadioPlay;
use DateTimeImmutable;

interface SrfSource
{
    /** @return list<RadioPlay> */
    public function fetch(DateTimeImmutable $fromLocal, DateTimeImmutable $toLocal): array;
}
