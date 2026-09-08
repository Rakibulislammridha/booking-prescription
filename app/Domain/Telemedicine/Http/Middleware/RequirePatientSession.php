<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `auth:patient` with the right destination. The global `redirectGuestsTo` in bootstrap/app.php (foundation-owned)
 * routes anything that is not `site.portal.*` to the STAFF login — correct for the panel, and quietly wrong here:
 * a patient whose session lapsed between the SMS and the consultation would be shown a staff password form.
 *
 * So this sends them to the portal's mobile-OTP login instead, remembering where they were going. The signed link
 * itself normally means they never see this at all.
 */
final class RequirePatientSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('patient')->check()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('telemedicine.join.sign_in_required'), 'code' => 'auth.unauthenticated'], 401);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('site.portal.login');
    }
}
