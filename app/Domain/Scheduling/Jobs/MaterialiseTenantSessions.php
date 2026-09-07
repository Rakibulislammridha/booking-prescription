<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Jobs;

use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Support\Clock;
use App\Tenancy\Queue\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One job per active tenant, dispatched by sessions:materialise (SERIAL_ENGINE §2.2): materialises [from, to] in the
 * tenant's timezone. Idempotent — creation is INSERT … ON CONFLICT DO NOTHING.
 */
final class MaterialiseTenantSessions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAware;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly string $from, public readonly string $to)
    {
        $this->onQueue('default');
    }

    public function handle(SessionMaterialiser $materialiser): int
    {
        $tz = Clock::timezone();

        return $materialiser->materialiseRange(CarbonImmutable::parse($this->from, $tz), CarbonImmutable::parse($this->to, $tz));
    }
}
