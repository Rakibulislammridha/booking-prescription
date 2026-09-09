<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Auth\Concerns;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The last three lines of a super login, wherever it finishes: straight after the password when the operator has
 * no second factor, and after the TOTP challenge when they do. Kept in one place so the `SESSION_PASSED_AT` stamp
 * — the thing `EnsureSuperTwoFactor` reads — can never be forgotten on one of the two paths.
 */
trait CompletesSuperLogin
{
    protected function completeSuperLogin(Request $request, SuperAdmin $admin, CentralAudit $audit): RedirectResponse
    {
        $admin->forceFill(['last_login_at' => CarbonImmutable::now(), 'last_login_ip' => $request->ip()])->saveQuietly();

        $request->session()->forget(SuperTwoFactor::SESSION_PENDING);
        $request->session()->put(SuperTwoFactor::SESSION_PASSED_AT, CarbonImmutable::now()->getTimestamp());

        $audit->record(CentralAuditAction::Login, null, $admin, superAdminId: $admin->id);

        return redirect()->intended(route('super.dashboard', absolute: false));
    }
}
