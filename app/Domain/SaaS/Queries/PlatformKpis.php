<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\QueueHealth;
use App\Domain\SaaS\Services\UsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's tiles — the operator's morning glance. Every number is one aggregate query (or one cached
 * strip, for appointments) so the screen costs the same for ten clinics and a thousand.
 *
 * @phpstan-type Kpis array{
 *   trials_ending_7d: int,
 *   past_due: array{tenants: int, invoices: int, paisa: int},
 *   revenue: array{mrr_paisa: int, arr_paisa: int, outstanding_paisa: int, outstanding_invoices: int, collected_month_paisa: int, collected_month_payments: int},
 *   signups_month: int,
 *   appointments_today: int,
 *   sms_near_limit: int,
 *   over_limit: int,
 *   backups: array{never: int, stale: int, oldest_hours: int|null, worst: array{public_id: string, name: string, slug: string, last_backup_at: string|null}|null},
 *   queue: array{available: bool, running: bool, depth: int, longest_wait: int, queues: array<int, array{name: string, length: int, wait: int}>, failed: int, failed_recent: int, horizon_url: string}
 * }
 */
final class PlatformKpis
{
    /** A meter at or past this share of its cap is "nearly exhausted". */
    public const NEAR_PERCENT = 80;

    /** A clinic whose last backup is older than this is stale. */
    public const STALE_BACKUP_HOURS = 48;

    public function __construct(
        private readonly PlatformTrend $trend,
        private readonly QueueHealth $queues,
        private readonly TenantLimitsSnapshot $limits,
        private readonly PlatformRevenueSummary $revenue,
    ) {}

    /** @return Kpis */
    public function all(): array
    {
        $now = CarbonImmutable::now();
        $local = $now->setTimezone('Asia/Dhaka');

        // The money is Billing's read model (PlatformRevenueSummary), not re-derived here: past-due clinics are
        // the ones whose CURRENT subscription is past_due, an overdue invoice is an open one whose due date has
        // passed, and MRR counts yearly plans at one twelfth — the same numbers the Billing screens show.
        $revenue = $this->revenue->summary($now);

        return [
            'trials_ending_7d' => (int) DB::connection('pgsql')->table('public.tenants')->whereNull('deleted_at')
                ->where('status', TenantStatus::Trial->value)->whereBetween('trial_ends_at', [$now, $now->addDays(7)])->count(),
            'past_due' => ['tenants' => $revenue['past_due'], 'invoices' => $revenue['overdue_invoices'], 'paisa' => $revenue['overdue_paisa']],
            'revenue' => [
                'mrr_paisa' => $revenue['mrr_paisa'],
                'arr_paisa' => $revenue['arr_paisa'],
                'outstanding_paisa' => $revenue['outstanding_paisa'],
                'outstanding_invoices' => $revenue['outstanding_invoices'],
                'collected_month_paisa' => $revenue['collected_month_paisa'],
                'collected_month_payments' => $revenue['collected_month_payments'],
            ],
            'signups_month' => (int) DB::connection('pgsql')->table('public.tenants')->whereNull('deleted_at')
                ->where('created_at', '>=', $local->startOfMonth()->utc())->count(),
            'appointments_today' => $this->trend->appointmentsToday(),
            'sms_near_limit' => count($this->nearLimit(UsageMetric::SmsCredits, $local->format('Y-m'))),
            'over_limit' => $this->overAnyLimit($local->format('Y-m')),
            'backups' => $this->backups($now),
            'queue' => $this->queues->snapshot(),
        ];
    }

    /**
     * Tenants at or past NEAR_PERCENT of a metered cap this month (unlimited caps never qualify).
     *
     * @return array<int, int> tenant ids
     */
    public function nearLimit(UsageMetric $metric, string $period): array
    {
        $limits = $this->limits->forMetric($metric);
        $usage = DB::connection('pgsql')->table('public.usage_counters')
            ->where('metric', $metric->value)->where('period', $metric->isGauge() ? UsageMeter::GAUGE_PERIOD : $period)
            ->pluck('value', 'tenant_id');
        $out = [];

        foreach ($limits as $tenantId => $limit) {
            if ($limit === null) {
                continue;
            }

            $used = (int) ($usage[$tenantId] ?? 0);

            if ($limit === 0 ? $used > 0 : ($used * 100 / $limit) >= self::NEAR_PERCENT) {
                $out[] = (int) $tenantId;
            }
        }

        return $out;
    }

    /** Tenants past ANY capped metric right now. */
    public function overAnyLimit(string $period): int
    {
        $over = [];

        foreach (UsageMetric::cases() as $metric) {
            if (! $metric->isCapped()) {
                continue;
            }

            $limits = $this->limits->forMetric($metric);
            $usage = DB::connection('pgsql')->table('public.usage_counters')
                ->where('metric', $metric->value)->where('period', $metric->isGauge() ? UsageMeter::GAUGE_PERIOD : $period)
                ->pluck('value', 'tenant_id');

            foreach ($limits as $tenantId => $limit) {
                if ($limit !== null && (int) ($usage[$tenantId] ?? 0) >= $limit && ($limit > 0 || (int) ($usage[$tenantId] ?? 0) > 0)) {
                    $over[(int) $tenantId] = true;
                }
            }
        }

        return count($over);
    }

    /** @return array{never: int, stale: int, oldest_hours: int|null, worst: array{public_id: string, name: string, slug: string, last_backup_at: string|null}|null} */
    private function backups(CarbonImmutable $now): array
    {
        $live = DB::connection('pgsql')->table('public.tenants')
            ->whereNull('deleted_at')->whereNotNull('provisioned_at')
            ->whereNotIn('status', [TenantStatus::Cancelled->value]);

        $never = (int) (clone $live)->whereNull('last_backup_at')->count();
        $stale = (int) (clone $live)->where('last_backup_at', '<', $now->subHours(self::STALE_BACKUP_HOURS))->count();

        $worst = (clone $live)->orderByRaw('last_backup_at asc nulls first')->orderBy('id')->first(['public_id', 'name', 'slug', 'last_backup_at']);

        $oldest = null;

        if ($worst !== null && $worst->last_backup_at !== null) {
            $oldest = (int) CarbonImmutable::parse((string) $worst->last_backup_at)->diffInHours($now, true);
        }

        return [
            'never' => $never,
            'stale' => $stale,
            'oldest_hours' => $oldest,
            'worst' => $worst === null ? null : [
                'public_id' => (string) $worst->public_id,
                'name' => (string) $worst->name,
                'slug' => (string) $worst->slug,
                'last_backup_at' => $worst->last_backup_at === null ? null : CarbonImmutable::parse((string) $worst->last_backup_at)->toIso8601String(),
            ],
        ];
    }
}
