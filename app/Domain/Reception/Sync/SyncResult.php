<?php

declare(strict_types=1);

namespace App\Domain\Reception\Sync;

use Carbon\CarbonImmutable;

/** The POST /api/reception/sync body (OFFLINE §7.1). */
final readonly class SyncResult
{
    /** @param  array<int, array<string, mixed>>  $results */
    public function __construct(public array $results, public bool $bootstrapStale) {}

    /** @return array{server_time: string, results: array<int, array<string, mixed>>, bootstrap_stale: bool} */
    public function toArray(): array
    {
        return [
            'server_time' => CarbonImmutable::now()->toIso8601String(),
            'results' => $this->results,
            'bootstrap_stale' => $this->bootstrapStale,
        ];
    }
}
