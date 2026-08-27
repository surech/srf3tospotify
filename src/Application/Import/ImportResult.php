<?php

declare(strict_types=1);

namespace App\Application\Import;

final readonly class ImportResult
{
    public function __construct(
        public string $correlationId,
        public int $received,
        public int $inserted,
        public int $duplicates,
    ) {}

    /** @return array{status: string, correlation_id: string, counts: array{received: int, inserted: int, duplicates: int}} */
    public function toArray(): array
    {
        return [
            'status' => 'succeeded',
            'correlation_id' => $this->correlationId,
            'counts' => [
                'received' => $this->received,
                'inserted' => $this->inserted,
                'duplicates' => $this->duplicates,
            ],
        ];
    }
}
