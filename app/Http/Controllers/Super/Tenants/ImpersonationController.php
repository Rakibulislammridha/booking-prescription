<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\SaaS\Actions\Impersonation\IssueImpersonationToken;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mint a handoff and send the browser to the clinic's own host (ARCHITECTURE §6.5).
 *
 * A cancelled tenant cannot be entered: its host answers 404 on every route, so a token for it would be a link to
 * nowhere. A suspended one CAN be — support usually needs to look at a clinic precisely because it is suspended —
 * and `EnsureTenantIsActive` will show the impersonating admin the same 402 the customer sees, which is the
 * honest thing for it to do.
 */
final class ImpersonationController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, IssueImpersonationToken $issue): Response
    {
        if ($tenant->status === TenantStatus::Cancelled) {
            throw ValidationException::withMessages(['tenant' => __('saas.impersonation.cancelled')]);
        }

        $validated = $request->validate(['user_id' => ['nullable', 'integer', 'min:1']]);
        $admin = $request->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        $ticket = $issue->handle(
            $admin,
            $tenant,
            isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            $request->ip(),
            $request->getScheme(),
        );

        $url = $this->withCurrentPort($ticket->url, $request);

        // The clinic lives on another host, so this is a cross-origin hop. An Inertia XHR cannot follow a plain
        // 302 across origins — `Inertia::location()` is the 409 + `X-Inertia-Location` that makes the client do a
        // full page visit — while a native form post takes the ordinary redirect.
        return $request->header('X-Inertia') !== null ? Inertia::location($url) : redirect()->away($url);
    }

    /** Dev and CI serve the platform on :8000 / :8090; a handoff that dropped the port would 404. */
    private function withCurrentPort(string $url, Request $request): string
    {
        $port = $request->getPort();
        $scheme = $request->getScheme();

        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return $url;
        }

        return (string) preg_replace('#^(https?://[^/:]+)#', '$1:'.$port, $url, 1);
    }
}
