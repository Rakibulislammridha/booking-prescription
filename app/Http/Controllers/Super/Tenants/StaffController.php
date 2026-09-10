<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\SaaS\Actions\Tenants\CreateTenantStaffUser;
use App\Domain\SaaS\Actions\Tenants\ResetTenantStaffPassword;
use App\Domain\SaaS\Actions\Tenants\SetTenantStaffStatus;
use App\Domain\SaaS\Data\CredentialReveal;
use App\Domain\SaaS\Exceptions\StaffEmailTaken;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Tenants\ResetTenantStaffPasswordRequest;
use App\Http\Requests\Super\Tenants\StoreTenantStaffRequest;
use App\Http\Requests\Super\Tenants\UpdateTenantStaffStatusRequest;
use App\Models\Central\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Support's hands inside a clinic's staff list (BRIEF §5.M): create a hospital admin, hand out a way back in, and
 * switch an account off or on. Every write goes through the clinic's own Clinic actions inside `Tenancy::run()`,
 * and every one leaves an `audit_logs_central` row. Signing in as one of them is `ImpersonationController`.
 */
final class StaffController extends Controller
{
    public function store(StoreTenantStaffRequest $request, Tenant $tenant, CreateTenantStaffUser $create): RedirectResponse
    {
        try {
            $result = $create->handle($tenant, $request->toData(), (int) $request->user('super')?->getAuthIdentifier(), $request->ip());
        } catch (StaffEmailTaken $e) {
            throw ValidationException::withMessages(['email' => $e->getMessage()]);
        }

        return redirect()->route('super.tenants.show', ['tenant' => $tenant->public_id])
            ->with(CredentialReveal::SESSION_KEY, $result['reveal']->toArray())
            ->with('flash.success', __('super.tenants.staff.flash.created', ['name' => (string) $result['user']['name']]));
    }

    public function resetPassword(ResetTenantStaffPasswordRequest $request, Tenant $tenant, string $user, ResetTenantStaffPassword $reset): RedirectResponse
    {
        $reveal = $reset->handle($tenant, $user, $request->mode(), (int) $request->user('super')?->getAuthIdentifier());

        return redirect()->route('super.tenants.show', ['tenant' => $tenant->public_id])
            ->with(CredentialReveal::SESSION_KEY, $reveal->toArray())
            ->with('flash.success', __('super.tenants.staff.flash.reset', ['name' => $reveal->userName]));
    }

    public function status(UpdateTenantStaffStatusRequest $request, Tenant $tenant, string $user, SetTenantStaffStatus $status): RedirectResponse
    {
        $row = $status->handle($tenant, $user, $request->isActive(), (int) $request->user('super')?->getAuthIdentifier(), $request->ip());

        return back()->with('flash.success', __($request->isActive() ? 'super.tenants.staff.flash.activated' : 'super.tenants.staff.flash.deactivated', ['name' => (string) $row['name']]));
    }
}
