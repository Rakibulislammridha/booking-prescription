<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Admins;

use App\Domain\SaaS\Actions\Admins\DeactivateSuperAdmin;
use App\Domain\SaaS\Actions\Admins\ReactivateSuperAdmin;
use App\Domain\SaaS\Actions\Admins\ResetSuperAdminTwoFactor;
use App\Domain\SaaS\Actions\Admins\SendSuperSetPasswordLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Admins\ReauthenticatedRequest;
use App\Models\Central\SuperAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * What changes another operator's ACCESS: the kill switch and its undo, the "locked out of the authenticator"
 * reset, and a fresh set-password link. Resetting a second factor re-asks the acting operator's password.
 */
final class AdminAccessController extends Controller
{
    public function deactivate(Request $request, SuperAdmin $admin, DeactivateSuperAdmin $deactivate): RedirectResponse
    {
        $deactivate->handle($admin, $this->actor($request));

        return back()->with('flash.warning', __('super.admins.flash.deactivated', ['name' => $admin->name]));
    }

    public function reactivate(Request $request, SuperAdmin $admin, ReactivateSuperAdmin $reactivate): RedirectResponse
    {
        $reactivate->handle($admin, $this->actor($request));

        return back()->with('flash.success', __('super.admins.flash.reactivated', ['name' => $admin->name]));
    }

    public function resetTwoFactor(ReauthenticatedRequest $request, SuperAdmin $admin, ResetSuperAdminTwoFactor $reset): RedirectResponse
    {
        $reset->handle($admin, $request->admin());

        return back()->with('flash.warning', __('super.admins.flash.two_factor_reset', ['name' => $admin->name]));
    }

    public function sendPasswordLink(Request $request, SuperAdmin $admin, SendSuperSetPasswordLink $link): RedirectResponse
    {
        $link->handle($admin, $this->actor($request));

        return back()->with('flash.success', __('super.admins.flash.link_sent', ['email' => $admin->email]));
    }

    private function actor(Request $request): SuperAdmin
    {
        $admin = $request->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
