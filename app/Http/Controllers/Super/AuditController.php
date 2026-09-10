<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Queries\AuditLogSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Audit\AuditSearchRequest;
use App\Models\Central\AuditLogCentral;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `public.audit_logs_central`, newest first — who did what to which clinic, from where. Read-only by design:
 * the only write anywhere near this screen is the export's own `export` row (AuditExportController).
 */
final class AuditController extends Controller
{
    public function __invoke(AuditSearchRequest $request, AuditLogSearch $search): Response
    {
        $rows = $search->paginate($request->filters());

        return Inertia::render('Super/Audit/Index', [
            'logs' => $rows->through(fn (AuditLogCentral $l) => $search->present($l))->items(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'per_page' => $rows->perPage()],
            'filters' => $request->echo(),
            'actions' => CentralAuditAction::values(),
            'options' => $search->options(),
            'export_limit' => AuditLogSearch::EXPORT_LIMIT,
        ]);
    }
}
