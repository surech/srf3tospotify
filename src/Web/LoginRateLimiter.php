<?php

declare(strict_types=1);

namespace App\Web;

interface LoginRateLimiter
{
    public function retryAfterSeconds(string $clientAddress): int;

    public function recordFailure(string $clientAddress): void;

    public function clear(string $clientAddress): void;
}
