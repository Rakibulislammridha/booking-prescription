<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Data\StaffSessionData;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Services\StaffSessionIndex;
use App\Http\Controllers\Controller;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff device management (BRIEF §5.N, ARCHITECTURE §6.1): which devices an account is signed in on, and a way to
 * throw one off. `authenticateSessions()` is deliberately not enabled, so without this screen a laptop left signed
 * in at a front desk stays signed in until the session lifetime runs out.
 *
 * A session is addressed by `ref` — a hash of the session id — so the page can list devices without ever putting a
 * usable session identifier in a browser, a log, or an audit row.
 */
final class StaffSessionController extends Controller
{
    public function __construct(
        private readonly StaffSessionIndex $sessions,
        private readonly Settings $settings,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request, User $user): Response
    {
        $this->authorize('manageSessions', $user);

        return Inertia::render('Clinic/Staff/Sessions', [
            'staff' => ['public_id' => $user->public_id, 'name' => $user->name, 'email' => $user->email],
            'sessions' => array_map(fn (StaffSessionData $s) => $s->toArray(), $this->sessions->all($user)),
            'idle_timeout_minutes' => $user->session_timeout_minutes ?? (int) $this->settings->get('security.session_timeout_minutes'),
            'is_self' => $this->actor($request)->is($user),
        ]);
    }

    public function destroy(Request $request, User $user, string $ref): RedirectResponse
    {
        $this->authorize('manageSessions', $user);

        $session = $this->sessions->findByRef($user, $ref);

        if ($session === null || ! $this->sessions->revoke($user, $session->id)) {
            return back()->with('flash.error', __('clinic.sessions.already_gone'));
        }

        $this->record($request, $user, ['revoked' => 1, 'device' => $session->device(), 'ip' => $session->ip, 'session_ref' => $ref]);

        // Revoking your OWN current session has to end this request's session too: the store would otherwise be
        // written back at terminate and resurrect the very session that was just destroyed.
        if ($session->isCurrent && $this->actor($request)->is($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('panel.login')->with('flash.success', __('clinic.sessions.revoked'));
        }

        return back()->with('flash.success', __('clinic.sessions.revoked'));
    }

    public function destroyOthers(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manageSessions', $user);

        $keep = $this->actor($request)->is($user) ? (string) $request->session()->getId() : '';
        $revoked = $this->sessions->revokeOthers($user, $keep);

        if ($revoked > 0) {
            $this->record($request, $user, ['revoked' => $revoked, 'scope' => 'others']);
        }

        return back()->with('flash.success', __('clinic.sessions.revoked_many', ['count' => $revoked]));
    }

    /** @param array<string, mixed> $context */
    private function record(Request $request, User $user, array $context): void
    {
        $this->audit->record(AuditAction::Logout, $user, null, null, $context + [
            'event' => 'session_revoked',
            'actor_user_id' => $this->actor($request)->getKey(),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user('web');

        return $actor;
    }
}
