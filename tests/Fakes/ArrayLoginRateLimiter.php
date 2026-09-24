<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Web\LoginRateLimiter;

final class ArrayLoginRateLimiter implements LoginRateLimiter
{
    /** @var array<string, int> */
    public array $failures = [];

    public function retryAfterSeconds(string $clientAddress): int
    {
        return ($this->failures[$clientAddress] ?? 0) >= 5 ? 900 : 0;
    }

    public function recordFailure(string $clientAddress): void
    {
        $this->failures[$clientAddress] = ($this->failures[$clientAddress] ?? 0) + 1;
    }

    public function clear(string $clientAddress): void
    {
        unset($this->failures[$clientAddress]);
    }
}
