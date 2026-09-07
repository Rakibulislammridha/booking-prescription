<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Records what tenancy looked like while the job ran (test-only; static state is fine in tests).
 */
final class RecordTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var array<int, array{tenant_id: int|null, search_path: string, payload_tenant_id: int|null}> */
    public static array $runs = [];

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $payload = $this->job?->payload() ?? [];

        self::$runs[] = [
            'tenant_id' => Tenancy::id(),
            'search_path' => (string) DB::scalar('show search_path'),
            'payload_tenant_id' => isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null,
        ];
    }
}
