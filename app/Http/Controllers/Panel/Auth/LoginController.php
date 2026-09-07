<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Auth\LoginRequest;
use App\Models\Tenant\User;
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
 * Staff login on the tenant host (guard web). Renders panel page Auth/Login.
 */
final class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', ['status' => session('status')]);
    }

    public function store(LoginRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $key = 'login:'.Str::lower($request->credentials()['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $guard = Auth::guard('web');

        if (! $guard->attempt($request->credentials() + ['is_active' => true], $request->remember())) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        /** @var User $user */
        $user = $guard->user();
        $user->forceFill(['last_login_at' => CarbonImmutable::now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        $audit->record(AuditAction::Login, $user, null, null, ['guard' => 'web']);

        return redirect()->intended(route('panel.dashboard', absolute: false));
    }

    public function destroy(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user('web');

        if ($user instanceof User) {
            $audit->record(AuditAction::Logout, $user, null, null, ['guard' => 'web']);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('panel.login');
    }
}
