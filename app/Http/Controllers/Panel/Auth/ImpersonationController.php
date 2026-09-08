<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Auth;

use App\Domain\SaaS\Actions\Impersonation\ConsumeImpersonationToken;
use App\Domain\SaaS\Actions\Impersonation\EndImpersonation;
use App\Domain\SaaS\Exceptions\ImpersonationTokenInvalid;
use App\Http\Controllers\Controller;
use App\Models\Central\ImpersonationToken;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The tenant-side half of impersonation (ARCHITECTURE §6.5).
 *
 * `enter` is deliberately outside `auth:web`: the whole point is that nobody is signed in yet. It is safe because
 * the token is the credential — 64 random characters, stored only as a sha256, valid for 60 seconds, spent by a
 * conditional UPDATE that exactly one caller can win, and bound to the tenant of the host it arrives on.
 *
 * The session carries three markers for the rest of the visit:
 *   `impersonated_by`      the super admin id — `AuditRecorder` stamps it on EVERY tenant audit row written during
 *                          the session (`audit_logs.impersonator_super_admin_id`), and `HandleInertiaRequests`
 *                          turns it into `SharedProps.auth.impersonating`, which is what makes `PanelLayout` show
 *                          the banner on every page;
 *   `impersonation_user_id`/`impersonation_started_at`  so leaving can audit precisely what is ending.
 *
 * `leave` logs out and invalidates the session — the impersonated identity must not survive the exit in any form —
 * and sends the operator back to the console on the central host.
 */
final class ImpersonationController extends Controller
{
    public function enter(Request $request, string $token, ConsumeImpersonationToken $consume): RedirectResponse
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            abort(404);
        }

        try {
            $user = $consume->handle($token, $tenant);
        } catch (ImpersonationTokenInvalid $e) {
            Log::warning('saas.impersonation.refused', ['tenant_id' => $tenant->id, 'reason' => $e->reason, 'ip' => $request->ip()]);

            abort(403, __('saas.impersonation.invalid'));
        }

        $superAdminId = (int) $request->session()->get('impersonated_by');

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonated_by', $this->superAdminIdFor($token, $superAdminId));
        $request->session()->put('impersonation_user_id', $user->id);
        $request->session()->put('impersonation_started_at', CarbonImmutable::now()->toIso8601String());

        return redirect()->route('panel.impersonate.started');
    }

    public function started(Request $request): Response
    {
        abort_unless($request->session()->has('impersonated_by'), 404);

        $user = $request->user('web');
        $tenant = Tenancy::current();

        return Inertia::render('Super/Impersonating', [
            'tenant' => ['name' => $tenant?->name, 'slug' => $tenant?->slug],
            'user' => $user instanceof User ? ['name' => $user->name, 'email' => $user->email, 'roles' => $user->getRoleNames()->values()->all()] : null,
            'started_at' => (string) $request->session()->get('impersonation_started_at'),
            'console_url' => $this->consoleUrl($request),
        ]);
    }

    public function leave(Request $request, EndImpersonation $end): HttpResponse
    {
        $superAdminId = (int) $request->session()->get('impersonated_by');
        $userId = (int) $request->session()->get('impersonation_user_id');
        $tenant = Tenancy::current();

        if ($tenant !== null && $superAdminId > 0) {
            $end->handle($tenant, $superAdminId, $userId);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $url = $this->consoleUrl($request);

        // Back to the console on the super host: cross-origin, so an Inertia caller needs a location visit.
        return $request->header('X-Inertia') !== null ? Inertia::location($url) : redirect()->away($url);
    }

    /** The token row is the authority for who is impersonating; the session value is only a hint. */
    private function superAdminIdFor(string $token, int $fallback): int
    {
        $id = ImpersonationToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->value('super_admin_id');

        return $id === null ? $fallback : (int) $id;
    }

    private function consoleUrl(Request $request): string
    {
        $port = $request->getPort();
        $scheme = $request->getScheme();
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return $scheme.'://super.'.config('tenancy.central_domain').($default ? '' : ':'.$port).'/tenants';
    }
}
