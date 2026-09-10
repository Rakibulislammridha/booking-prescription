<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Actions\Tenants\CreateTenantFromConsole;
use App\Domain\SaaS\Actions\Tenants\DeleteTenant;
use App\Domain\SaaS\Actions\Tenants\UpdateTenantProfile;
use App\Domain\SaaS\Data\CredentialReveal;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Queries\BillingTenantPanel;
use App\Domain\SaaS\Queries\PlanCatalog;
use App\Domain\SaaS\Queries\TenantOverview;
use App\Domain\SaaS\Queries\TenantStaffDirectory;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\DomainVerifier;
use App\Domain\SaaS\Services\TenantLinks;
use App\Domain\SaaS\Services\UsageMeter;
use App\Domain\Tenancy\Exceptions\ProvisioningFailed;
use App\Domain\Tenancy\Exceptions\SlugReserved;
use App\Domain\Tenancy\Exceptions\SlugTaken;
use App\Domain\Tenancy\Rules\NotReservedSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Tenants\DeleteTenantRequest;
use App\Http\Requests\Super\Tenants\StoreTenantRequest;
use App\Http\Requests\Super\Tenants\UpdateTenantRequest;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Domain;
use App\Models\Central\Plan;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use App\Tenancy\Facades\Tenancy;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant list with health and usage, the tenant detail every operational action hangs off, and the clinic's own
 * lifecycle as a resource: created from the console (the same `ProvisionTenant` path as the public wizard),
 * edited, and — behind two steps — deleted.
 */
final class TenantController extends Controller
{
    public function index(Request $request, TenantOverview $overview): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([...TenantStatus::values(), TenantOverview::STATUS_DELETED])],
            'plan' => ['nullable', 'string', 'max:40'],
            'attention' => ['nullable', Rule::in(TenantOverview::ATTENTION)],
            'sort' => ['nullable', Rule::in(TenantOverview::SORTS)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $filters = [
            'q' => (string) ($validated['q'] ?? ''),
            'status' => (string) ($validated['status'] ?? ''),
            'plan' => (string) ($validated['plan'] ?? ''),
            'attention' => (string) ($validated['attention'] ?? ''),
            'sort' => (string) ($validated['sort'] ?? 'created'),
            'dir' => (string) ($validated['dir'] ?? ''),
        ];
        $page = $overview->list($filters['q'], $filters['status'], 25, $filters);

        return Inertia::render('Super/Tenants/Index', [
            'tenants' => $page['data'],
            'meta' => $page['meta'],
            'filters' => $filters,
            'statuses' => TenantStatus::values(),
            'plans' => Plan::query()->whereNull('archived_at')->where('is_addon', false)->orderBy('sort_order')->get(['code', 'name'])
                ->map(fn (Plan $p) => ['code' => $p->code, 'name' => $p->name])->all(),
            'attention' => TenantOverview::ATTENTION,
            'sorts' => TenantOverview::SORTS,
            'totals' => $overview->platformTotals(),
        ]);
    }

    public function create(PlanCatalog $catalog): Response
    {
        return Inertia::render('Super/Tenants/Create', [
            'plans' => array_values(array_filter($catalog->all(), fn (array $plan) => ! $plan['is_addon'] && ($plan['archived_at'] ?? null) === null)),
            'central_domain' => (string) config('tenancy.central_domain'),
            'reserved_slugs' => NotReservedSlug::reserved(),
            'timezones' => self::timezones(),
            'roles' => Role::values(),
        ]);
    }

    public function store(StoreTenantRequest $request, CreateTenantFromConsole $create): RedirectResponse
    {
        try {
            $result = $create->handle($request->toData(), (int) $request->user('super')?->getAuthIdentifier());
        } catch (SlugTaken|SlugReserved) {
            throw ValidationException::withMessages(['slug' => __('saas.onboarding.slug_taken')]);
        } catch (ProvisioningFailed $e) {
            report($e);
            Tenancy::check() && Tenancy::end();

            throw ValidationException::withMessages(['name' => __('saas.onboarding.failed')]);
        }

        return redirect()->route('super.tenants.show', ['tenant' => $result['tenant']->public_id])
            ->with(CredentialReveal::SESSION_KEY, $result['reveal']->toArray())
            ->with('flash.success', __('super.tenants.flash.created', ['clinic' => $result['tenant']->name]));
    }

    public function show(Request $request, Tenant $tenant, TenantOverview $overview, UsageMeter $meter, CentralAudit $audit, TenantStaffDirectory $staff, DeleteTenant $delete, TenantLinks $links, BillingTenantPanel $billing): Response
    {
        $audit->record(CentralAuditAction::View, $tenant, $tenant);
        $central = (string) config('tenancy.central_domain');
        $export = DeleteTenant::freshExport($tenant);

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
                    'ssl_status' => $d->ssl_status->value,
                    'ssl_expires_at' => $d->getAttribute('ssl_expires_at')?->toIso8601String(),
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
            // The Billing tab is the billing module's own card (Components/Super/TenantBillingCard); this is its read model.
            'billing' => $billing->for($tenant),
            'staff' => $staff->list($tenant),
            'roles' => Role::values(),
            // Pulled, not read: a temporary password or set-password link is shown exactly once.
            'reveal' => CredentialReveal::fromArray($request->session()->pull(CredentialReveal::SESSION_KEY))?->toArray(),
            'deletion' => [
                'unsettled_invoices' => $delete->unsettledInvoices($tenant),
                'export_max_age_hours' => DeleteTenant::EXPORT_MAX_AGE_HOURS,
                'export' => $export === null ? null : ['id' => $export->id, 'completed_at' => $export->getAttribute('completed_at')?->toIso8601String(), 'size_bytes' => $export->getAttribute('size_bytes')],
            ],
            'links' => ['panel' => $links->panel($tenant)],
        ]);
    }

    public function edit(Tenant $tenant, TenantOverview $overview): Response
    {
        return Inertia::render('Super/Tenants/Edit', [
            'tenant' => $overview->detail($tenant),
            'central_domain' => (string) config('tenancy.central_domain'),
            'timezones' => self::timezones(),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant, UpdateTenantProfile $update): RedirectResponse
    {
        $logo = $request->file('logo');
        $update->handle($tenant, $request->toData(), $logo instanceof UploadedFile ? $logo : null, (int) $request->user('super')?->getAuthIdentifier());

        return redirect()->route('super.tenants.show', ['tenant' => $tenant->public_id])
            ->with('flash.success', __('super.tenants.flash.updated', ['clinic' => $tenant->refresh()->name]));
    }

    public function destroy(DeleteTenantRequest $request, Tenant $tenant, DeleteTenant $delete): RedirectResponse
    {
        $result = $delete->handle($tenant, (int) $request->user('super')?->getAuthIdentifier());

        return redirect()->route('super.tenants.index')
            ->with('flash.warning', __('super.tenants.flash.deleted', ['clinic' => $tenant->name, 'schema' => $result['schema'], 'export' => (string) $result['export']->storage_path]));
    }

    /**
     * The zones a Bangladeshi clinic platform actually sells into, plus UTC. `timezone:all` still validates
     * anything IANA knows, so a hand-typed value on the edit form is accepted too.
     *
     * @return array<int, string>
     */
    private static function timezones(): array
    {
        $zones = array_merge(
            ['Asia/Dhaka', 'Asia/Kolkata', 'Asia/Kathmandu', 'Asia/Karachi', 'Asia/Dubai', 'Asia/Riyadh', 'Asia/Kuala_Lumpur', 'Asia/Singapore', 'Europe/London', 'UTC'],
            DateTimeZone::listIdentifiers(DateTimeZone::ASIA),
        );

        return array_values(array_unique($zones));
    }
}
