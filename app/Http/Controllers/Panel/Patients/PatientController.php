<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Patients;

use App\Domain\Patients\Actions\CreatePatient;
use App\Domain\Patients\Actions\UpdatePatient;
use App\Domain\Patients\Queries\PatientTimelineQuery;
use App\Domain\Patients\Queries\VitalsTrendQuery;
use App\Domain\Patients\Services\PatientSearch;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Patients\StorePatientRequest;
use App\Http\Requests\Panel\Patients\UpdatePatientRequest;
use App\Http\Resources\Clinic\BranchResource;
use App\Http\Resources\Patients\FamilyMemberResource;
use App\Http\Resources\Patients\PatientResource;
use App\Http\Resources\Patients\PatientSummaryResource;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patients/Index (search + list), Create, Show (audited), Edit. Thin: validate → Data → Action → respond.
 */
final class PatientController extends Controller
{
    public function index(Request $request, PatientSearch $search): Response
    {
        $this->authorize('viewAny', Patient::class);
        /** @var User $user */
        $user = $request->user('web');
        $q = trim((string) $request->query('q', ''));

        return Inertia::render('Patients/Index', [
            'filters' => ['q' => $q],
            'patients' => PatientSummaryResource::collection($search->search($q, 50, $user))->resolve(),
            'search_engine' => $search->usesMeilisearch() ? 'meilisearch' : 'database',
            'can' => ['create' => $user->can('create', Patient::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Patient::class);
        $primaryId = (string) $request->query('primary', '');
        $primary = $primaryId !== '' ? Patient::query()->wherePublicId($primaryId)->first() : null;

        return Inertia::render('Patients/Create', [
            'branches' => BranchResource::collection(Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get())->resolve(),
            'primary' => $primary === null ? null : (new PatientSummaryResource($primary))->resolve(),
        ]);
    }

    public function store(StorePatientRequest $request, CreatePatient $create): RedirectResponse
    {
        $patient = $create->handle($request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.patients.show', ['patient' => $patient->public_id])->with('flash.success', __('patients.flash.created', ['code' => $patient->patient_code]));
    }

    public function show(Request $request, Patient $patient, PatientTimelineQuery $timeline, VitalsTrendQuery $vitals): Response
    {
        $this->authorize('view', $patient);
        AuditLog::view($patient, ['screen' => 'panel.patients.show']);
        /** @var User $user */
        $user = $request->user('web');

        $patient->load([
            'primaryRelation.primary',
            'allergies' => fn ($q) => $q->orderByDesc('is_active')->orderByDesc('id'),
            'conditions' => fn ($q) => $q->orderByRaw("case status when 'resolved' then 1 else 0 end")->orderByDesc('id'),
            'medications' => fn ($q) => $q->orderByDesc('is_active')->orderByDesc('id'),
            'documents' => fn ($q) => $q->orderByDesc('id'),
            'consents' => fn ($q) => $q->orderByDesc('occurred_at')->orderByDesc('id'),
        ]);

        $family = Patient::query()->household($patient->mobile)->whereKeyNot($patient->id)->with('primaryRelation')->get();

        return Inertia::render('Patients/Show', [
            'patient' => (new PatientResource($patient))->resolve(),
            'family' => FamilyMemberResource::collection($family)->resolve(),
            'timeline' => $timeline->fetch($patient, null, 25)->toArray(),
            'timeline_kinds' => $timeline->kinds(),
            'vitals_trend' => $vitals->for($patient, 12),
            'vitals_available' => $vitals->available(),
            'can' => [
                'update' => $user->can('update', $patient),
                'manage_clinical' => $user->can('manageClinical', $patient),
                'upload_document' => $user->can('uploadDocument', $patient),
                'record_consent' => $user->can('recordConsent', $patient),
                'merge' => $user->can('merge', $patient),
                'create' => $user->can('create', Patient::class),
            ],
            'policy_version' => (string) config('patients.policy_version', '2026-01'),
        ]);
    }

    public function edit(Patient $patient): Response
    {
        $this->authorize('update', $patient);
        AuditLog::view($patient, ['screen' => 'panel.patients.edit']);
        $patient->load('primaryRelation.primary');

        return Inertia::render('Patients/Edit', [
            'patient' => (new PatientResource($patient))->resolve(),
            'branches' => BranchResource::collection(Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get())->resolve(),
        ]);
    }

    public function update(UpdatePatientRequest $request, Patient $patient, UpdatePatient $update): RedirectResponse
    {
        $update->handle($patient, $request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.patients.show', ['patient' => $patient->public_id])->with('flash.success', __('patients.flash.updated'));
    }
}
