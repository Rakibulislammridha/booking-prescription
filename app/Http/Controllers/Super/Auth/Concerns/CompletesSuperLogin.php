<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Auth\Concerns;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The last lines of a super login, wherever it finishes: straight after the password when the operator is not
 * challenged (not enrolled, or the platform policy is `disabled`), and after the TOTP challenge when they are.
 * Kept in one place so the `SESSION_PASSED_AT` stamp — the thing `EnsureSuperTwoFactor` reads — is written by
 * exactly one path and means exactly one thing: THIS session presented a second factor. A password-only login
 * never stamps it, which is what lets a policy switched from `disabled` back to `required`/`optional` send an
 * enrolled operator back to sign in properly on their very next request (ARCHITECTURE §6.5).
 *
 * Both callers regenerate the session id BEFORE arriving here, so the id written into `SuperSessionIndex` is the
 * one the browser will actually present — the Profile screen's device list is built from it.
 */
trait CompletesSuperLogin
{
    protected function completeSuperLogin(Request $request, SuperAdmin $admin, CentralAudit $audit, bool $challengePassed): RedirectResponse
    {
        $admin->forceFill(['last_login_at' => CarbonImmutable::now(), 'last_login_ip' => $request->ip()])->saveQuietly();

        $request->session()->forget(SuperTwoFactor::SESSION_PENDING);

        if ($challengePassed) {
            $request->session()->put(SuperTwoFactor::SESSION_PASSED_AT, CarbonImmutable::now()->getTimestamp());
        } else {
            $request->session()->forget(SuperTwoFactor::SESSION_PASSED_AT);
        }

        app(SuperSessionIndex::class)->remember($admin, $request->session()->getId(), $request->ip(), $request->userAgent());

        $audit->record(CentralAuditAction::Login, null, $admin, null, ['two_factor' => $challengePassed], superAdminId: $admin->id);

        return redirect()->intended(route('super.dashboard', absolute: false));
    }
}
