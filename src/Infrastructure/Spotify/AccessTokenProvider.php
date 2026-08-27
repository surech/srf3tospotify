<?php

declare(strict_types=1);

namespace App\Infrastructure\Spotify;

interface AccessTokenProvider
{
    public function accessToken(): string;
}
