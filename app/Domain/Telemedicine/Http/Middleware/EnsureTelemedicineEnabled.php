<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Http\Middleware;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Services\Entitlements;
use App\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SITE half of the plan gate. The panel and API routes carry the shared `plan:telemedicine` alias, whose
 * refusal redirects a staff user to the subscription page with an upgrade message — exactly right for the person
 * who can act on it, and exactly wrong for a patient who followed a link from an SMS.
 *
 * So the patient-facing surface answers with a page of its own: 402, a plain explanation that the clinic does
 * not offer video consultations, and the clinic's phone number. Never a 404 — the appointment is real, and a
 * patient told "not found" will simply not turn up (BRIEF §5.K gating, §5.M add-on tier).
 */
final class EnsureTelemedicineEnabled
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenancy::current();

        if ($tenant !== null && $this->entitlements->for($tenant)->enabled(PlanFeatureKey::Telemedicine)) {
            return $next($request);
        }

        $message = __('telemedicine.unavailable.body');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'code' => 'saas.feature.telemedicine'], 402);
        }

        return Inertia::render('Telemedicine/Unavailable', [
            'message' => $message,
        ])->toResponse($request)->setStatusCode(402);
    }
}
