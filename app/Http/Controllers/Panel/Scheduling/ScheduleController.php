<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Scheduling;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Scheduling\Actions\CreateDoctorSchedule;
use App\Domain\Scheduling\Actions\DeleteDoctorSchedule;
use App\Domain\Scheduling\Actions\UpdateDoctorSchedule;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Scheduling\StoreDoctorScheduleRequest;
use App\Http\Requests\Panel\Scheduling\UpdateDoctorScheduleRequest;
use App\Http\Resources\Scheduling\DoctorScheduleResource;
use App\Http\Resources\Scheduling\ScheduleOverrideResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\ScheduleOverride;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Weekly template editor per doctor/branch (panel page Scheduling/Index) + template CRUD as Inertia form posts. */
final class ScheduleController extends Controller
{
    public function index(Request $request, ActiveBranch $activeBranch): Response
    {
        $this->authorize('viewAny', DoctorSchedule::class);

        $doctors = Doctor::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'public_id', 'name', 'name_bn', 'slug', 'user_id']);
        $branches = Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get(['id', 'public_id', 'name', 'code']);

        $user = $request->user('web');
        $ownDoctorId = $user?->doctor()->value('id');
        $firstDoctor = $doctors->first();
        $firstBranch = $branches->first();
        $doctorId = (int) ($request->integer('doctor') ?: ($ownDoctorId ?? ($firstDoctor === null ? 0 : $firstDoctor->id)));
        $branchId = (int) ($request->integer('branch') ?: ($activeBranch->id() ?? ($firstBranch === null ? 0 : $firstBranch->id)));

        $schedules = DoctorSchedule::query()->where('doctor_id', $doctorId)->where('branch_id', $branchId)->where('is_active', true)->orderBy('weekday')->orderBy('start_time')->get();
        $overrides = ScheduleOverride::query()->where('doctor_id', $doctorId)->where('branch_id', $branchId)->whereDate('override_date', '>=', Clock::today()->subDays(7)->toDateString())->orderBy('override_date')->orderBy('id')->get();

        return Inertia::render('Scheduling/Index', [
            'doctors' => $doctors->map(fn (Doctor $d) => ['id' => $d->id, 'public_id' => $d->public_id, 'name' => $d->name, 'name_bn' => $d->name_bn, 'slug' => $d->slug])->values(),
            'branches' => $branches->map(fn (Branch $b) => ['id' => $b->id, 'public_id' => $b->public_id, 'name' => $b->name, 'code' => $b->code])->values(),
            'selected' => ['doctor_id' => $doctorId, 'branch_id' => $branchId],
            'schedules' => DoctorScheduleResource::collection($schedules)->resolve(),
            'overrides' => ScheduleOverrideResource::collection($overrides)->resolve(),
            'today' => Clock::today()->toDateString(),
            'can_manage' => $user?->can('create', [DoctorSchedule::class, $doctorId]) ?? false,
        ]);
    }

    public function store(StoreDoctorScheduleRequest $request, CreateDoctorSchedule $action): RedirectResponse
    {
        $schedule = $action->handle($request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.scheduling.index', ['doctor' => $schedule->doctor_id, 'branch' => $schedule->branch_id])->with('flash.success', __('scheduling.flash.schedule_saved'));
    }

    public function update(UpdateDoctorScheduleRequest $request, DoctorSchedule $schedule, UpdateDoctorSchedule $action): RedirectResponse
    {
        $schedule = $action->handle($schedule, $request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.scheduling.index', ['doctor' => $schedule->doctor_id, 'branch' => $schedule->branch_id])->with('flash.success', __('scheduling.flash.schedule_saved'));
    }

    public function destroy(Request $request, DoctorSchedule $schedule, DeleteDoctorSchedule $action): RedirectResponse
    {
        $this->authorize('delete', $schedule);
        $action->handle($schedule, Actor::fromRequest($request));

        return redirect()->route('panel.scheduling.index', ['doctor' => $schedule->doctor_id, 'branch' => $schedule->branch_id])->with('flash.success', __('scheduling.flash.schedule_removed'));
    }
}
