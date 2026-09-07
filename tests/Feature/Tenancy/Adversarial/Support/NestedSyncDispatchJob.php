<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Tests\Support\Jobs\RecordTenantJob;

/** A queued job that, while running, dispatches another job synchronously (dispatch_sync / sync connection). */
final class NestedSyncDispatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        dispatch_sync(new RecordTenantJob);
    }
}
