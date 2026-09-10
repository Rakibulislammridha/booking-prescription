<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Profile;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Http\Controllers\Controller;
use App\Models\Central\SuperAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The operator's own devices (`SuperSessionIndex`): end one, or every other one. Sessions are addressed by an
 * opaque `ref`, never by the session id, exactly as the clinic's staff screen does.
 */
final class SessionController extends Controller
{
    public function __construct(
        private readonly SuperSessionIndex $sessions,
        private readonly CentralAudit $audit,
    ) {}

    public function destroy(Request $request, string $ref): RedirectResponse
    {
        $admin = $this->admin($request);
        $session = $this->sessions->findByRef($admin, $ref);

        if ($session === null || ! $this->sessions->revoke($admin, $session->id)) {
            return back()->with('flash.error', __('super.profile.sessions.already_gone'));
        }

        $this->audit->record(CentralAuditAction::SessionRevoke, null, $admin, null, ['revoked' => 1, 'device' => $session->device(), 'ip' => $session->ip, 'session_ref' => $ref], $admin->id);

        // Ending the session this request runs on has to end it here too, or the store would write it back.
        if ($session->isCurrent) {
            Auth::guard('super')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('super.login')->with('flash.success', __('super.profile.sessions.revoked'));
        }

        return back()->with('flash.success', __('super.profile.sessions.revoked'));
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $revoked = $this->sessions->revokeOthers($admin, (string) $request->session()->getId());

        if ($revoked > 0) {
            $this->audit->record(CentralAuditAction::SessionRevoke, null, $admin, null, ['revoked' => $revoked, 'scope' => 'others'], $admin->id);
        }

        return back()->with('flash.success', __('super.profile.sessions.revoked_many', ['count' => (string) $revoked]));
    }

    private function admin(Request $request): SuperAdmin
    {
        $admin = $request->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
