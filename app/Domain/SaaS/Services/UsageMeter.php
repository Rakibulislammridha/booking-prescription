<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of `public.usage_counters` (SCHEMA §5.8).
 *
 * `add()` is a single statement:
 *
 *     INSERT … ON CONFLICT (tenant_id, metric, period) DO UPDATE SET value = value + ? RETURNING value
 *
 * which matters for three reasons at once:
 *   1. it is the documented protocol;
 *   2. Postgres takes a row lock for the DO UPDATE, so concurrent writers are serialised and each one is handed a
 *      DISTINCT post-increment value — which is what makes `PlanLimits::reserve()` race-proof without an advisory
 *      lock or a SELECT … FOR UPDATE round trip;
 *   3. it runs on the caller's connection and therefore inside the caller's transaction: when the metered write
 *      rolls back, so does the count.
 *
 * The table lives in `public` and is always addressed schema-qualified, so this is safe to call while a tenant
 * search path is active — which is exactly where the metered writes happen.
 */
final class UsageMeter
{
    public const GAUGE_PERIOD = 'current';

    /** Gauges use 'current'; meters use the clinic-local calendar month, so a Dhaka clinic rolls over at midnight Dhaka. */
    public function period(Tenant $tenant, UsageMetric $metric, ?CarbonImmutable $at = null): string
    {
        if ($metric->isGauge()) {
            return self::GAUGE_PERIOD;
        }

        return ($at ?? CarbonImmutable::now())->setTimezone($tenant->timezone !== '' ? $tenant->timezone : 'Asia/Dhaka')->format('Y-m');
    }

    public function value(Tenant $tenant, UsageMetric $metric, ?string $period = null): int
    {
        $value = DB::connection('pgsql')->table('public.usage_counters')
            ->where('tenant_id', $tenant->id)
            ->where('metric', $metric->value)
            ->where('period', $period ?? $this->period($tenant, $metric))
            ->value('value');

        return $value === null ? 0 : (int) $value;
    }

    /**
     * Atomic `value += $delta`, clamped at zero (the table's CHECK), returning the value AFTER the write.
     * A negative delta is how a reservation is released and how a gauge follows a deletion.
     */
    public function add(Tenant $tenant, UsageMetric $metric, int $delta, ?int $limitSnapshot = null, ?string $period = null): int
    {
        $period ??= $this->period($tenant, $metric);
        $now = CarbonImmutable::now();

        /** @var array<int, object{value: int|string}> $rows */
        $rows = DB::connection('pgsql')->select(
            'insert into public.usage_counters (tenant_id, metric, period, value, limit_snapshot, created_at, updated_at)'
            .' values (?, ?, ?, greatest(0, ?), ?, ?, ?)'
            .' on conflict (tenant_id, metric, period) do update'
            .' set value = greatest(0, public.usage_counters.value + ?), limit_snapshot = excluded.limit_snapshot, updated_at = excluded.updated_at'
            .' returning value',
            [$tenant->id, $metric->value, $period, $delta, $limitSnapshot, $now, $now, $delta],
        );

        return (int) ($rows[0]->value ?? 0);
    }

    /** Absolute write — the nightly recount's only path, so gauge drift self-heals instead of accumulating. */
    public function set(Tenant $tenant, UsageMetric $metric, int $value, ?int $limitSnapshot = null, ?string $period = null): void
    {
        $now = CarbonImmutable::now();

        DB::connection('pgsql')->statement(
            'insert into public.usage_counters (tenant_id, metric, period, value, limit_snapshot, created_at, updated_at)'
            .' values (?, ?, ?, greatest(0, ?), ?, ?, ?)'
            .' on conflict (tenant_id, metric, period) do update'
            .' set value = excluded.value, limit_snapshot = excluded.limit_snapshot, updated_at = excluded.updated_at',
            [$tenant->id, $metric->value, $period ?? $this->period($tenant, $metric), $value, $limitSnapshot, $now, $now],
        );
    }

    /**
     * Every counter that is in force for this tenant right now (gauges + this month's meters).
     *
     * @return array<string, int> metric => value
     */
    public function current(Tenant $tenant, ?CarbonImmutable $at = null): array
    {
        $month = $this->period($tenant, UsageMetric::Appointments, $at);

        $rows = DB::connection('pgsql')->table('public.usage_counters')
            ->where('tenant_id', $tenant->id)
            ->whereIn('period', [self::GAUGE_PERIOD, $month])
            ->get(['metric', 'period', 'value']);

        $out = array_fill_keys(UsageMetric::values(), 0);

        foreach ($rows as $row) {
            $metric = UsageMetric::tryFrom((string) $row->metric);

            if ($metric === null) {
                continue;
            }

            if ($metric->isGauge() === ($row->period === self::GAUGE_PERIOD)) {
                $out[$metric->value] = (int) $row->value;
            }
        }

        return $out;
    }

    /**
     * The last 12 calendar months of one meter, oldest first — the super console's usage chart.
     *
     * @return array<int, array{period: string, value: int}>
     */
    public function history(Tenant $tenant, UsageMetric $metric, int $months = 12): array
    {
        if ($metric->isGauge()) {
            return [['period' => self::GAUGE_PERIOD, 'value' => $this->value($tenant, $metric)]];
        }

        $zone = $tenant->timezone !== '' ? $tenant->timezone : 'Asia/Dhaka';
        $cursor = CarbonImmutable::now($zone)->startOfMonth();
        $periods = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $periods[] = $cursor->subMonths($i)->format('Y-m');
        }

        $values = DB::connection('pgsql')->table('public.usage_counters')
            ->where('tenant_id', $tenant->id)
            ->where('metric', $metric->value)
            ->whereIn('period', $periods)
            ->pluck('value', 'period');

        return array_map(fn (string $period) => ['period' => $period, 'value' => (int) ($values[$period] ?? 0)], $periods);
    }
}
