<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Infrastructure\Spotify\AccessTokenProvider;

final readonly class StaticAccessTokenProvider implements AccessTokenProvider
{
    public function __construct(private string $token = 'test-access-token') {}

    public function accessToken(): string
    {
        return $this->token;
    }
}
