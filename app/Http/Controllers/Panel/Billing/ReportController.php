<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Queries\CollectionReportQuery;
use App\Domain\Billing\Queries\RevenueShareReportQuery;
use App\Domain\Billing\Services\Paisa;
use App\Domain\Clinic\Services\ActiveBranch;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Invoice;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Collection and commission reports (BRIEF §5.L). The numbers come from the query objects, so the Reports module
 * can reuse them without this controller.
 */
final class ReportController extends Controller
{
    public function index(Request $request, CollectionReportQuery $collection, RevenueShareReportQuery $shares, ActiveBranch $activeBranch): Response
    {
        $this->authorize('reports', Invoice::class);
        [$from, $to] = $this->range($request);
        $filters = $this->filters($request, $activeBranch);

        return Inertia::render('Billing/Reports', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()] + array_map(fn ($v) => $v === null ? null : (string) $v, $this->rawFilters($request)),
            'collection' => $collection->summary($from, $to, $filters),
            'commission' => $shares->summary($from, $to, $filters),
            'doctors' => Doctor::query()->orderBy('name')->get(['public_id', 'name'])->map(fn (Doctor $d) => ['public_id' => $d->public_id, 'name' => $d->name])->all(),
            'branch_options' => Branch::query()->orderBy('name')->get(['public_id', 'name'])->map(fn (Branch $b) => ['public_id' => $b->public_id, 'name' => $b->name])->all(),
        ]);
    }

    /** CSV export (BRIEF §5.L "export to CSV"); audited as an export of financial records. */
    public function export(Request $request, CollectionReportQuery $collection, RevenueShareReportQuery $shares, ActiveBranch $activeBranch, AuditRecorder $audit): StreamedResponse
    {
        $this->authorize('reports', Invoice::class);
        [$from, $to] = $this->range($request);
        $filters = $this->filters($request, $activeBranch);
        $kind = $request->query('kind') === 'commission' ? 'commission' : 'collection';

        $rows = $kind === 'commission'
            ? $this->commissionRows($shares->rows($from, $to, $filters))
            : $this->collectionRows($collection->summary($from, $to, $filters));

        // Audited against the exporting user: the export is not one record, and `auditable_id` must be real.
        $audit->record(AuditAction::Export, $request->user('web') ?? new Invoice, null, null, [
            'report' => $kind,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => count($rows) - 1,
        ]);

        $filename = sprintf('billing-%s-%s-%s.csv', $kind, $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");   // BOM: Excel on Windows opens Bangla names correctly

            foreach ($rows as $row) {
                fputcsv($handle, $row, ',', '"', '\\');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<int, array<int, string>>
     */
    private function collectionRows(array $summary): array
    {
        $rows = [[__('billing.report.csv.date'), __('billing.report.csv.gross'), __('billing.report.csv.refunds'), __('billing.report.csv.net'), __('billing.report.csv.count')]];

        /** @var array<int, array<string, mixed>> $byDay */
        $byDay = $summary['by_day'] ?? [];

        foreach ($byDay as $day) {
            $rows[] = [
                (string) $day['date'],
                Paisa::toDecimal((int) $day['gross_paisa']),
                Paisa::toDecimal((int) $day['refunds_paisa']),
                Paisa::toDecimal((int) $day['net_paisa']),
                (string) $day['count'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<int, string>>
     */
    private function commissionRows(array $rows): array
    {
        $out = [[__('billing.report.csv.doctor'), __('billing.report.csv.branch'), __('billing.report.csv.item_type'), __('billing.report.csv.billed'), __('billing.report.csv.doctor_share'), __('billing.report.csv.clinic_share'), __('billing.report.csv.collected'), __('billing.report.csv.count')]];

        foreach ($rows as $row) {
            $out[] = [
                (string) ($row['doctor_name'] ?? ''),
                (string) ($row['branch_name'] ?? ''),
                (string) $row['item_type'],
                Paisa::toDecimal((int) $row['billed_paisa']),
                Paisa::toDecimal((int) $row['doctor_share_paisa']),
                Paisa::toDecimal((int) $row['clinic_share_paisa']),
                Paisa::toDecimal((int) $row['collected_paisa']),
                (string) $row['item_count'],
            ];
        }

        return $out;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $tz = Clock::timezone();
        $parse = function (string $key, CarbonImmutable $default) use ($request, $tz): CarbonImmutable {
            $value = (string) $request->query($key, '');

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? CarbonImmutable::parse($value, $tz)->startOfDay() : $default;
        };

        $to = $parse('to', Clock::today());
        $from = $parse('from', $to->startOfMonth());

        return [$from->greaterThan($to) ? $to : $from, $to];
    }

    /** @return array<string, int|string|null> */
    private function filters(Request $request, ActiveBranch $activeBranch): array
    {
        $raw = $this->rawFilters($request);
        $branchId = $raw['branch'] === null ? $activeBranch->current()?->id : Branch::query()->where('public_id', (string) $raw['branch'])->value('id');
        $doctorId = $raw['doctor'] === null ? null : Doctor::query()->where('public_id', (string) $raw['doctor'])->value('id');

        return [
            'branch_id' => $branchId === null ? null : (int) $branchId,
            'doctor_id' => $doctorId === null ? null : (int) $doctorId,
            'method' => $raw['method'],
        ];
    }

    /** @return array{branch: string|null, doctor: string|null, method: string|null} */
    private function rawFilters(Request $request): array
    {
        $clean = function (string $key) use ($request): ?string {
            $value = trim((string) $request->query($key, ''));

            return $value === '' ? null : $value;
        };

        return ['branch' => $clean('branch'), 'doctor' => $clean('doctor'), 'method' => $clean('method')];
    }
}
