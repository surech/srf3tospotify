<?php

declare(strict_types=1);

namespace App\Web;

use RuntimeException;

final class LoginRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many login attempts.');
    }
}
