<?php

declare(strict_types=1);

namespace App\Domain\Reports\Jobs;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Data\ReportScope;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Enums\PeakMetric;
use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Export\ReportExporter;
use App\Models\Tenant\ReportExport;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds a large export on Horizon's `reports` queue (ARCHITECTURE §4.6) and stores the file for the requester.
 *
 * The job carries the export ROW ID and the requester's SCOPE, not the numbers and not a user object: the scope
 * has already been resolved from the requester's permissions in the request, and re-applying it here means a
 * background render of a doctor's report is still that doctor's report even though no session exists in the
 * worker. Nothing is recomputed from the user, so a permission changed between request and run cannot widen the
 * file — only the scope that was authorised is honoured.
 *
 * Idempotent: a retry of an export already `ready` returns immediately rather than writing a second file.
 */
final class GenerateReportExport implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public int $timeout = 300;

    /** @param array<string, mixed> $scope the ReportScope::toArray() of the requester */
    public function __construct(public readonly int $exportId, public readonly array $scope)
    {
        $this->onQueue('reports');

        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(ReportExporter $exporter): void
    {
        $export = ReportExport::query()->find($this->exportId);

        if ($export === null || $export->status === ExportStatus::Ready) {
            return;
        }

        $kind = ReportKind::tryFrom($export->report);

        if ($kind === null) {
            $export->forceFill(['status' => ExportStatus::Failed->value, 'error' => 'unknown report'])->save();

            return;
        }

        $export->forceFill(['status' => ExportStatus::Processing->value])->save();

        try {
            $exporter->fulfil($export, $exporter->table($kind, $this->filters($export), $this->scope()));
        } catch (Throwable $e) {
            // The row is the user's only feedback, so a failure must be visible on it even while the job retries.
            $export->forceFill(['status' => ExportStatus::Failed->value, 'error' => mb_substr($e->getMessage(), 0, 255)])->save();
            Log::error('reports.export.failed', ['export_id' => $export->id, 'report' => $export->report, 'message' => $e->getMessage()]);

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        ReportExport::query()->whereKey($this->exportId)->get()->each(function (ReportExport $export) use ($e): void {
            $export->forceFill(['status' => ExportStatus::Failed->value, 'error' => mb_substr($e?->getMessage() ?? 'failed', 0, 255)])->save();
        });
    }

    private function filters(ReportExport $export): ReportFilters
    {
        $stored = $export->filters;
        $tz = Clock::timezone();
        $parse = static fn (string $key, string $fallback): CarbonImmutable => CarbonImmutable::parse(
            is_string($stored[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $stored[$key]) === 1 ? (string) $stored[$key] : $fallback,
            $tz,
        )->startOfDay();

        $today = Clock::today()->toDateString();

        return new ReportFilters(
            from: $parse('from', $today),
            to: $parse('to', $today),
            branchId: is_numeric($stored['branch_id'] ?? null) ? (int) $stored['branch_id'] : null,
            doctorId: is_numeric($stored['doctor_id'] ?? null) ? (int) $stored['doctor_id'] : null,
            specialtyId: is_numeric($stored['specialty_id'] ?? null) ? (int) $stored['specialty_id'] : null,
            method: is_string($stored['method'] ?? null) ? (string) $stored['method'] : null,
            metric: PeakMetric::tryFrom(is_string($stored['metric'] ?? null) ? (string) $stored['metric'] : '') ?? PeakMetric::Arrivals,
            limit: is_numeric($stored['limit'] ?? null) ? (int) $stored['limit'] : 15,
        );
    }

    private function scope(): ReportScope
    {
        return new ReportScope(
            financial: (bool) ($this->scope['financial'] ?? false),
            clinical: (bool) ($this->scope['clinical'] ?? false),
            allDoctors: (bool) ($this->scope['all_doctors'] ?? false),
            canExport: (bool) ($this->scope['can_export'] ?? false),
            doctorId: is_numeric($this->scope['doctor_id'] ?? null) ? (int) $this->scope['doctor_id'] : null,
        );
    }
}
