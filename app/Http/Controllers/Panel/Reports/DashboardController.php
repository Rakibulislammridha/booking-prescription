<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reports;

use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Services\ReportData;
use App\Domain\Reports\Services\ReportOptions;
use App\Domain\Reports\Services\ReportScopeResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Reports\ReportFilterRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clinic owner's Sunday morning: today's bookings, arrivals, completions, no-shows, collection and average
 * wait, plus the live board of today's sessions (BRIEF §5.L).
 *
 * The range in the filter bar drives the small trend strip; the tiles are always TODAY, because "today at a
 * glance" is the page's whole promise and a tile that silently meant "the last 30 days" would be a lie.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly ReportData $data,
        private readonly ReportScopeResolver $scopes,
        private readonly ReportOptions $options,
    ) {}

    public function __invoke(ReportFilterRequest $request): Response
    {
        $scope = $this->scopes->for($request->user('web'));
        $filters = $scope->apply($request->toData());
        $today = $this->data->for(ReportKind::Dashboard, $filters, $scope);
        $trend = $this->data->for(ReportKind::Appointments, $filters, $scope);

        return Inertia::render('Reports/Dashboard', [
            'report' => ReportKind::Dashboard->value,
            'filters' => $filters->toArray() + $request->rawFilters(),
            'scope' => $scope->toArray(),
            'options' => $this->options->all($scope),
            'data' => $today['data'],
            'trend' => $trend['data'],
            'generated_at' => $today['generated_at'],
            'cached' => $today['cached'],
        ]);
    }
}
