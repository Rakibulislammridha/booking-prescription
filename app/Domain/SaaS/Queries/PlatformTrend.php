<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's thirty-day strip: sign-ups per day (one grouped query over `public.tenants`) and appointments
 * per day across every clinic.
 *
 * Appointments live in each tenant's schema and nothing central counts them by day (`usage_counters` is
 * monthly), so this is the one place the console reads tenant tables — as a single `UNION ALL` of one grouped
 * count per provisioned schema, schema-qualified so it runs on the central search path, and cached for five
 * minutes because the morning screen is opened by several people and the answer does not change by the second.
 * A schema name is interpolated only after matching `^[a-z0-9_]+$`; anything else is skipped, not quoted.
 *
 * @phpstan-type Day array{day: string, signups: int, appointments: int}
 */
final class PlatformTrend
{
    public const DAYS = 30;

    private const CACHE_KEY = 'bp:super:trend';

    private const TTL = 300;

    public function __construct(private readonly Cache $cache) {}

    /** @return array<int, Day> oldest first, every day present */
    public function last30Days(): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();
        $from = $today->subDays(self::DAYS - 1);

        /** @var array<int, Day> $days */
        $days = $this->cache->remember(self::CACHE_KEY.':'.$today->toDateString(), self::TTL, function () use ($from, $today): array {
            $signups = $this->signupsByDay($from, $today);
            $appointments = $this->appointmentsByDay($from, $today);
            $out = [];

            for ($cursor = $from; $cursor <= $today; $cursor = $cursor->addDay()) {
                $day = $cursor->toDateString();
                $out[] = ['day' => $day, 'signups' => $signups[$day] ?? 0, 'appointments' => $appointments[$day] ?? 0];
            }

            return $out;
        });

        return $days;
    }

    /** Today's appointments across the platform, from the same cached strip. */
    public function appointmentsToday(): int
    {
        $days = $this->last30Days();

        return $days === [] ? 0 : $days[count($days) - 1]['appointments'];
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY.':'.CarbonImmutable::now('Asia/Dhaka')->toDateString());
    }

    /** @return array<string, int> */
    private function signupsByDay(CarbonImmutable $from, CarbonImmutable $today): array
    {
        $rows = DB::connection('pgsql')->table('public.tenants')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $from->utc())
            ->selectRaw("to_char(created_at at time zone 'Asia/Dhaka', 'YYYY-MM-DD') as day, count(*) as total")
            ->groupBy('day')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->day] = (int) $row->total;
        }

        return $out;
    }

    /** @return array<string, int> */
    private function appointmentsByDay(CarbonImmutable $from, CarbonImmutable $today): array
    {
        $schemas = DB::connection('pgsql')->table('public.tenants')
            ->whereNull('deleted_at')
            ->whereNotNull('provisioned_at')
            ->where('status', '!=', TenantStatus::Cancelled->value)
            ->pluck('schema_name')
            ->filter(fn ($schema): bool => is_string($schema) && preg_match('/^[a-z0-9_]+$/', $schema) === 1)
            ->values()
            ->all();

        if ($schemas === []) {
            return [];
        }

        $parts = [];
        $bindings = [];

        foreach ($schemas as $schema) {
            $parts[] = 'select scheduled_date as day, count(*) as total from "'.$schema.'".appointments'
                ." where scheduled_date between ? and ? and status not in ('cancelled', 'draft') group by scheduled_date";
            $bindings[] = $from->toDateString();
            $bindings[] = $today->toDateString();
        }

        $rows = DB::connection('pgsql')->select('select day, sum(total) as total from ('.implode(' union all ', $parts).') as per_tenant group by day', $bindings);
        $out = [];

        foreach ($rows as $row) {
            $day = $row->day;
            $out[$day instanceof \DateTimeInterface ? $day->format('Y-m-d') : (string) $day] = (int) $row->total;
        }

        return $out;
    }
}
