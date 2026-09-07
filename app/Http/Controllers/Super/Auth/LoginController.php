<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Auth;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Auth\LoginRequest;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin login on super.{central} (guard super). Renders panel page Super/Auth/Login. TOTP is the SaaS module's.
 */
final class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Super/Auth/Login', ['status' => session('status')]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $key = 'super-login:'.Str::lower($request->credentials()['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $guard = Auth::guard('super');

        if (! $guard->attempt($request->credentials() + ['is_active' => true], $request->remember())) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        /** @var SuperAdmin $admin */
        $admin = $guard->user();
        $admin->forceFill(['last_login_at' => CarbonImmutable::now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        $this->auditCentral($request, $admin, CentralAuditAction::Login);

        return redirect()->intended(route('super.dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $admin = $request->user('super');

        if ($admin instanceof SuperAdmin) {
            $this->auditCentral($request, $admin, CentralAuditAction::Logout);
        }

        Auth::guard('super')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('super.login');
    }

    private function auditCentral(Request $request, SuperAdmin $admin, CentralAuditAction $action): void
    {
        AuditLogCentral::query()->create([
            'super_admin_id' => $admin->id,
            'action' => $action,
            'auditable_type' => $admin->getMorphClass(),
            'auditable_id' => $admin->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => $request->attributes->get('request_id'),
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
