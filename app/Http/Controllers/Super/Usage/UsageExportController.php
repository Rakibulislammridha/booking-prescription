<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Usage;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Queries\TenantUsageBoard;
use App\Domain\SaaS\Services\CentralAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Usage\UsageBoardRequest;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The usage board of one metric as CSV — every clinic, the cap, the share — under the screen's filter; audited. */
final class UsageExportController extends Controller
{
    public function __invoke(UsageBoardRequest $request, TenantUsageBoard $board, CentralAudit $audit): StreamedResponse
    {
        $metric = $request->metric();
        $filter = $request->filter();
        $rows = $board->export($metric, $filter);

        $audit->record(CentralAuditAction::Export, null, null, null, ['what' => 'usage', 'metric' => $metric->value, 'filter' => $filter, 'rows' => count($rows)]);

        $name = 'usage-'.$metric->value.'-'.CarbonImmutable::now('Asia/Dhaka')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $metric): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['clinic', 'slug', 'status', 'plan', 'metric', 'value', 'limit', 'percent', 'state']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['name'], $row['slug'], $row['status'], $row['plan_name'], $metric->value, $row['value'],
                    $row['limit'] === null ? 'unlimited' : $row['limit'],
                    $row['percent'] === null ? '' : $row['percent'],
                    $row['exhausted'] ? 'over' : ($row['near'] ? 'near' : 'ok'),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Accel-Buffering' => 'no']);
    }
}
