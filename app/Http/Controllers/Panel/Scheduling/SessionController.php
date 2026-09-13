<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Scheduling;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Clinic\Services\DoctorScope;
use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Serials\Services\CapacityService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Scheduling\SessionInstanceResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Session-day view (panel page Scheduling/SessionDay): every session of a doctor/branch/date with its serials.
 *
 * `?doctor=` is caller-chosen and the page loads that doctor's serials, so this is a second door onto a chamber's
 * queue and it answers to the caller's DoctorScope: the picker offers only the doctors they may see, and a doctor
 * id they may not see is a 403 rather than a quietly substituted one — a scope that silently redirects teaches the
 * caller nothing and hides the boundary from the log.
 */
final class SessionController extends Controller
{
    public function index(Request $request, ActiveBranch $activeBranch, SessionMaterialiser $materialiser, CapacityService $capacity, DoctorScope $scope): Response
    {
        $this->authorize('viewAny', DoctorSchedule::class);

        $user = $request->user('web');
        $allowed = $user === null ? [] : $scope->doctorIds($user);
        $doctors = Doctor::query()->active()->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?? []))
            ->orderBy('sort_order')->orderBy('name')->get(['id', 'public_id', 'name', 'name_bn', 'slug']);
        $branches = Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get(['id', 'public_id', 'name', 'code']);

        $firstDoctor = $doctors->first();
        $firstBranch = $branches->first();
        $doctorId = (int) ($request->integer('doctor') ?: ($user?->doctor()->value('id') ?? ($firstDoctor === null ? 0 : $firstDoctor->id)));
        $branchId = (int) ($request->integer('branch') ?: ($activeBranch->id() ?? ($firstBranch === null ? 0 : $firstBranch->id)));
        $dateInput = (string) $request->query('date', '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput) === 1 ? CarbonImmutable::parse($dateInput, Clock::timezone())->startOfDay() : Clock::today();

        abort_unless($doctorId === 0 || ($user !== null && $scope->allows($user, $doctorId)), 403);

        $sessions = collect();

        if ($doctorId > 0 && $branchId > 0) {
            $materialiser->ensureDay($branchId, $doctorId, $date);
            $sessions = SessionInstance::query()->forDay($branchId, $doctorId, $date)
                ->with(['doctor', 'branch', 'serials' => fn ($q) => $q->orderBy('position')->orderBy('number')])
                ->orderBy('session_code')->get();
            $remaining = $capacity->remainingFor($sessions->pluck('id')->all());

            foreach ($sessions as $s) {
                $s->setAttribute('remaining', $remaining[$s->id] ?? CapacityService::empty());
            }
        }

        return Inertia::render('Scheduling/SessionDay', [
            'doctors' => $doctors->map(fn (Doctor $d) => ['id' => $d->id, 'public_id' => $d->public_id, 'name' => $d->name, 'name_bn' => $d->name_bn, 'slug' => $d->slug])->values(),
            'branches' => $branches->map(fn (Branch $b) => ['id' => $b->id, 'public_id' => $b->public_id, 'name' => $b->name, 'code' => $b->code])->values(),
            'selected' => ['doctor_id' => $doctorId, 'branch_id' => $branchId, 'date' => $date->toDateString()],
            'sessions' => SessionInstanceResource::collection($sessions)->resolve(),
            'permissions' => [
                'reorder' => $user?->can('serials.reorder') ?? false,
                'call_next' => $user?->can('queue.call-next') ?? false,
                'issue' => $user?->can('serials.issue.counter') ?? false,
            ],
        ]);
    }
}
