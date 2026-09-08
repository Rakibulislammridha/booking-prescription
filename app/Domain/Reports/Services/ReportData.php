<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Data\ReportScope;
use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Queries\AppointmentVolumeQuery;
use App\Domain\Reports\Queries\DailySnapshotQuery;
use App\Domain\Reports\Queries\PatientMixQuery;
use App\Domain\Reports\Queries\PeakHourQuery;
use App\Domain\Reports\Queries\RevenueQuery;
use App\Domain\Reports\Queries\TopDiagnosesQuery;
use App\Domain\Reports\Queries\TopDrugsQuery;
use App\Domain\Reports\Queries\WaitTimeQuery;

/**
 * THE single entry point to a report's numbers.
 *
 * Everything — the Inertia page, the JSON dashboard poll, the CSV, the Excel file and the PDF — reads a report
 * through this one method, so "the export matches the screen" is a property of the design rather than a test
 * that has to be remembered. The table builder is handed the exact payload the page received; it never
 * re-queries.
 *
 * Scope is applied HERE, once, before any query runs (`$scope->apply()` forces the doctor filter), and the
 * financial/clinical gate is re-checked even though the controller already authorised the route: a report the
 * scope may not see returns an empty payload rather than numbers.
 */
final class ReportData
{
    public function __construct(
        private readonly ReportCache $cache,
        private readonly AppointmentVolumeQuery $appointments,
        private readonly WaitTimeQuery $waits,
        private readonly RevenueQuery $revenue,
        private readonly PatientMixQuery $patients,
        private readonly TopDiagnosesQuery $diagnoses,
        private readonly TopDrugsQuery $drugs,
        private readonly PeakHourQuery $peaks,
        private readonly DailySnapshotQuery $snapshot,
    ) {}

    /**
     * @return array{data: array<string, mixed>, generated_at: string, cached: bool, ttl?: int}
     */
    public function for(ReportKind $kind, ReportFilters $filters, ReportScope $scope): array
    {
        $scoped = $scope->apply($filters);

        if (! $scope->allows($kind)) {
            return ['data' => [], 'generated_at' => now()->toIso8601String(), 'cached' => false];
        }

        // The cache row is keyed by the scope's CAPABILITIES as well as by the filters: the dashboard's payload
        // contains the clinic's takings only for a scope that may see them, and two viewers whose filters
        // happen to match must never be handed each other's version of it.
        return $this->cache->remember($kind->value.'.'.$scope->cacheVariant(), $scoped, fn (): array => $this->compute($kind, $scoped, $scope));
    }

    /** @return array<string, mixed> */
    private function compute(ReportKind $kind, ReportFilters $filters, ReportScope $scope): array
    {
        return match ($kind) {
            ReportKind::Dashboard => $this->snapshot->today($filters, $scope),
            ReportKind::Appointments => $this->appointments->summary($filters),
            ReportKind::WaitTimes => $this->waits->summary($filters),
            ReportKind::Revenue => $this->revenue->summary($filters),
            ReportKind::Patients => $this->patients->summary($filters),
            ReportKind::Clinical => [
                'diagnoses' => $this->diagnoses->summary($filters),
                'drugs' => $this->drugs->summary($filters),
            ],
            ReportKind::PeakHours => $this->peaks->summary($filters),
        };
    }
}
