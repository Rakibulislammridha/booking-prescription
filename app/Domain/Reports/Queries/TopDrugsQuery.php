<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Support\LocalTime;
use App\Domain\Reports\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Top prescribed drugs, by GENERIC and by BRAND (BRIEF §5.L — the other half of the "genuinely valuable" pair).
 *
 * SOURCE: `prescription_items`, whose `generic_name` / `brand_name` are SNAPSHOT text written at issue
 * (SCHEMA §3.4, ARCHITECTURE §8.3). Grouping on the snapshot rather than on the soft catalog id is the whole
 * point: last year's report must keep saying "Napa 500 mg" even if the brand was renamed or delisted in the
 * DGDA catalog since, and this query never joins the catalog database (BRIEF §8).
 *
 * DEFINITIONS:
 *  - Only prescriptions with status `issued` are counted. `draft` was never given to a patient; `amended` is a
 *    SUPERSEDED version whose replacement is also in the table, so counting it would double-count the drug;
 *    `voided` was withdrawn.
 *  - The period is `prescriptions.issued_at`, the moment the paper existed.
 *  - `items` counts prescription lines, `prescriptions` counts distinct prescriptions (a doctor who writes the
 *    same molecule twice on one sheet is one prescribing decision), `patients` counts distinct patients.
 *  - The generic view keys on `lower(generic_name)` so a custom brand with no catalog `generic_id` still lands
 *    on its molecule. The brand view skips lines prescribed by generic (`brand_name IS NULL`) and reports them
 *    as a separate "prescribed by generic" count, which is itself a metric worth watching.
 *
 * INDEXES: `prescriptions_issued_at_idx_p` (this module's migration, partial on status = 'issued') for the
 * clinic-wide period scan, or `prescriptions_doctor_id_issued_at_idx` under a doctor filter; then the
 * `prescription_items.prescription_id` FK index for the nested loop. `prescription_items_generic_id_idx` is not
 * the driving index here — it serves the reconcile scans — because a period is always the narrower predicate.
 */
final class TopDrugsQuery
{
    /** @return array<string, mixed> */
    public function summary(ReportFilters $filters): array
    {
        $generics = $this->byGeneric($filters);
        $keys = array_map(static fn (array $r): string => (string) $r['key'], array_slice($generics, 0, 6));

        return [
            'by_generic' => $generics,
            'by_brand' => $this->byBrand($filters),
            'trend' => $this->trend($filters, $keys),
            'trend_keys' => $keys,
            'totals' => $this->totals($filters),
            'granularity' => $filters->granularity(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function byGeneric(ReportFilters $filters): array
    {
        $rows = $this->base($filters)
            ->whereRaw("coalesce(pi.generic_name, '') <> ''")
            ->selectRaw(self::GENERIC_KEY.' as k')
            ->selectRaw('mode() within group (order by pi.generic_name) as name, max(pi.generic_id) as generic_id')
            ->selectRaw('count(*) as items, count(distinct p.id) as prescriptions, count(distinct p.patient_id) as patients')
            ->groupBy(DB::raw(self::GENERIC_KEY))
            ->orderByDesc('items')
            ->orderBy('k')
            ->limit(max(1, $filters->limit))
            ->get();

        return $rows->map(fn (mixed $row): array => [
            'key' => Row::string($row, 'k'),
            'name' => Row::string($row, 'name'),
            'generic_id' => Row::nullableInt($row, 'generic_id'),
            'items' => Row::int($row, 'items'),
            'prescriptions' => Row::int($row, 'prescriptions'),
            'patients' => Row::int($row, 'patients'),
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function byBrand(ReportFilters $filters): array
    {
        $rows = $this->base($filters)
            ->whereRaw("coalesce(pi.brand_name, '') <> ''")
            ->selectRaw(self::BRAND_KEY.' as k')
            ->selectRaw('mode() within group (order by pi.brand_name) as name, mode() within group (order by pi.generic_name) as generic_name')
            ->selectRaw('max(pi.brand_id) as brand_id, max(pi.custom_brand_id) as custom_brand_id')
            ->selectRaw('count(*) as items, count(distinct p.id) as prescriptions, count(distinct p.patient_id) as patients')
            ->groupBy(DB::raw(self::BRAND_KEY))
            ->orderByDesc('items')
            ->orderBy('k')
            ->limit(max(1, $filters->limit))
            ->get();

        return $rows->map(fn (mixed $row): array => [
            'key' => Row::string($row, 'k'),
            'name' => Row::string($row, 'name'),
            'generic_name' => Row::nullableString($row, 'generic_name'),
            'brand_id' => Row::nullableInt($row, 'brand_id'),
            'custom_brand_id' => Row::nullableInt($row, 'custom_brand_id'),
            'is_custom' => Row::nullableInt($row, 'custom_brand_id') !== null,
            'items' => Row::int($row, 'items'),
            'prescriptions' => Row::int($row, 'prescriptions'),
            'patients' => Row::int($row, 'patients'),
        ])->all();
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    public function trend(ReportFilters $filters, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $bucket = LocalTime::bucket('p.issued_at', $filters->granularity());

        $rows = $this->base($filters)
            ->whereIn(DB::raw(self::GENERIC_KEY), $keys)
            ->selectRaw("{$bucket} as period, ".self::GENERIC_KEY.' as k, count(*) as items')
            ->groupBy(DB::raw($bucket), DB::raw(self::GENERIC_KEY))
            ->orderBy('period')
            ->get();

        return $rows->map(fn (mixed $row): array => [
            'period' => Row::string($row, 'period'),
            'key' => Row::string($row, 'k'),
            'items' => Row::int($row, 'items'),
        ])->all();
    }

    /** @return array<string, mixed> */
    public function totals(ReportFilters $filters): array
    {
        $row = $this->base($filters)
            ->selectRaw('count(*) as items, count(distinct p.id) as prescriptions')
            ->selectRaw("count(*) filter (where coalesce(pi.brand_name, '') = '') as generic_only")
            ->selectRaw('count(*) filter (where pi.custom_brand_id is not null) as custom_brand_items')
            ->first();

        $items = Row::int($row, 'items');

        return [
            'items' => $items,
            'prescriptions' => Row::int($row, 'prescriptions'),
            'generic_only' => Row::int($row, 'generic_only'),
            'custom_brand_items' => Row::int($row, 'custom_brand_items'),
            'generic_only_rate' => $items === 0 ? null : round(Row::int($row, 'generic_only') / $items * 100, 1),
        ];
    }

    private const GENERIC_KEY = "lower(trim(coalesce(pi.generic_name, '')))";

    private const BRAND_KEY = "lower(trim(coalesce(pi.brand_name, '')))";

    private function base(ReportFilters $filters): Builder
    {
        $query = DB::table('prescription_items as pi')
            ->join('prescriptions as p', 'p.id', '=', 'pi.prescription_id')
            ->where('p.status', PrescriptionStatus::Issued->value)
            ->where('p.issued_at', '>=', $filters->startUtc())
            ->where('p.issued_at', '<', $filters->endUtc());

        if ($filters->branchId !== null) {
            $query->where('p.branch_id', $filters->branchId);
        }

        if ($filters->doctorId !== null) {
            $query->where('p.doctor_id', $filters->doctorId);
        }

        if ($filters->specialtyId !== null) {
            $query->whereExists(fn (Builder $sub) => $sub->from('doctor_specialties as ds')
                ->whereColumn('ds.doctor_id', 'p.doctor_id')
                ->where('ds.specialty_id', $filters->specialtyId));
        }

        return $query;
    }
}
