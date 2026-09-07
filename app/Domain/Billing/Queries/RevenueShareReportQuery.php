<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The commission report by doctor, branch and period (BRIEF §5.I "a major selling point").
 *
 * It reads ONLY the frozen `invoice_items.doctor_share_paisa` / `clinic_share_paisa` written at issue time — the
 * rules table is never joined — so re-running last month's report after a rule change returns the same numbers.
 */
final class RevenueShareReportQuery
{
    /**
     * @param  array{branch_id?: int|null, doctor_id?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $rows = $this->rows($from, $to, $filters);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
            'totals' => [
                'billed_paisa' => array_sum(array_column($rows, 'billed_paisa')),
                'doctor_share_paisa' => array_sum(array_column($rows, 'doctor_share_paisa')),
                'clinic_share_paisa' => array_sum(array_column($rows, 'clinic_share_paisa')),
                'collected_paisa' => array_sum(array_column($rows, 'collected_paisa')),
                'item_count' => array_sum(array_column($rows, 'item_count')),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function rows(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $tz = Clock::timezone();
        $start = $from->setTimezone($tz)->startOfDay()->utc();
        $end = $to->setTimezone($tz)->endOfDay()->utc();

        $query = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->leftJoin('doctors as d', 'd.id', '=', DB::raw('coalesce(ii.doctor_id, i.doctor_id)'))
            ->leftJoin('branches as b', 'b.id', '=', 'i.branch_id')
            ->whereNotNull('i.issued_at')
            ->whereBetween('i.issued_at', [$start, $end])
            ->where('i.status', '!=', InvoiceStatus::Void->value);

        if (! empty($filters['branch_id'])) {
            $query->where('i.branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['doctor_id'])) {
            $query->where(DB::raw('coalesce(ii.doctor_id, i.doctor_id)'), (int) $filters['doctor_id']);
        }

        return $query
            ->selectRaw('coalesce(ii.doctor_id, i.doctor_id) as earning_doctor_id, d.name as doctor_name, d.public_id as doctor_public_id, i.branch_id, b.name as branch_name, ii.type')
            ->selectRaw('sum(ii.line_total_paisa) as billed, sum(ii.doctor_share_paisa) as doctor_share, sum(ii.clinic_share_paisa) as clinic_share, count(*) as n')
            // Collected is apportioned by the invoice's paid ratio: a partly paid bill has partly earned commission.
            ->selectRaw('sum((ii.line_total_paisa * i.paid_paisa) / greatest(i.total_paisa, 1)) as collected')
            ->groupBy(DB::raw('coalesce(ii.doctor_id, i.doctor_id)'), 'd.name', 'd.public_id', 'i.branch_id', 'b.name', 'ii.type')
            ->orderByDesc('doctor_share')
            ->get()
            ->map(fn ($row): array => [
                'doctor_id' => $row->earning_doctor_id === null ? null : (int) $row->earning_doctor_id,
                'doctor_public_id' => $row->doctor_public_id === null ? null : (string) $row->doctor_public_id,
                'doctor_name' => $row->doctor_name === null ? null : (string) $row->doctor_name,
                'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                'branch_name' => $row->branch_name === null ? null : (string) $row->branch_name,
                'item_type' => (string) $row->type,
                'billed_paisa' => (int) $row->billed,
                'doctor_share_paisa' => (int) $row->doctor_share,
                'clinic_share_paisa' => (int) $row->clinic_share,
                'collected_paisa' => (int) $row->collected,
                'item_count' => (int) $row->n,
            ])
            ->all();
    }
}
