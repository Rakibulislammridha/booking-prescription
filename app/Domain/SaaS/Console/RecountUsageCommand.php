<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\PlanLimits;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `saas:recount-usage` — the nightly truth for the three gauges (SCHEMA §5.8).
 *
 * The incremental ±1 in the observers is what makes the cap enforceable in real time; this is what makes it
 * CORRECT over months. A row inserted by a raw `DB::table()` write, a restore from a backup, a schema swap or a
 * bug in a delete path all move the real count without moving the counter, and an absolute `COUNT(*)` per tenant
 * per night means the drift never accumulates.
 *
 * Monthly meters are deliberately not recounted: they are historical facts about what happened, not a census of
 * what exists, and a "recount" of last month's SMS would be a fiction.
 */
final class RecountUsageCommand extends Command
{
    protected $signature = 'saas:recount-usage {--tenant=* : Slugs or ids; default is every servable tenant}';

    protected $description = 'Recompute the doctors / branches / storage_bytes gauges from the tenant schemas';

    public function handle(PlanLimits $limits): int
    {
        if (Tenancy::check()) {
            $this->components->error('saas:recount-usage is central work and must not run inside a tenant.');

            return self::FAILURE;
        }

        /** @var array<int, string> $only */
        $only = (array) $this->option('tenant');

        $tenants = Tenant::query()
            ->when($only !== [], fn ($q) => $q->where(fn ($w) => $w->whereIn('slug', $only)->orWhereIn('id', array_filter($only, 'is_numeric'))))
            ->when($only === [], fn ($q) => $q->active())
            ->whereNotNull('provisioned_at')
            ->orderBy('id')
            ->get();

        $done = 0;

        foreach ($tenants as $tenant) {
            try {
                $counts = Tenancy::run($tenant, fn (): array => [
                    UsageMetric::Branches->value => (int) DB::table('branches')->where('is_active', true)->whereNull('deleted_at')->count(),
                    UsageMetric::Doctors->value => (int) DB::table('doctors')->where('is_active', true)->whereNull('deleted_at')->count(),
                    UsageMetric::StorageBytes->value => (int) DB::table('patient_documents')->sum('size_bytes'),
                ]);

                foreach ($counts as $metric => $value) {
                    $limits->recount($tenant, UsageMetric::from((string) $metric), $value);
                }

                $this->components->twoColumnDetail($tenant->slug, sprintf('%d branches · %d doctors · %s bytes', $counts['branches'], $counts['doctors'], number_format($counts['storage_bytes'])));
                $done++;
            } catch (Throwable $e) {
                Log::error('saas.recount.failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
                $this->components->warn($tenant->slug.': '.$e->getMessage());
            }
        }

        $this->components->info("Recounted {$done} tenant(s).");

        return self::SUCCESS;
    }
}
