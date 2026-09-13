<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Queue;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Clinic\Services\DoctorScope;
use App\Domain\Queue\Services\BoardStateBuilder;
use App\Domain\Queue\TenantChannel;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `panel.queue.today` (GET /panel/queue/today) — today's queues at the active branch in one view (now serving,
 * waiting, delay), each row a link into the doctor screen. Live over the reception channel's `board.updated`;
 * polled in degraded mode through `panel.queue.today.data`. `panel.queue.index` (GET /panel/queue) lands here for
 * everyone who is not a doctor.
 *
 * It had no authorisation call at all, which made it a second, unfiltered view of every chamber at the branch for
 * anyone who could reach the panel. It now asks the same door the desk board asks (AppointmentPolicy::viewAny) and
 * narrows the same way: the caller's DoctorScope filters the board AND the doctor map the rows link through.
 */
final class TodayController extends Controller
{
    public function index(Request $request, ActiveBranch $activeBranch, BoardStateBuilder $board, DoctorScope $scope): Response
    {
        $this->authorize('viewAny', Appointment::class);
        $branch = self::branch($activeBranch);
        $tenant = Tenancy::current();
        $user = $request->user('web');
        $doctorIds = $user === null ? [] : $scope->doctorIds($user);

        return Inertia::render('Queue/Today', [
            'tenant_public_id' => $tenant?->public_id,
            'channel' => $tenant === null ? null : TenantChannel::receptionName($tenant->public_id, $branch->public_id),
            'branch' => ['public_id' => $branch->public_id, 'slug' => $branch->slug, 'name' => $branch->name],
            'date' => Clock::today()->toDateString(),
            'board' => $board->build($branch, null, $doctorIds),
            'doctors' => self::doctors($branch, $doctorIds),
            'can' => ['call_next' => $user instanceof User && $user->can(Permission::QueueCallNext->value)],
        ]);
    }

    public function data(Request $request, ActiveBranch $activeBranch, BoardStateBuilder $board, DoctorScope $scope): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);
        $branch = self::branch($activeBranch);
        $user = $request->user('web');
        $doctorIds = $user === null ? [] : $scope->doctorIds($user);

        return response()->json([
            'board' => $board->build($branch, null, $doctorIds),
            'doctors' => self::doctors($branch, $doctorIds),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * doctor public id → slug/name, so the board rows can link into the doctor screen. Its own query, so it needs
     * its own copy of the scope — a map of every doctor at the branch would name the colleagues the board omits.
     *
     * @param  list<int>|null  $doctorIds  DoctorScope: null = unrestricted, a list = only these doctors, [] = none
     * @return array<string, array<string, mixed>>
     */
    private static function doctors(Branch $branch, ?array $doctorIds = null): array
    {
        $out = [];

        foreach (SessionInstance::query()->with('doctor')->where('branch_id', $branch->id)
            ->when($doctorIds !== null, fn ($q) => $q->whereIn('doctor_id', $doctorIds ?? []))
            ->whereDate('session_date', Clock::today()->toDateString())->get() as $session) {
            $out[$session->doctor->public_id] = [
                'slug' => $session->doctor->slug,
                'name' => $session->doctor->name,
                'name_bn' => $session->doctor->name_bn,
                'room' => $session->doctor->room_label,
            ];
        }

        return $out;
    }

    private static function branch(ActiveBranch $activeBranch): Branch
    {
        return $activeBranch->current() ?? Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->firstOrFail();
    }
}
