<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\SaaS;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\SaaS\Actions\Domains\AddCustomDomain;
use App\Domain\SaaS\Actions\Domains\RemoveCustomDomain;
use App\Domain\SaaS\Actions\Domains\SetPrimaryDomain;
use App\Domain\SaaS\Actions\Domains\VerifyCustomDomain;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Services\DomainVerifier;
use App\Domain\SaaS\Services\PlanLimits;
use App\Http\Controllers\Controller;
use App\Models\Central\Domain;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clinic's custom-domain screen: add a hostname, publish the TXT record we show, press verify.
 *
 * The whole flow is safe by construction because `TenantResolver` only ever joins on
 * `verification_status = 'verified'` — an unverified row exists in the table but routes nothing, so a clinic
 * cannot claim a hostname it does not own and cannot break its own site by adding one.
 */
final class DomainController extends Controller
{
    public function index(Request $request, PlanLimits $limits): Response
    {
        $tenant = Tenancy::current();
        abort_if($tenant === null, 404);
        $this->authorizeManage($request);

        $central = (string) config('tenancy.central_domain');

        return Inertia::render('SaaS/Domains', [
            'allowed' => $limits->enabled($tenant, PlanFeatureKey::CustomDomain),
            'plan_name' => $limits->entitlements($tenant)->planName,
            'central_domain' => $central,
            'domains' => Domain::query()->where('tenant_id', $tenant->id)->orderByDesc('is_primary')->orderBy('id')->get()
                ->map(fn (Domain $d) => [
                    'id' => $d->id,
                    'domain' => $d->domain,
                    'type' => $d->type->value,
                    'is_primary' => $d->is_primary,
                    'verification_status' => $d->verification_status->value,
                    'verified_at' => $d->getAttribute('verified_at')?->toIso8601String(),
                    'last_checked_at' => $d->getAttribute('last_checked_at')?->toIso8601String(),
                    'instructions' => DomainVerifier::instructions($d, $central),
                ])->all(),
        ]);
    }

    public function store(Request $request, AddCustomDomain $add): RedirectResponse
    {
        $this->authorizeManage($request);
        $tenant = Tenancy::current();
        abort_if($tenant === null, 404);

        $validated = $request->validate(['domain' => ['required', 'string', 'max:253']]);
        $add->handle($tenant, (string) $validated['domain']);

        return back()->with('flash.success', __('saas.domains.flash.added'));
    }

    public function verify(Request $request, Domain $domain, VerifyCustomDomain $verify): RedirectResponse
    {
        $this->authorizeManage($request);
        abort_unless($domain->tenant_id === Tenancy::id(), 404);

        $result = $verify->handle($domain);

        return back()->with(
            $result->verified ? 'flash.success' : 'flash.warning',
            $result->verified ? __('saas.domains.flash.verified') : __('saas.domains.reason.'.$result->reason),
        );
    }

    public function primary(Request $request, Domain $domain, SetPrimaryDomain $primary): RedirectResponse
    {
        $this->authorizeManage($request);
        abort_unless($domain->tenant_id === Tenancy::id(), 404);

        $primary->handle($domain);

        return back()->with('flash.success', __('saas.domains.flash.primary'));
    }

    public function destroy(Request $request, Domain $domain, RemoveCustomDomain $remove): RedirectResponse
    {
        $this->authorizeManage($request);
        abort_unless($domain->tenant_id === Tenancy::id(), 404);

        $remove->handle($domain);

        return back()->with('flash.success', __('saas.domains.flash.removed'));
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user('web')?->can(Permission::SaasSettingsManage->value) === true, 403);
    }
}
