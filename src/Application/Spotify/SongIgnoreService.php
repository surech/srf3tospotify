<?php

declare(strict_types=1);

namespace App\Application\Spotify;

use App\Infrastructure\Database\SongIgnoreRepository;
use App\Infrastructure\Database\SongIgnoreRule;
use InvalidArgumentException;

final readonly class SongIgnoreService
{
    public function __construct(private SongIgnoreRepository $repository) {}

    public function ignore(int $songId, ?int $playlistId, ?string $reason): SongIgnoreRule
    {
        if ($songId < 1 || ($playlistId !== null && $playlistId < 1)) {
            throw new InvalidArgumentException('Song and playlist IDs must be positive.');
        }

        $normalizedReason = $reason === null ? null : trim($reason);
        if ($normalizedReason === '') {
            $normalizedReason = null;
        }
        if ($normalizedReason !== null && mb_strlen($normalizedReason) > 500) {
            throw new InvalidArgumentException('Ignore reason must not exceed 500 characters.');
        }

        return $this->repository->create($songId, $playlistId, $normalizedReason);
    }

    public function reactivate(int $ruleId): SongIgnoreRule
    {
        if ($ruleId < 1) {
            throw new InvalidArgumentException('Ignored song rule ID must be positive.');
        }

        return $this->repository->reactivate($ruleId);
    }

    /** @return list<SongIgnoreRule> */
    public function rules(bool $includeHistory = false): array
    {
        return $this->repository->rules($includeHistory);
    }
}
