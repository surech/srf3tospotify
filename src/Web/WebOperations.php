<?php

declare(strict_types=1);

namespace App\Web;

interface WebOperations
{
    /** @return array<string, mixed> */
    public function dashboard(): array;

    /** @return array<string, mixed> */
    public function import(string $fromDate, string $toDate, string $trigger): array;

    /** @return array<string, mixed> */
    public function synchronize(string $trigger): array;

    /** @return array<string, mixed> */
    public function migrate(): array;

    /** @return array<string, mixed> */
    public function selectMatch(int $songId, string $trackReference): array;

    /** @return array<string, mixed> */
    public function rejectMatch(int $songId): array;

    public function authorizationUrl(string $state, string $redirectUri): string;

    public function exchangeAuthorizationCode(string $code, string $redirectUri): void;
}
