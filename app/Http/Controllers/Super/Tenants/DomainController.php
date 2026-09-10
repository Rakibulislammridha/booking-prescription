<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Actions\Domains\AddCustomDomain;
use App\Domain\SaaS\Actions\Domains\RemoveCustomDomain;
use App\Domain\SaaS\Actions\Domains\SetPrimaryDomain;
use App\Domain\SaaS\Actions\Domains\VerifyCustomDomain;
use App\Domain\SaaS\Services\CentralAudit;
use App\Http\Controllers\Controller;
use App\Models\Central\Domain;
use App\Models\Central\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The super admin's copy of the tenant's own domain screen. It bypasses the `custom_domain` plan check
 * (`requireFeature: false`) because support adding a domain for a customer mid-negotiation is a legitimate
 * override, and the action it calls is otherwise identical — same uniqueness rules, same TXT proof.
 */
final class DomainController extends Controller
{
    public function store(Request $request, Tenant $tenant, AddCustomDomain $add): RedirectResponse
    {
        $validated = $request->validate(['domain' => ['required', 'string', 'max:253']]);
        $add->handle($tenant, (string) $validated['domain'], requireFeature: false);

        return back()->with('flash.success', __('saas.domains.flash.added'));
    }

    public function verify(Tenant $tenant, Domain $domain, VerifyCustomDomain $verify, CentralAudit $audit): RedirectResponse
    {
        abort_unless($domain->tenant_id === $tenant->id, 404);
        $before = ['verification_status' => $domain->verification_status->value];
        $result = $verify->handle($domain);
        $audit->record(CentralAuditAction::Update, $tenant, $domain, $before, ['domain' => $domain->domain, 'verification_status' => $domain->verification_status->value, 'checked' => $result->checkedName, 'reason' => $result->reason]);

        return back()->with(
            $result->verified ? 'flash.success' : 'flash.warning',
            $result->verified ? __('saas.domains.flash.verified') : __('saas.domains.reason.'.$result->reason),
        );
    }

    public function primary(Tenant $tenant, Domain $domain, SetPrimaryDomain $primary, CentralAudit $audit): RedirectResponse
    {
        abort_unless($domain->tenant_id === $tenant->id, 404);
        $previous = Domain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->value('domain');
        $primary->handle($domain);
        $audit->record(CentralAuditAction::Update, $tenant, $domain, ['primary' => $previous], ['primary' => $domain->domain]);

        return back()->with('flash.success', __('saas.domains.flash.primary'));
    }

    public function destroy(Tenant $tenant, Domain $domain, RemoveCustomDomain $remove): RedirectResponse
    {
        abort_unless($domain->tenant_id === $tenant->id, 404);
        $remove->handle($domain);

        return back()->with('flash.success', __('saas.domains.flash.removed'));
    }
}
