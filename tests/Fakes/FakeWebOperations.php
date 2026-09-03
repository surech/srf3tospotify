<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Web\WebOperations;
use Throwable;

final class FakeWebOperations implements WebOperations
{
    /** @var list<array{from: string, to: string, trigger: string}> */
    public array $imports = [];

    /** @var list<string> */
    public array $synchronizations = [];

    /** @var list<array{state: string, redirect_uri: string}> */
    public array $authorizations = [];

    /** @var list<array{code: string, redirect_uri: string}> */
    public array $exchanges = [];

    /** @var list<array{song_id: int, track: string}> */
    public array $selectedMatches = [];

    /** @var list<int> */
    public array $rejectedMatches = [];

    /** @var list<array<string, mixed>> */
    public array $ranking = [];

    public int $migrations = 0;

    public ?Throwable $dashboardException = null;
    public ?Throwable $importException = null;
    public ?Throwable $synchronizeException = null;

    public function dashboard(): array
    {
        if ($this->dashboardException !== null) {
            throw $this->dashboardException;
        }

        return [
            'statistics' => ['plays' => 0, 'songs' => 0, 'unresolved' => 0, 'last_import' => null, 'last_sync' => null],
            'ranking' => $this->ranking,
            'unresolved_matches' => [],
            'recent_imports' => [],
            'recent_syncs' => [],
        ];
    }

    public function import(string $fromDate, string $toDate, string $trigger): array
    {
        if ($this->importException !== null) {
            throw $this->importException;
        }
        $this->imports[] = ['from' => $fromDate, 'to' => $toDate, 'trigger' => $trigger];

        return ['counts' => ['received' => 1, 'inserted' => 1, 'duplicates' => 0]];
    }

    public function synchronize(string $trigger): array
    {
        if ($this->synchronizeException !== null) {
            throw $this->synchronizeException;
        }
        $this->synchronizations[] = $trigger;

        return [
            'playlist_count' => 2,
            'track_count' => 3,
            'unresolved_count' => 0,
            'total_track_count' => 4,
            'total_unresolved_count' => 0,
        ];
    }

    public function migrate(): array
    {
        ++$this->migrations;

        return ['applied' => [], 'skipped' => ['001_initial']];
    }

    public function selectMatch(int $songId, string $trackReference): array
    {
        $this->selectedMatches[] = ['song_id' => $songId, 'track' => $trackReference];

        return ['song_id' => $songId, 'track_id' => $trackReference, 'status' => 'accepted'];
    }

    public function rejectMatch(int $songId): array
    {
        $this->rejectedMatches[] = $songId;

        return ['song_id' => $songId, 'status' => 'rejected'];
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $this->authorizations[] = ['state' => $state, 'redirect_uri' => $redirectUri];

        return 'https://accounts.spotify.test/authorize?state=' . rawurlencode($state);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri): void
    {
        $this->exchanges[] = ['code' => $code, 'redirect_uri' => $redirectUri];
    }
}
