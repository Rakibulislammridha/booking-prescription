<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Auth;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Super\Auth\Concerns\CompletesSuperLogin;
use App\Http\Requests\Super\Auth\LoginRequest;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin login on super.{central} (guard super). Renders panel page Super/Auth/Login.
 *
 * The password is only the FIRST half for an enrolled operator (ARCHITECTURE §6.5). Rather than logging them in
 * and then fencing the session off, a correct password parks a half-finished login in the session
 * (`SuperTwoFactor::SESSION_PENDING`) and nobody is authenticated until `TwoFactorChallengeController` says so —
 * a session that has not passed the challenge is a guest, which is a much stronger statement than a middleware
 * check, and it is why an unchallenged session cannot mint an impersonation token.
 */
final class LoginController extends Controller
{
    use CompletesSuperLogin;

    public function __construct(
        private readonly SuperTwoFactor $twoFactor,
        private readonly CentralAudit $audit,
    ) {}

    public function create(): Response
    {
        return Inertia::render('Super/Auth/Login', ['status' => session('status')]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->credentials();
        $key = 'super-login:'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $admin = $this->retrieve($credentials);

        if (! $admin instanceof SuperAdmin) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);

        if ($this->twoFactor->enabled($admin)) {
            // Deliberately NOT logged in. The pending marker carries an id and a timestamp and nothing else —
            // it is not a session for the console, it is a receipt for the password half, and it expires.
            $request->session()->put(SuperTwoFactor::SESSION_PENDING, [
                'id' => $admin->id,
                'at' => CarbonImmutable::now()->getTimestamp(),
            ]);

            return redirect()->route('super.two-factor.challenge');
        }

        // No "remember me" on the super guard (B4): the console can impersonate into any clinic's records, so a
        // durable recaller cookie that could re-authenticate it — and, worse, one that bypasses the second-factor
        // challenge — is not a trade worth making. Every super session begins with the password (and the code).
        Auth::guard('super')->login($admin);
        $request->session()->regenerate();

        return $this->completeSuperLogin($request, $admin, $this->audit);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $admin = $request->user('super');

        if ($admin instanceof SuperAdmin) {
            $this->audit->record(CentralAuditAction::Logout, null, $admin, superAdminId: $admin->id);
        }

        Auth::guard('super')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('super.login');
    }

    /**
     * The `super` provider, asked directly rather than through `attempt()`: `attempt()` would log the operator in
     * before we have decided whether they are allowed to be logged in yet.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    private function retrieve(array $credentials): ?SuperAdmin
    {
        /** @var UserProvider $provider */
        $provider = Auth::createUserProvider('super_admins');
        $admin = $provider->retrieveByCredentials(['email' => $credentials['email'], 'is_active' => true]);

        if (! $admin instanceof SuperAdmin || ! $provider->validateCredentials($admin, $credentials)) {
            return null;
        }

        return $admin;
    }
}
