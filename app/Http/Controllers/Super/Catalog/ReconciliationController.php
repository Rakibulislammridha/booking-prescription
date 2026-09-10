<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Catalog;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Catalog\Enums\ReconciliationStatus;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\PlatformMailer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Catalog\NotifyReconciliationRequest;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\CatalogReconciliationReport;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The nightly catalog reconciliation reports (SCHEMA §2.12) — soft references from tenant schemas into `catalog`
 * that no longer resolve. Orphans are REPORTED, never deleted: a prescription that names a molecule the catalogue
 * has retired is still what the doctor wrote, and the fix is a catalogue decision, not a delete.
 *
 * Writes here are "a human has looked at this" and "tell the clinic" — both audited; the second also goes into
 * the platform's outbound ledger.
 */
final class ReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'unresolved' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(ReconciliationStatus::values())],
            'tenant' => ['nullable', 'string', 'size:26'],
            'run' => ['nullable', 'string', 'max:26'],
            'table' => ['nullable', 'string', 'max:64'],
        ]);
        $unresolved = ! $request->exists('unresolved') || $request->boolean('unresolved');
        $tenantId = isset($validated['tenant']) ? Tenant::query()->where('public_id', (string) $validated['tenant'])->value('id') : null;

        $rows = CatalogReconciliationReport::query()
            ->with('tenant:id,public_id,name,slug')
            ->when($unresolved, fn ($q) => $q->whereNull('resolved_at')->where('status', '!=', 'clean'))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', (string) $validated['status']))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when(isset($validated['run']), fn ($q) => $q->where('run_id', (string) $validated['run']))
            ->when(isset($validated['table']), fn ($q) => $q->where('table_name', (string) $validated['table']))
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Super/Catalog/Reconciliation', [
            'reports' => $rows->through(fn (CatalogReconciliationReport $r) => self::present($r))->items(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
            'filters' => [
                'unresolved' => $unresolved, 'status' => $validated['status'] ?? '', 'tenant' => $validated['tenant'] ?? '',
                'run' => $validated['run'] ?? '', 'table' => $validated['table'] ?? '',
            ],
            'statuses' => ReconciliationStatus::values(),
            'tenants' => Tenant::query()->whereIn('id', CatalogReconciliationReport::query()->select('tenant_id'))->orderBy('name')->get(['public_id', 'name'])
                ->map(fn (Tenant $t) => ['public_id' => $t->public_id, 'name' => $t->name])->values()->all(),
            'tables' => CatalogReconciliationReport::query()->select('table_name')->distinct()->orderBy('table_name')->pluck('table_name')->all(),
            'runs' => DB::table('public.catalog_reconciliation_reports')->selectRaw('run_id, min(created_at) AS started_at, count(*) AS rows_count, sum(orphan_count) AS orphans')
                ->groupBy('run_id')->orderByDesc('started_at')->limit(20)->get()
                ->map(fn (object $r) => ['run_id' => (string) $r->run_id, 'started_at' => CarbonImmutable::parse((string) $r->started_at)->toIso8601String(), 'rows' => (int) $r->rows_count, 'orphans' => (int) $r->orphans])->values()->all(),
        ]);
    }

    /** One report with what its sample ids and details actually point at. */
    public function show(CatalogReconciliationReport $report): Response
    {
        $report->load(['tenant:id,public_id,name,slug,owner_email,owner_name', 'resolvedBy:id,name']);

        return Inertia::render('Super/Catalog/ReconciliationShow', [
            'report' => self::present($report) + [
                'resolved_by' => $report->resolvedBy?->name,
                'catalog_version_id' => $report->catalog_version_id,
                'notified_at' => self::lastNotification($report),
            ],
            'references' => $this->references($report),
            'sample' => $this->sampleRows($report),
        ]);
    }

    public function resolve(Request $request, CatalogReconciliationReport $report, CentralAudit $audit): RedirectResponse
    {
        $adminId = (int) $request->user('super')?->getAuthIdentifier();

        $report->forceFill(['resolved_at' => CarbonImmutable::now(), 'resolved_by_super_admin_id' => $adminId])->save();
        $audit->record(CentralAuditAction::Update, $report->tenant, $report, null, ['resolved' => true]);

        return back()->with('flash.success', __('saas.reconciliation.flash.resolved'));
    }

    /** Tell the clinic's owner, by mail in their language, what the sweep found in their records. */
    public function notify(NotifyReconciliationRequest $request, CatalogReconciliationReport $report, PlatformMailer $mailer, CentralAudit $audit): RedirectResponse
    {
        $tenant = $report->tenant;

        abort_if($tenant === null, 404);

        $note = trim((string) $request->validated('note'));
        $sent = $mailer->toOwner($tenant, 'saas.mail.reconciliation_tenant.subject', 'saas.mail.reconciliation_tenant.body', [
            'table' => $report->table_name, 'column' => $report->column_name, 'orphans' => $report->orphan_count, 'checked' => $report->checked_count,
            'status' => (string) __('super.reconciliation.status.'.$report->status),
            'note' => $note !== '' ? $note : '—',
        ], null, $request->admin()->id);

        $audit->record(CentralAuditAction::Update, $tenant, $report, null, ['notified' => $sent, 'note' => $note !== '' ? $note : null], $request->admin()->id);

        return back()->with($sent ? 'flash.success' : 'flash.error', __($sent ? 'super.reconciliation.flash.notified' : 'super.reconciliation.flash.notify_failed'));
    }

    /**
     * What the catalogue says about every referenced id in `details`: its current name and state, or that it is gone.
     *
     * @return array<string, list<array{ref: string, rows: int, name: string|null, is_active: bool|null, exists: bool}>>
     */
    private function references(CatalogReconciliationReport $report): array
    {
        $entity = self::entityOf($report->column_name);
        $out = [];

        foreach ((array) $report->details as $kind => $refs) {
            if (! is_array($refs)) {
                continue;
            }

            $list = [];

            foreach ($refs as $ref => $count) {
                $row = $this->lookup($entity, (string) $ref);
                $list[] = ['ref' => (string) $ref, 'rows' => (int) $count, 'name' => $row['name'] ?? null, 'is_active' => $row['is_active'] ?? null, 'exists' => $row !== null];
            }

            $out[(string) $kind] = $list;
        }

        return $out;
    }

    /**
     * The tenant rows behind `sample_ids`, read from the clinic's schema: the snapshotted names prove what the
     * doctor actually wrote, which is why nothing here is ever "fixed" automatically.
     *
     * @return list<array<string, mixed>>
     */
    private function sampleRows(CatalogReconciliationReport $report): array
    {
        $tenant = $report->tenant;
        $ids = array_values(array_filter(array_map('intval', (array) $report->sample_ids)));

        if ($tenant === null || $ids === []) {
            return [];
        }

        $table = $report->table_name;
        $column = $report->column_name;

        try {
            return Tenancy::run($tenant, function () use ($table, $column, $ids): array {
                $rows = DB::table($table)->whereIn('id', array_slice($ids, 0, 50))->orderBy('id')->get();

                return $rows->map(function (object $r) use ($column): array {
                    $out = ['id' => (int) $r->id, 'ref' => $r->{$column} ?? null];

                    foreach (['generic_name', 'brand_name', 'strength', 'form', 'name', 'brand', 'created_at', 'prescription_id', 'patient_id'] as $col) {
                        if (property_exists($r, $col)) {
                            $out[$col] = $r->{$col};
                        }
                    }

                    return $out;
                })->values()->all();
            });
        } catch (\Throwable) {
            return [];                                             // a dropped column or a schema mid-migration: the report stands on its own
        }
    }

    /** @return array{name: string|null, is_active: bool}|null */
    private function lookup(string $entity, string $ref): ?array
    {
        $c = DB::connection('catalog');
        $row = match ($entity) {
            'icd10_codes' => $c->table('icd10_codes')->where('code', $ref)->first(['title AS name', 'is_active']),
            'strengths' => $c->table('strengths AS s')->join('brands AS b', 'b.id', '=', 's.brand_id')->where('s.id', (int) $ref)->selectRaw("b.name || ' ' || s.strength_label AS name, s.is_active")->first(),
            default => ctype_digit($ref) ? $c->table($entity)->where('id', (int) $ref)->first(['name', 'is_active']) : null,
        };

        return $row === null ? null : ['name' => $row->name ?? null, 'is_active' => (bool) $row->is_active];
    }

    private static function entityOf(string $column): string
    {
        return match (true) {
            str_contains($column, 'strength') => 'strengths',
            str_contains($column, 'brand') => 'brands',
            str_contains($column, 'allergy_class') => 'allergy_classes',
            str_contains($column, 'icd') => 'icd10_codes',
            default => 'generics',
        };
    }

    private static function lastNotification(CatalogReconciliationReport $report): ?string
    {
        $last = AuditLogCentral::query()->where('auditable_type', $report->getMorphClass())->where('auditable_id', $report->id)
            ->where('action', CentralAuditAction::Update->value)->whereRaw("after->>'notified' = 'true'")->orderByDesc('id')->value('occurred_at');

        return $last === null ? null : CarbonImmutable::parse((string) $last)->toIso8601String();
    }

    /** @return array<string, mixed> */
    private static function present(CatalogReconciliationReport $r): array
    {
        return [
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
        ];
    }
}
