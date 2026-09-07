<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daily / monthly collection by doctor, branch, user and payment method (BRIEF §5.L). Exposed as a query object
 * so the Reports module can reuse it instead of the numbers being re-derived in a page controller.
 *
 * Every figure is `settled payments − processed refunds` in paisa, grouped on the CLINIC-LOCAL day (the report a
 * receptionist recognises is "today in Dhaka", not "today in UTC").
 */
final class CollectionReportQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        [$start, $end] = $this->window($from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $this->totals($start, $end, $filters),
            'by_method' => $this->grouped($start, $end, $filters, 'p.method', 'method'),
            'by_day' => $this->byDay($start, $end, $filters),
            'by_doctor' => $this->groupedWithLabel($start, $end, $filters, 'i.doctor_id', 'doctors', 'name'),
            'by_branch' => $this->groupedWithLabel($start, $end, $filters, 'i.branch_id', 'branches', 'name'),
            'by_user' => $this->groupedWithLabel($start, $end, $filters, 'p.received_by_user_id', 'users', 'name'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{gross_paisa: int, refunds_paisa: int, net_paisa: int, payment_count: int, invoice_count: int}
     */
    public function totals(CarbonImmutable $start, CarbonImmutable $end, array $filters = []): array
    {
        $row = $this->paymentQuery($start, $end, $filters)
            ->selectRaw('coalesce(sum(p.amount_paisa), 0) as gross, count(*) as n, count(distinct p.invoice_id) as invoices')
            ->first();

        $refunds = (int) $this->refundQuery($start, $end, $filters)->sum('r.amount_paisa');
        $grossPaisa = self::int($row, 'gross');

        return [
            'gross_paisa' => $grossPaisa,
            'refunds_paisa' => $refunds,
            'net_paisa' => $grossPaisa - $refunds,
            'payment_count' => self::int($row, 'n'),
            'invoice_count' => self::int($row, 'invoices'),
        ];
    }

    /** Query-builder rows come back as stdClass; read them without pretending they are models. */
    private static function int(mixed $row, string $key): int
    {
        return is_object($row) && isset($row->{$key}) ? (int) $row->{$key} : 0;
    }

    private static function str(mixed $row, string $key): ?string
    {
        return is_object($row) && isset($row->{$key}) ? (string) $row->{$key} : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function grouped(CarbonImmutable $start, CarbonImmutable $end, array $filters, string $column, string $alias): array
    {
        $gross = $this->paymentQuery($start, $end, $filters)
            ->selectRaw("{$column} as k, sum(p.amount_paisa) as gross, count(*) as n")
            ->groupBy(DB::raw($column))
            ->get();

        $refunds = $this->refundQuery($start, $end, $filters)
            ->selectRaw("{$column} as k, sum(r.amount_paisa) as refunded")
            ->groupBy(DB::raw($column))
            ->pluck('refunded', 'k')
            ->all();

        $rows = [];

        foreach ($gross as $row) {
            $key = self::str($row, 'k');
            $refunded = (int) ($refunds[$key] ?? 0);
            $grossPaisa = self::int($row, 'gross');

            $rows[] = [
                $alias => $key,
                'key' => $key,
                'gross_paisa' => $grossPaisa,
                'refunds_paisa' => $refunded,
                'net_paisa' => $grossPaisa - $refunded,
                'count' => self::int($row, 'n'),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['net_paisa'] <=> $a['net_paisa']);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function groupedWithLabel(CarbonImmutable $start, CarbonImmutable $end, array $filters, string $column, string $table, string $labelColumn): array
    {
        $rows = $this->grouped($start, $end, $filters, $column, 'key');
        $ids = array_values(array_filter(array_map(fn (array $r) => $r['key'] === null ? null : (int) $r['key'], $rows)));
        $labels = $ids === [] ? [] : DB::table($table)->whereIn('id', $ids)->pluck($labelColumn, 'id')->all();

        return array_map(function (array $row) use ($labels): array {
            $row['label'] = $row['key'] === null ? null : (string) ($labels[(int) $row['key']] ?? $row['key']);

            return $row;
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array{date: string, gross_paisa: int, refunds_paisa: int, net_paisa: int, count: int}>
     */
    private function byDay(CarbonImmutable $start, CarbonImmutable $end, array $filters): array
    {
        // The same expression text in SELECT and GROUP BY (Postgres matches them syntactically), and the zone is
        // a validated literal rather than a placeholder so the bindings of the WHERE clause stay in order.
        $grossDay = self::localDay('p.paid_at');
        $refundDay = self::localDay('r.processed_at');

        $gross = $this->paymentQuery($start, $end, $filters)
            ->selectRaw("{$grossDay} as d, sum(p.amount_paisa) as gross, count(*) as n")
            ->groupBy(DB::raw($grossDay))
            ->orderBy('d')
            ->get();

        $refunds = $this->refundQuery($start, $end, $filters)
            ->selectRaw("{$refundDay} as d, sum(r.amount_paisa) as refunded")
            ->groupBy(DB::raw($refundDay))
            ->pluck('refunded', 'd')
            ->all();

        $rows = [];

        foreach ($gross as $row) {
            $day = self::str($row, 'd') ?? '';
            $refunded = (int) ($refunds[$day] ?? 0);
            $grossPaisa = self::int($row, 'gross');

            $rows[] = [
                'date' => $day,
                'gross_paisa' => $grossPaisa,
                'refunds_paisa' => $refunded,
                'net_paisa' => $grossPaisa - $refunded,
                'count' => self::int($row, 'n'),
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $filters */
    private function paymentQuery(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        $query = DB::table('payments as p')
            ->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->whereIn('p.status', [PaymentTxnStatus::Succeeded->value, PaymentTxnStatus::Refunded->value, PaymentTxnStatus::PartiallyRefunded->value])
            ->whereNotNull('p.paid_at')
            ->whereBetween('p.paid_at', [$start, $end]);

        return $this->applyFilters($query, $filters);
    }

    /** @param array<string, mixed> $filters */
    private function refundQuery(CarbonImmutable $start, CarbonImmutable $end, array $filters): Builder
    {
        $query = DB::table('refunds as r')
            ->join('invoices as i', 'i.id', '=', 'r.invoice_id')
            ->join('payments as p', 'p.id', '=', 'r.payment_id')
            ->where('r.status', RefundStatus::Processed->value)
            ->whereNotNull('r.processed_at')
            ->whereBetween('r.processed_at', [$start, $end]);

        return $this->applyFilters($query, $filters);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['branch_id'])) {
            $query->where('i.branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['doctor_id'])) {
            $query->where('i.doctor_id', (int) $filters['doctor_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('p.received_by_user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['method'])) {
            $query->where('p.method', (string) $filters['method']);
        }

        return $query;
    }

    /** `to_char(<column> at time zone 'Asia/Dhaka', 'YYYY-MM-DD')` — the clinic's day, not UTC's. */
    private static function localDay(string $column): string
    {
        $tz = Clock::timezone();

        // The tenant timezone is configuration, but it still never reaches SQL unvalidated.
        if (preg_match('#^[A-Za-z][A-Za-z0-9_+/-]{1,63}$#', $tz) !== 1) {
            $tz = Clock::DEFAULT_TIMEZONE;
        }

        return "to_char({$column} at time zone '{$tz}', 'YYYY-MM-DD')";
    }

    /**
     * Clinic-local calendar days converted to the UTC instants the columns actually hold.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = Clock::timezone();

        return [
            $from->setTimezone($tz)->startOfDay()->utc(),
            $to->setTimezone($tz)->endOfDay()->utc(),
        ];
    }
}
