<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Clinic\Listeners\RecordStaffSession;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Services\StaffSessionIndex;
use App\Models\Tenant\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The idle timeout of BRIEF §5.N. `users.session_timeout_minutes` and the tenant setting
 * `security.session_timeout_minutes` were editable and read by nothing — an admin who set 15 minutes got no
 * protection and no warning, which is worse than the feature being absent.
 *
 * The effective limit is the per-user value, falling back to the tenant setting (super admins use
 * `session.idle_timeout_minutes`, since there is no tenant to ask). Past it the guard is logged out, the session is
 * invalidated and the person is sent back to their login screen with a message that says why.
 *
 * Registered on the `panel` and `super` route groups only, deliberately NOT on `api`: the panel's background
 * traffic (the `/api/ping` heartbeat, the dashboard refresh poll, queue state) all lives on `api`, and a timer an
 * open tab silently resets is not an idle timeout. Human navigation of a session-authenticated screen is the
 * activity that counts.
 */
final class EnforceIdleTimeout
{
    public const SESSION_KEY = 'idle_last_activity_at';

    /** Refresh `last_seen_at` in the device index at most this often, so a chatty screen is not a write storm. */
    private const INDEX_TOUCH_SECONDS = 60;

    /**
     * The smallest limit anything can configure: `SettingsRegistry` pins `security.session_timeout_minutes` to
     * `min: 5` and `Store/UpdateStaffUserRequest` validate `session_timeout_minutes` `between:5,1440`. Below this
     * much idle time no session can possibly have expired, so the tenant settings row is not read at all — a
     * person clicking through the panel must not pay a query per page for a control that cannot have fired yet.
     */
    private const FLOOR_MINUTES = 5;

    private const INDEX_SESSION_KEY = 'idle_last_index_touch_at';

    public function __construct(
        private readonly Settings $settings,
        private readonly StaffSessionIndex $sessions,
    ) {}

    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        if (! $request->hasSession() || ! Auth::guard($guard)->check()) {
            return $next($request);
        }

        $session = $request->session();
        $now = CarbonImmutable::now()->getTimestamp();
        $last = $session->get(self::SESSION_KEY);

        if (is_int($last)) {
            $idle = $now - $last;
            $limit = $this->limitMinutes($guard, $idle);

            if ($limit !== null && $limit > 0 && $idle > $limit * 60) {
                return $this->expire($request, $guard, $idle, $limit);
            }
        }

        $session->put(self::SESSION_KEY, $now);
        $this->touchIndex($request, $guard, $now);

        return $next($request);
    }

    /**
     * The effective limit in minutes; 0 means "no idle limit" (only reachable by configuring the super console's
     * key to 0). `null` means "not worth resolving": the session has been idle for less than any configurable
     * limit, so the answer cannot change the outcome and the settings row is left unread.
     */
    private function limitMinutes(string $guard, int $idleSeconds): ?int
    {
        if ($guard !== 'web') {
            return max(0, (int) config('session.idle_timeout_minutes', (int) config('session.lifetime')));
        }

        $user = Auth::guard($guard)->user();

        if ($user instanceof User && $user->session_timeout_minutes !== null) {
            return $user->session_timeout_minutes;      // already loaded with the guard: free
        }

        if ($idleSeconds < self::FLOOR_MINUTES * 60) {
            return null;
        }

        return (int) $this->settings->get('security.session_timeout_minutes');
    }

    /**
     * Keep the `sessions_by_user` entry for this session current: create it the first time this session id is seen
     * (the login listener cannot, because the id is regenerated right after Login fires), then refresh
     * `last_seen_at` at most once a minute so a busy screen is not a write storm.
     */
    private function touchIndex(Request $request, string $guard, int $now): void
    {
        if ($guard !== 'web') {
            return;
        }

        $session = $request->session();
        $lastTouch = $session->get(self::INDEX_SESSION_KEY);

        if (is_int($lastTouch) && ($now - $lastTouch) < self::INDEX_TOUCH_SECONDS) {
            return;
        }

        $user = Auth::guard($guard)->user();

        if (! $user instanceof User) {
            return;
        }

        $sessionId = $session->getId();

        if ($this->sessions->has($user, $sessionId)) {
            $this->sessions->touch($user, $sessionId);
        } else {
            $loginAt = $session->get(RecordStaffSession::LOGIN_AT_KEY);
            $this->sessions->remember($user, $sessionId, $request->ip(), $request->userAgent(), is_int($loginAt) ? $loginAt : null);
        }

        $session->put(self::INDEX_SESSION_KEY, $now);
    }

    private function expire(Request $request, string $guard, int $idleSeconds, int $limitMinutes): Response
    {
        $session = $request->session();
        $user = Auth::guard($guard)->user();

        if ($guard === 'web' && $user instanceof User) {
            $this->sessions->forget($user, $session->getId());
        }

        Log::info('session.idle_timeout', ['guard' => $guard, 'idle_seconds' => $idleSeconds, 'limit_minutes' => $limitMinutes]);

        Auth::guard($guard)->logout();
        $session->invalidate();
        $session->regenerateToken();

        $message = __('auth.idle_timeout', ['minutes' => $limitMinutes]);

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()
            ->route($request->routeIs('super.*') ? 'super.login' : 'panel.login')
            ->with('flash.error', $message);
    }
}
