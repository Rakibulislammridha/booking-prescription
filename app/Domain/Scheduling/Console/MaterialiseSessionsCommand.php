<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Console;

use App\Domain\Scheduling\Jobs\MaterialiseTenantSessions;
use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Support\Clock;
use App\Tenancy\Console\ResolvesTenants;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * sessions:materialise {--days=14} {--tenant=*} {--date=} {--sync} — runs in central context and dispatches one
 * MaterialiseTenantSessions per active tenant (SERIAL_ENGINE §2.2); --date limits the sweep to one day, --sync runs
 * inline (the concurrency test spawns 12 of these against one date). Registered by SchedulingServiceProvider
 * (app/Console is foundation-owned, so the class lives under the module).
 */
final class MaterialiseSessionsCommand extends Command
{
    use ResolvesTenants;

    protected $signature = 'sessions:materialise {--days=14 : Horizon in days from today} {--tenant=* : ids or slugs} {--date= : Materialise only this date (Y-m-d)} {--sync : Run inline instead of dispatching}';

    protected $description = 'Materialise session instances for the coming days in every (or the selected) tenant';

    public function handle(): int
    {
        $date = $this->option('date');
        $days = max(0, (int) $this->option('days'));

        $dispatched = 0;

        foreach ($this->resolveTenants() as $tenant) {
            $tz = $tenant->timezone ?: Clock::DEFAULT_TIMEZONE;
            $from = is_string($date) && $date !== '' ? CarbonImmutable::parse($date, $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfDay();
            $to = is_string($date) && $date !== '' ? $from : $from->addDays($days);

            $job = (new MaterialiseTenantSessions($from->toDateString(), $to->toDateString()))->forTenant($tenant);

            if ((bool) $this->option('sync')) {
                $created = Tenancy::run($tenant, fn () => $job->handle(app(SessionMaterialiser::class)));
                $this->components->info("Tenant #{$tenant->id} [{$tenant->slug}]: {$created} instance(s) created for {$from->toDateString()}..{$to->toDateString()}");
            } else {
                dispatch($job);
            }

            $dispatched++;
        }

        $this->components->info("{$dispatched} tenant(s) processed.");

        return self::SUCCESS;
    }
}
