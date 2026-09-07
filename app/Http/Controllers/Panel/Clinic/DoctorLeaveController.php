<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\CancelDoctorLeave;
use App\Domain\Clinic\Actions\CreateDoctorLeave;
use App\Domain\Clinic\Enums\LeaveType;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\StoreDoctorLeaveRequest;
use App\Http\Resources\Clinic\BranchResource;
use App\Http\Resources\Clinic\DoctorLeaveResource;
use App\Http\Resources\Clinic\DoctorResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorLeave;
use App\Models\Tenant\User;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BRIEF §5.A — doctor leave, including the emergency cancellation path.
 *
 * Recording an **emergency** leave is the whole chain in one POST: `CreateDoctorLeave` raises `DoctorLeaveCreated`
 * after commit → Scheduling's `CancelSessionsForLeave` cancels every open `session_instance` in range through
 * `CancelSession` (which cancels each live serial with `session_cancelled`) → `SessionCancelled` carries
 * `notify_patients` → Notifications' `FanOutSessionCancellation` queues one `doctor_cancelled` message per booked
 * patient, deduped on `doctor_cancelled:{serial_id}`. Nothing in this controller re-implements any of that.
 */
final class DoctorLeaveController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DoctorLeave::class);
        /** @var User $user */
        $user = $request->user('web');
        $doctorId = (int) $request->query('doctor', 0);
        $scope = (string) $request->query('scope', 'upcoming');

        $leaves = DoctorLeave::query()
            ->with(['doctor', 'branch'])
            ->when($doctorId > 0, fn (Builder $q) => $q->where('doctor_id', $doctorId))
            ->when($scope === 'upcoming', fn (Builder $q) => $q->whereDate('ends_on', '>=', Clock::today()->toDateString()))
            ->when($scope === 'past', fn (Builder $q) => $q->whereDate('ends_on', '<', Clock::today()->toDateString()))
            ->orderByDesc('starts_on')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Clinic/Leaves/Index', [
            'leaves' => DoctorLeaveResource::collection($leaves)->response()->getData(true),
            'doctors' => DoctorResource::collection(Doctor::query()->orderBy('name')->get())->resolve(),
            'branch_options' => BranchResource::collection(Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get())->resolve(),
            'filters' => ['doctor' => $doctorId ?: null, 'scope' => $scope],
            'types' => array_map(fn (LeaveType $t) => $t->value, LeaveType::cases()),
            'today' => Clock::today()->toDateString(),
            'can' => ['manage' => $user->can('create', [DoctorLeave::class, null])],
        ]);
    }

    public function store(StoreDoctorLeaveRequest $request, CreateDoctorLeave $create): RedirectResponse
    {
        $leave = $create->handle($request->toData(), Actor::fromRequest($request));

        return back()->with('flash.success', __(
            $leave->type === LeaveType::Emergency ? 'clinic.leaves.flash.emergency_recorded' : 'clinic.leaves.flash.created',
            ['from' => $leave->starts_on->toDateString(), 'to' => $leave->ends_on->toDateString()],
        ));
    }

    /** Withdrawing a leave; the sessions it cancelled are not resurrected (SERIAL_ENGINE §2.1). */
    public function destroy(Request $request, DoctorLeave $leave, CancelDoctorLeave $cancel): RedirectResponse
    {
        $this->authorize('delete', $leave);
        $cancel->handle($leave, Actor::fromRequest($request));

        return back()->with('flash.success', __('clinic.leaves.flash.cancelled'));
    }
}
