<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

final class TenantAwareRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAware;

    /** @var array<int, array{tenant_id: int|null, search_path: string}> */
    public static array $runs = [];

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        self::$runs[] = ['tenant_id' => Tenancy::id(), 'search_path' => (string) DB::scalar('show search_path')];
    }
}
