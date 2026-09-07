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
 * One report family per request (BRIEF §5.L). The route binds `{report}` to a `ReportKind`, the FormRequest
 * authorises it against the gate and turns the query string into `ReportFilters`, and the numbers come from
 * `ReportData` — the same method the exports read, so a page and its CSV cannot disagree.
 */
final class ReportController extends Controller
{
    public function __construct(
        private readonly ReportData $data,
        private readonly ReportScopeResolver $scopes,
        private readonly ReportOptions $options,
    ) {}

    public function show(ReportFilterRequest $request): Response
    {
        $kind = $request->kind();
        $scope = $this->scopes->for($request->user('web'));
        $filters = $scope->apply($request->toData());
        $payload = $this->data->for($kind, $filters, $scope);

        return Inertia::render($kind->page(), [
            'report' => $kind->value,
            'filters' => $filters->toArray() + $request->rawFilters(),
            'scope' => $scope->toArray(),
            'options' => $this->options->all($scope),
            'data' => $payload['data'],
            'generated_at' => $payload['generated_at'],
            'cached' => $payload['cached'],
        ]);
    }
}
