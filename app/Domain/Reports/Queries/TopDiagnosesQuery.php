<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Support\LocalTime;
use App\Domain\Reports\Support\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Top diagnoses (BRIEF §5.L calls this data "genuinely valuable" — it is the epidemiology of the clinic's
 * catchment, and the one report a hospital chain will pay for).
 *
 * SOURCE: `visits.diagnoses`, the ICD-10-coded jsonb array SCHEMA §3.4 keeps in plain text precisely so it can
 * be aggregated (it is deliberately NOT encrypted, ARCHITECTURE §8.2).
 *
 * DEFINITIONS:
 *  - The unit is the VISIT, not the row: a visit that lists "Type 2 diabetes" as both provisional and final
 *    counts once, via `count(distinct v.id)`.
 *  - The key is the ICD-10 code when the doctor picked one, and the lower-cased free-text title when he did
 *    not — an uncoded "viral fever" is still the clinic's most common diagnosis and dropping it would be a lie
 *    of omission. Uncoded keys are flagged so the page can show them differently.
 *  - The displayed title is `mode()` of the snapshots seen under that key, so one doctor's odd spelling does
 *    not rename a code for everyone.
 *  - `provisional` and `final` are both counted; the `kind` split is reported alongside.
 *
 * FILTERS: period, doctor and specialty (the specialty is an EXISTS against `doctor_specialties`, which keeps
 * the visits themselves in the driving loop).
 *
 * INDEXES: `visits_started_at_idx` (this module's migration) for the clinic-wide period scan, or
 * `visits_doctor_id_started_at_idx` under a doctor filter. The GIN index `visits_diagnoses_idx` SCHEMA §3.4
 * mentions serves containment lookups ("which visits have code E11"), not aggregation, so it is NOT used here:
 * the lateral `jsonb_array_elements` expands rows already narrowed by the date range.
 */
final class TopDiagnosesQuery
{
    /** @return array<string, mixed> */
    public function summary(ReportFilters $filters): array
    {
        $rows = $this->rows($filters);
        $keys = array_map(static fn (array $r): string => (string) $r['key'], array_slice($rows, 0, 6));

        return [
            'rows' => $rows,
            'trend' => $this->trend($filters, $keys),
            'trend_keys' => $keys,
            'total_visits' => $this->visitCount($filters),
            'granularity' => $filters->granularity(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(ReportFilters $filters): array
    {
        $rows = $this->base($filters)
            ->selectRaw(self::KEY_EXPR.' as k')
            ->selectRaw("max(dx.value->>'icd10_code') as icd10_code")
            ->selectRaw("mode() within group (order by dx.value->>'title') as title")
            ->selectRaw('count(distinct v.id) as visits, count(distinct v.patient_id) as patients')
            ->selectRaw("count(distinct v.id) filter (where dx.value->>'kind' = 'final') as final_visits")
            ->groupBy(DB::raw(self::KEY_EXPR))
            ->orderByDesc('visits')
            ->orderBy('k')
            ->limit(max(1, $filters->limit))
            ->get();

        return $rows->map(fn (mixed $row): array => [
            'key' => Row::string($row, 'k'),
            'icd10_code' => Row::nullableString($row, 'icd10_code'),
            'title' => Row::string($row, 'title'),
            'coded' => Row::nullableString($row, 'icd10_code') !== null,
            'visits' => Row::int($row, 'visits'),
            'patients' => Row::int($row, 'patients'),
            'final_visits' => Row::int($row, 'final_visits'),
        ])->all();
    }

    /**
     * The period series of the leading diagnoses — "top diagnoses AND TRENDS" of the brief. One extra aggregate
     * restricted to the keys already found, so the chart never asks for a second full scan per series.
     *
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    public function trend(ReportFilters $filters, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $bucket = LocalTime::bucket('v.started_at', $filters->granularity());

        $rows = $this->base($filters)
            ->whereIn(DB::raw(self::KEY_EXPR), $keys)
            ->selectRaw("{$bucket} as period, ".self::KEY_EXPR.' as k, count(distinct v.id) as visits')
            ->groupBy(DB::raw($bucket), DB::raw(self::KEY_EXPR))
            ->orderBy('period')
            ->get();

        return $rows->map(fn (mixed $row): array => [
            'period' => Row::string($row, 'period'),
            'key' => Row::string($row, 'k'),
            'visits' => Row::int($row, 'visits'),
        ])->all();
    }

    public function visitCount(ReportFilters $filters): int
    {
        $query = DB::table('visits as v')
            ->where('v.status', '!=', VisitStatus::Cancelled->value)
            ->where('v.started_at', '>=', $filters->startUtc())
            ->where('v.started_at', '<', $filters->endUtc());

        self::applyFilters($query, $filters);

        return $query->count();
    }

    /** An uncoded diagnosis keys on its own text, prefixed so it can never collide with a real ICD-10 code. */
    private const KEY_EXPR = "coalesce(nullif(dx.value->>'icd10_code', ''), '~' || lower(trim(coalesce(dx.value->>'title', ''))))";

    private function base(ReportFilters $filters): Builder
    {
        $query = DB::table('visits as v')
            ->crossJoin(DB::raw('lateral jsonb_array_elements(v.diagnoses) as dx(value)'))
            ->where('v.status', '!=', VisitStatus::Cancelled->value)
            ->where('v.started_at', '>=', $filters->startUtc())
            ->where('v.started_at', '<', $filters->endUtc())
            // Parenthesised: an unbracketed OR here would out-rank the date and status predicates and quietly
            // widen the whole report to every visit that happens to carry a coded diagnosis.
            ->whereRaw("(coalesce(dx.value->>'title', '') <> '' or coalesce(dx.value->>'icd10_code', '') <> '')");

        self::applyFilters($query, $filters);

        return $query;
    }

    /** Shared by the aggregate and the denominator so the two can never diverge. */
    public static function applyFilters(Builder $query, ReportFilters $filters): void
    {
        if ($filters->branchId !== null) {
            $query->where('v.branch_id', $filters->branchId);
        }

        if ($filters->doctorId !== null) {
            $query->where('v.doctor_id', $filters->doctorId);
        }

        if ($filters->specialtyId !== null) {
            $query->whereExists(fn (Builder $sub) => $sub->from('doctor_specialties as ds')
                ->whereColumn('ds.doctor_id', 'v.doctor_id')
                ->where('ds.specialty_id', $filters->specialtyId));
        }
    }
}
