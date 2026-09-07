<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Patients\Actions\RemoveCondition;
use App\Domain\Patients\Actions\SaveCondition;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Patients\SaveConditionRequest;
use App\Http\Resources\Patients\ConditionResource;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientCondition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** GET/POST/PATCH/DELETE /panel/patients/{patient}/conditions[/{condition}]. DELETE marks the condition resolved. */
final class PatientConditionController extends Controller
{
    public function index(Patient $patient): AnonymousResourceCollection
    {
        $this->authorize('view', $patient);
        AuditLog::view($patient, ['section' => 'conditions']);

        return ConditionResource::collection($patient->conditions()->orderByRaw("case status when 'resolved' then 1 else 0 end")->orderByDesc('id')->get());
    }

    public function store(SaveConditionRequest $request, Patient $patient, SaveCondition $save): JsonResponse|RedirectResponse
    {
        $condition = $save->handle($patient, $request->toData(), Actor::fromRequest($request));

        return $request->wantsJson()
            ? (new ConditionResource($condition))->response()->setStatusCode(201)
            : back()->with('flash.success', __('patients.flash.condition_saved'));
    }

    public function update(SaveConditionRequest $request, Patient $patient, PatientCondition $condition, SaveCondition $save): JsonResponse|RedirectResponse
    {
        $condition = $save->handle($patient, $request->toData(), Actor::fromRequest($request), $condition);

        return $request->wantsJson()
            ? (new ConditionResource($condition))->response()
            : back()->with('flash.success', __('patients.flash.condition_saved'));
    }

    public function destroy(Request $request, Patient $patient, PatientCondition $condition, RemoveCondition $remove): JsonResponse|RedirectResponse
    {
        $this->authorize('manageClinical', $patient);
        $condition = $remove->handle($condition, Actor::fromRequest($request));

        return $request->wantsJson()
            ? (new ConditionResource($condition))->response()
            : back()->with('flash.success', __('patients.flash.condition_resolved'));
    }
}
