<?php

declare(strict_types=1);

namespace App\Domain\Queue\Console;

use App\Domain\Queue\Services\QueueBroadcaster;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * REALTIME.md §4.3 — run every minute through `tenants:run queue:refresh-eta` (App\Domain\Queue\Schedule): rebuild
 * every `running` session whose current call is older than the stale window so the `eta` values keep moving even when
 * no event fires. `version` is bumped first (CountsRecalculator::bumpVersion) so ETag clients see a new document.
 */
final class RefreshEtaCommand extends Command
{
    public const STALE_SECONDS = 60;

    protected $signature = 'queue:refresh-eta {--stale=60 : seconds since the current call before a session is refreshed}';

    protected $description = 'Rebuild the QueueState of running sessions so ETAs keep moving (REALTIME.md §4.3)';

    public function handle(QueueStateRepository $repository, QueueBroadcaster $broadcaster): int
    {
        if (! Tenancy::check()) {
            $this->components->error('queue:refresh-eta must run inside a tenant (tenants:run queue:refresh-eta).');

            return self::FAILURE;
        }

        $stale = max(0, (int) $this->option('stale'));
        $cutoff = now()->subSeconds($stale);
        $refreshed = 0;
        $cached = 0;

        SessionInstance::query()
            ->with(['doctor', 'branch'])
            ->where('status', SessionStatus::Running->value)
            ->where(fn ($q) => $q->whereNull('last_called_at')->orWhere('last_called_at', '<=', $cutoff))
            ->orderBy('id')
            ->chunkById(50, function ($sessions) use ($repository, $broadcaster, &$refreshed, &$cached): void {
                foreach ($sessions as $session) {
                    CountsRecalculator::bumpVersion($session->id);
                    $state = $repository->rebuild($session->refresh());
                    $broadcaster->queueState($session, $state);
                    // serials.estimated_call_at for slips/SMS lives on this path only (REALTIME.md §14.1).
                    $cached += $repository->refreshEtaCache($session, $state);
                    $refreshed++;
                }
            });

        $this->components->info("queue:refresh-eta: {$refreshed} session(s) refreshed, {$cached} cached ETA(s) moved.");

        return self::SUCCESS;
    }
}
