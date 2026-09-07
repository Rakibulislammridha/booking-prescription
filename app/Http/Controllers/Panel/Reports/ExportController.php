<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reports;

use App\Domain\Reports\Export\ReportExporter;
use App\Domain\Reports\Services\ReportScopeResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Reports\ExportReportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /panel/reports/{report}/export?format=csv|xlsx|pdf` (BRIEF §5.L "export to CSV, Excel, PDF").
 *
 * An ordinary report — the aggregates are already grouped, so a doctor table is tens of rows — streams straight
 * back and the browser starts saving. Anything past `ReportExporter::SYNC_ROW_LIMIT` goes to Horizon's
 * `reports` queue and the caller is redirected back with a flash pointing at the exports list; a clinic's three
 * years must not hold an HTTP worker.
 *
 * Either way `ReportExporter` writes the audit entry — who exported what, in which format, over which range.
 */
final class ExportController extends Controller
{
    public function __construct(
        private readonly ReportExporter $exporter,
        private readonly ReportScopeResolver $scopes,
    ) {}

    public function __invoke(ExportReportRequest $request): StreamedResponse|Response|RedirectResponse
    {
        $kind = $request->kind();
        $format = $request->exportFormat();
        $user = $request->user('web');
        $scope = $this->scopes->for($user);
        $filters = $scope->apply($request->toData());
        $table = $this->exporter->table($kind, $filters, $scope);

        if ($user !== null && $this->exporter->isLarge($table)) {
            $this->exporter->queue($kind, $format, $filters, $scope, $user, $table);

            return redirect()->back()->with('flash.info', __('reports.export.queued'));
        }

        return $this->exporter->respond($kind, $format, $table, $filters, $user);
    }
}
