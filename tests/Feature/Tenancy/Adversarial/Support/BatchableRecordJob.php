<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial\Support;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** RecordTenantJob for Bus::batch(). */
final class BatchableRecordJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    /** @var array<int, array{tenant_id: int|null, search_path: string}> */
    public static array $runs = [];

    public function handle(): void
    {
        self::$runs[] = ['tenant_id' => Tenancy::id(), 'search_path' => (string) DB::scalar('show search_path')];
    }
}
