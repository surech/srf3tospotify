<?php

declare(strict_types=1);

namespace App\Application\Ranking;

use InvalidArgumentException;

final readonly class RankingFilter
{
    public function __construct(
        public bool $weekdaysOnly = false,
        public ?int $localStartMinute = null,
        public ?int $localEndMinute = null,
    ) {
        if (($localStartMinute === null) !== ($localEndMinute === null)) {
            throw new InvalidArgumentException('Local ranking start and end minute must be configured together.');
        }
        if ($localStartMinute !== null && $localEndMinute !== null
            && ($localStartMinute < 0 || $localEndMinute > 1440 || $localStartMinute >= $localEndMinute)
        ) {
            throw new InvalidArgumentException('Local ranking minutes must define an increasing range within one day.');
        }
    }
}
