<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Queries\AuditLogSearch;
use App\Domain\SaaS\Services\CentralAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Audit\AuditSearchRequest;
use App\Models\Central\AuditLogCentral;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit log as CSV, under the same filters as the screen, streamed row by row (the cursor is lazy, so a
 * year of the platform's trail never sits in memory at once). Exporting the audit log is itself audited — with
 * the filters and the row count — because a copy of who-did-what leaving the console is exactly the kind of
 * event the log exists to record.
 */
final class AuditExportController extends Controller
{
    public function __invoke(AuditSearchRequest $request, AuditLogSearch $search, CentralAudit $audit): StreamedResponse
    {
        $filters = $request->filters();
        $count = min($search->count($filters), AuditLogSearch::EXPORT_LIMIT);

        $audit->record(CentralAuditAction::Export, null, null, null, ['what' => 'audit_log', 'rows' => $count, 'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== '')]);

        $name = 'audit-log-'.CarbonImmutable::now('Asia/Dhaka')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($search, $filters): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");   // BOM: Excel opens Bangla clinic names correctly
            fputcsv($out, ['id', 'occurred_at', 'action', 'actor', 'actor_id', 'tenant', 'tenant_slug', 'auditable_type', 'auditable_id', 'before', 'after', 'ip', 'user_agent', 'request_id']);

            foreach ($search->stream($filters) as $log) {
                /** @var AuditLogCentral $log */
                fputcsv($out, [
                    $log->id,
                    $log->getAttribute('occurred_at')?->toIso8601String(),
                    $log->action->value,
                    $log->superAdmin?->name,
                    $log->super_admin_id,
                    $log->tenant?->name,
                    $log->tenant?->slug,
                    $log->auditable_type,
                    $log->auditable_id,
                    $log->before === null ? '' : json_encode($log->before, JSON_UNESCAPED_UNICODE),
                    $log->after === null ? '' : json_encode($log->after, JSON_UNESCAPED_UNICODE),
                    $log->getAttribute('ip'),
                    $log->getAttribute('user_agent'),
                    $log->getAttribute('request_id'),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Accel-Buffering' => 'no']);
    }
}
