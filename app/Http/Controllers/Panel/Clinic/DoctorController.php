<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\CreateDoctor;
use App\Domain\Clinic\Actions\CreateStaffUser;
use App\Domain\Clinic\Actions\UpdateDoctor;
use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Enums\Gender;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\StoreDoctorRequest;
use App\Http\Requests\Panel\Clinic\UpdateDoctorRequest;
use App\Http\Resources\Clinic\BranchResource;
use App\Http\Resources\Clinic\DepartmentResource;
use App\Http\Resources\Clinic\DoctorLeaveResource;
use App\Http\Resources\Clinic\DoctorResource;
use App\Http\Resources\Clinic\SpecialtyResource;
use App\Http\Resources\Clinic\UserResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorLeave;
use App\Models\Tenant\Specialty;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BRIEF §5.A — doctors: list, create (linked to an existing staff login or creating one), and the profile editor
 * that carries degrees, BMDC number, specialties, fees and the **free follow-up window** the billing engine honours.
 * Chamber timings are NOT here: the weekly schedule editor is BRIEF §5.B (`panel.scheduling.index`), and the edit
 * page links straight into it for this doctor.
 */
final class DoctorController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Doctor::class);
        /** @var User $user */
        $user = $request->user('web');
        $q = trim((string) $request->query('q', ''));
        $departmentId = (int) $request->query('department', 0);
        $specialtyId = (int) $request->query('specialty', 0);
        $status = (string) $request->query('status', '');

        $doctors = Doctor::query()
            ->with(['profile', 'department', 'specialties', 'user'])
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('name', 'ilike', "%{$q}%")->orWhere('name_bn', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%")->orWhere('mobile', 'ilike', "%{$q}%")))
            ->when($departmentId > 0, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when($specialtyId > 0, fn (Builder $query) => $query->whereHas('specialties', fn (Builder $s) => $s->where('specialties.id', $specialtyId)))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('sort_order')->orderBy('name')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Clinic/Doctors/Index', [
            'doctors' => DoctorResource::collection($doctors)->response()->getData(true),
            'filters' => ['q' => $q, 'department' => $departmentId ?: null, 'specialty' => $specialtyId ?: null, 'status' => $status],
            'departments' => DepartmentResource::collection(Department::query()->orderBy('name')->get())->resolve(),
            'specialties' => SpecialtyResource::collection(Specialty::query()->orderBy('name')->get())->resolve(),
            'can' => [
                'manage' => $user->can('create', Doctor::class),
                'schedule' => $user->can('scheduling.schedules.manage'),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Doctor::class);

        return Inertia::render('Clinic/Doctors/Create', $this->formProps() + [
            'branch_options' => BranchResource::collection(Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get())->resolve(),
        ]);
    }

    /**
     * Two ways in, one screen: `user_id` links an existing staff login, or `new_user` creates one (CreateStaffUser
     * first, then CreateDoctor, which assigns the doctor role and seeds the pad defaults).
     */
    public function store(StoreDoctorRequest $request, CreateDoctor $create, CreateStaffUser $createUser): RedirectResponse
    {
        $actor = Actor::fromRequest($request);
        $data = $request->toData();

        if ($request->input('user_id') === null && is_array($request->input('new_user'))) {
            /** @var array<string, mixed> $new */
            $new = $request->validated('new_user');
            $user = $createUser->handle(new StaffUserData(
                name: (string) $new['name'],
                email: strtolower((string) $new['email']),
                role: Role::Doctor,
                password: null,
                mobile: isset($new['mobile']) ? (string) $new['mobile'] : null,
                defaultBranchId: isset($new['default_branch_id']) ? (int) $new['default_branch_id'] : null,
                locale: (string) ($new['locale'] ?? 'bn'),
                mustChangePassword: true,
            ), $actor);

            $data = $data->withUserId($user->id);
        }

        $doctor = $create->handle($data, $actor);

        return redirect()->route('panel.clinic.doctors.edit', ['doctor' => $doctor->public_id])
            ->with('flash.success', __('clinic.doctors.flash.created', ['name' => $doctor->name]));
    }

    public function edit(Request $request, Doctor $doctor): Response
    {
        $this->authorize('update', $doctor);
        /** @var User $user */
        $user = $request->user('web');
        $doctor->load(['profile', 'department', 'specialties', 'doctorSpecialties', 'user', 'padSetting']);

        return Inertia::render('Clinic/Doctors/Edit', $this->formProps($doctor) + [
            'doctor' => (new DoctorResource($doctor))->resolve(),
            'leaves' => DoctorLeaveResource::collection(
                $doctor->leaves()->with('branch')->orderByDesc('starts_on')->limit(20)->get()
            )->resolve(),
            'branch_options' => BranchResource::collection(Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get())->resolve(),
            'can' => [
                'design_pad' => $user->can('designPad', $doctor),
                'schedule' => $user->can('scheduling.schedules.manage'),
                'manage_leave' => $user->can('create', [DoctorLeave::class, $doctor->id]),
                // Same ability the compounders screen authorises with. It reads as redundant here — `update` is
                // already `clinic.doctors.manage`, which grants it — but the two rules are allowed to diverge, and
                // a link that 403s is worse than a link that is absent.
                'manage_compounders' => $user->can('manageCompounders', $doctor),
            ],
        ]);
    }

    public function update(UpdateDoctorRequest $request, Doctor $doctor, UpdateDoctor $update): RedirectResponse
    {
        $update->handle($doctor, $request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.doctors.edit', ['doctor' => $doctor->public_id])
            ->with('flash.success', __('clinic.doctors.flash.updated', ['name' => $doctor->name]));
    }

    /**
     * Shared option lists. `users` are the staff logins not already tied to another doctor row — the
     * `doctors.user_id` unique index is what UserAlreadyLinkedToDoctor protects.
     *
     * @return array<string, mixed>
     */
    private function formProps(?Doctor $doctor = null): array
    {
        $taken = Doctor::query()->whereNotNull('user_id')->when($doctor !== null, fn (Builder $q) => $q->whereKeyNot($doctor?->id))->pluck('user_id');

        return [
            'departments' => DepartmentResource::collection(Department::query()->where('is_active', true)->orderBy('name')->get())->resolve(),
            'specialties' => SpecialtyResource::collection(Specialty::query()->where('is_active', true)->orderBy('name')->get())->resolve(),
            'users' => UserResource::collection(
                User::query()->where('is_active', true)->whereKeyNot($taken->all())->orderBy('name')->get()
            )->resolve(),
            'genders' => array_map(fn (Gender $g) => $g->value, Gender::cases()),
        ];
    }
}
