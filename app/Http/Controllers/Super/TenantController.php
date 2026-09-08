<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Queries\TenantOverview;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\DomainVerifier;
use App\Domain\SaaS\Services\UsageMeter;
use App\Http\Controllers\Controller;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Domain;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Tenant list with health and usage, and the tenant detail every operational action hangs off. */
final class TenantController extends Controller
{
    public function index(Request $request, TenantOverview $overview): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(TenantStatus::values())],
        ]);

        $page = $overview->list($validated['q'] ?? null, $validated['status'] ?? null);

        return Inertia::render('Super/Tenants/Index', [
            'tenants' => $page['data'],
            'meta' => $page['meta'],
            'filters' => ['q' => $validated['q'] ?? '', 'status' => $validated['status'] ?? ''],
            'statuses' => TenantStatus::values(),
            'totals' => $overview->platformTotals(),
        ]);
    }

    public function show(Tenant $tenant, TenantOverview $overview, UsageMeter $meter, CentralAudit $audit): Response
    {
        $audit->record(CentralAuditAction::View, $tenant, $tenant);
        $central = (string) config('tenancy.central_domain');

        return Inertia::render('Super/Tenants/Show', [
            'tenant' => $overview->detail($tenant),
            'plans' => (new PlanCatalog)->all(),
            'feature_labels' => PlanCatalog::featureLabels(),
            'metric_labels' => PlanCatalog::metricLabels(),
            'toggles' => array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::toggles()),
            'limit_keys' => array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::limits()),
            'history' => [
                'appointments' => $meter->history($tenant, UsageMetric::Appointments),
                'sms_credits' => $meter->history($tenant, UsageMetric::SmsCredits),
                'prescriptions' => $meter->history($tenant, UsageMetric::Prescriptions),
            ],
            'invoices' => SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(24)->get()
                ->map(fn (SubscriptionInvoice $i) => [
                    'public_id' => $i->public_id, 'number' => $i->number, 'status' => $i->status->value,
                    'total_paisa' => $i->total_paisa, 'paid_paisa' => (int) $i->getAttribute('paid_paisa'),
                    'period_start' => $i->getAttribute('period_start')?->toDateString(),
                    'period_end' => $i->getAttribute('period_end')?->toDateString(),
                    'issued_at' => $i->getAttribute('issued_at')?->toIso8601String(),
                    'due_at' => $i->getAttribute('due_at')?->toIso8601String(),
                    'dunning_step' => (int) $i->getAttribute('dunning_step'),
                ])->all(),
            'domains' => Domain::query()->where('tenant_id', $tenant->id)->orderByDesc('is_primary')->orderBy('id')->get()
                ->map(fn (Domain $d) => [
                    'id' => $d->id, 'domain' => $d->domain, 'type' => $d->type->value, 'is_primary' => $d->is_primary,
                    'verification_status' => $d->verification_status->value,
                    'verified_at' => $d->getAttribute('verified_at')?->toIso8601String(),
                    'last_checked_at' => $d->getAttribute('last_checked_at')?->toIso8601String(),
                    'instructions' => DomainVerifier::instructions($d, $central),
                ])->all(),
            'backups' => TenantBackup::query()->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(12)->get()
                ->map(fn (TenantBackup $b) => [
                    'id' => $b->id, 'type' => $b->type->value, 'status' => $b->status->value,
                    'size_bytes' => $b->getAttribute('size_bytes'),
                    'completed_at' => $b->getAttribute('completed_at')?->toIso8601String(),
                    'expires_at' => $b->getAttribute('expires_at')?->toIso8601String(),
                    'error' => $b->getAttribute('error'),
                ])->all(),
            'audit' => AuditLogCentral::query()->where('tenant_id', $tenant->id)->with('superAdmin:id,name')
                ->orderByDesc('occurred_at')->limit(30)->get()
                ->map(fn (AuditLogCentral $l) => [
                    'action' => $l->action->value,
                    'actor' => $l->superAdmin?->name,
                    'auditable_type' => $l->getAttribute('auditable_type'),
                    'before' => $l->getAttribute('before'),
                    'after' => $l->getAttribute('after'),
                    'ip' => $l->getAttribute('ip'),
                    'occurred_at' => $l->getAttribute('occurred_at')?->toIso8601String(),
                ])->all(),
        ]);
    }
}
