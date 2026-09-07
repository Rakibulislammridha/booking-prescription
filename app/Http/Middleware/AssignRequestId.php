<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\AuditRecorder;
use App\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global: one ULID per request, echoed as X-Request-Id, stored on AuditRecorder, and set as log context
 * (request_id, tenant_id, actor) — CONVENTIONS §11. No PII in the context.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);
        app(AuditRecorder::class)->setRequestId($requestId);

        Log::withContext([
            'request_id' => $requestId,
            'tenant_id' => Tenancy::id(),
            'actor' => $this->actor($request),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    private function actor(Request $request): ?string
    {
        if ($request->bearerToken() !== null) {
            return 'token';
        }

        if (! $request->hasSession()) {
            return null;
        }

        // The web guard only exists inside a tenant; centrally a stray login_web_* marker is not an actor.
        $guards = Tenancy::check() ? ['web' => 'user'] : ['super' => 'super_admin'];

        foreach ($guards as $guard => $label) {
            if (($id = Auth::guard($guard)->id()) !== null) {
                return "{$label}:{$id}";
            }
        }

        return null;
    }
}
