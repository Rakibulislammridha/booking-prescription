<?php

declare(strict_types=1);

namespace App\Domain\Reception\Sync;

use App\Models\Tenant\OfflineEvent;

/** App\Domain\Reception\Handlers\<Type>Handler — called inside SyncReplayer's per-event transaction. */
interface ReplayHandler
{
    public function handle(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution = null): ReplayOutcome;
}
