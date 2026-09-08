<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Catalog;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Http\Controllers\Controller;
use App\Models\Central\CatalogReconciliationReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The nightly catalog reconciliation reports (SCHEMA §2.12) — soft references from tenant schemas into `catalog`
 * that no longer resolve. Orphans are REPORTED, never deleted: a prescription that names a molecule the catalogue
 * has retired is still what the doctor wrote, and the fix is a catalogue decision, not a delete.
 *
 * Marking a row reviewed is the only write here, and it is audited.
 */
final class ReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate(['unresolved' => ['nullable', 'boolean']]);
        $unresolved = ! $request->exists('unresolved') || $request->boolean('unresolved');

        $rows = CatalogReconciliationReport::query()
            ->with('tenant:id,public_id,name,slug')
            ->when($unresolved, fn ($q) => $q->whereNull('resolved_at')->where('status', '!=', 'clean'))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Super/Catalog/Reconciliation', [
            'reports' => $rows->through(fn (CatalogReconciliationReport $r) => [
                'id' => $r->id,
                'run_id' => $r->run_id,
                'tenant' => $r->tenant === null ? null : ['public_id' => $r->tenant->public_id, 'name' => $r->tenant->name, 'slug' => $r->tenant->slug],
                'table_name' => $r->table_name,
                'column_name' => $r->column_name,
                'checked_count' => $r->checked_count,
                'orphan_count' => $r->orphan_count,
                'status' => $r->status,
                'sample_ids' => $r->sample_ids,
                'details' => $r->details,
                'resolved_at' => $r->resolved_at?->toIso8601String(),
                'created_at' => $r->created_at?->toIso8601String(),
            ])->items(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
            'filters' => ['unresolved' => $unresolved],
        ]);
    }

    public function resolve(Request $request, CatalogReconciliationReport $report, CentralAudit $audit): RedirectResponse
    {
        $adminId = (int) $request->user('super')?->getAuthIdentifier();

        $report->forceFill(['resolved_at' => CarbonImmutable::now(), 'resolved_by_super_admin_id' => $adminId])->save();
        $audit->record(CentralAuditAction::Update, $report->tenant, $report, null, ['resolved' => true]);

        return back()->with('flash.success', __('saas.reconciliation.flash.resolved'));
    }
}
