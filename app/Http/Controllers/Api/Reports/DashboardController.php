<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Reports;

use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Services\ReportData;
use App\Domain\Reports\Services\ReportScopeResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Reports\ReportFilterRequest;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/reports/dashboard` — the same snapshot the panel dashboard is rendered from, as JSON, so the page
 * can refresh its tiles without a full Inertia visit while the owner leaves it open on a screen.
 *
 * It is the ONLY JSON endpoint this module owns: everything else is an Inertia page or a file download
 * (CONVENTIONS §5 — JSON lives under `/api`, and only when something actually polls it).
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly ReportData $data,
        private readonly ReportScopeResolver $scopes,
    ) {}

    public function __invoke(ReportFilterRequest $request): JsonResponse
    {
        $scope = $this->scopes->for($request->user('web'));
        $filters = $scope->apply($request->toData());
        $payload = $this->data->for(ReportKind::Dashboard, $filters, $scope);

        return response()->json([
            'data' => $payload['data'],
            'generated_at' => $payload['generated_at'],
            'cached' => $payload['cached'],
        ])->header('Cache-Control', 'no-store, private');
    }
}
