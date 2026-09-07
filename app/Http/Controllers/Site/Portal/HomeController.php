<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Portal;

use App\Domain\Patients\Queries\PatientTimelineQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\Patients\FamilyMemberResource;
use App\Http\Resources\Patients\PatientSummaryResource;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** GET /portal — the logged-in household: members, the member being acted for, and their timeline (audited). */
final class HomeController extends Controller
{
    public function __invoke(Request $request, PatientTimelineQuery $timeline): Response
    {
        /** @var Patient $patient */
        $patient = $request->user('patient');
        $household = Patient::query()->active()->household($patient->mobile)->with('primaryRelation')->get();

        $actingId = (string) $request->session()->get(OtpController::SESSION_ACTING_FOR, $patient->public_id);
        $acting = $household->firstWhere('public_id', $actingId) ?? $patient;
        $request->session()->put(OtpController::SESSION_ACTING_FOR, $acting->public_id);

        AuditLog::view($acting, ['screen' => 'site.portal.home']);

        return Inertia::render('Portal/Home', [
            'patient' => (new PatientSummaryResource($patient))->resolve(),
            'family' => FamilyMemberResource::collection($household)->resolve(),
            'acting_for' => (new PatientSummaryResource($acting))->resolve(),
            'timeline' => $timeline->fetch($acting, null, 10)->toArray(),
        ]);
    }
}
