<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Queue;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Queue\Services\SessionResolver;
use App\Domain\Queue\Services\SessionRosterBuilder;
use App\Domain\Queue\TenantChannel;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The doctor's session page — the sidebar's "Today's session" (`panel.queue.doctor`, REALTIME.md §11): the session
 * header, now serving, next up, and the whole roster in serial-number order with a per-row action (call this
 * patient / start / prescribe / view / print). Mutations are the Serials module's endpoints; Prescribe opens the
 * serial's visit through the Prescription module. Live over the private doctor channel plus the session's public
 * `queue.state`; the roster is re-read through `panel.queue.doctor.roster` whenever that state's version moves
 * (a check-in at the desk, vitals recorded, a completion) — event-driven, never a poller of its own.
 */
final class DoctorScreenController extends Controller
{
    /** `?notice=` values the page turns into a toast (the post-issue "Call next patient" landing here with no one waiting). */
    private const NOTICES = ['no_one_waiting'];

    public function show(Request $request, SessionResolver $resolver, QueueStateRepository $repository, SessionRosterBuilder $roster): Response
    {
        $user = $this->user($request);
        $doctor = $this->doctor($request, $user);
        $sessions = $resolver->today($doctor);
        $session = $resolver->forDoctor($doctor, (string) $request->query('session', '') ?: null);
        $tenant = Tenancy::current();
        $notice = (string) $request->query('notice', '');

        return Inertia::render('Queue/Doctor', [
            'tenant_public_id' => $tenant?->public_id,
            'doctor_channel' => $tenant === null ? null : TenantChannel::doctorName($tenant->public_id, $doctor->public_id),
            'doctor' => ['public_id' => $doctor->public_id, 'slug' => $doctor->slug, 'name' => $doctor->name, 'name_bn' => $doctor->name_bn, 'room' => $doctor->room_label],
            'date' => Clock::today()->toDateString(),
            'session_id' => $session?->public_id,
            'state' => $session === null ? null : $repository->state($session),
            'sessions' => $sessions->map(fn (SessionInstance $s) => [
                'public_id' => $s->public_id, 'code' => $s->session_code, 'status' => $s->status->value,
                'planned_start_at' => $s->planned_start_at->toIso8601ZuluString(), 'planned_end_at' => $s->planned_end_at->toIso8601ZuluString(),
                'delay_minutes' => $s->delay_minutes,
            ])->values()->all(),
            'roster' => $session === null ? [] : $roster->build($session),
            'notice' => in_array($notice, self::NOTICES, true) ? $notice : null,
            'can' => [
                // SessionInstancePolicy::callNext — the doctor on their own session, or an operator with queue.call-next.
                'call_next' => $session !== null ? $user->can('callNext', $session) : ($user->can(Permission::QueueCallNext->value) || $doctor->user_id === $user->id),
                'delay' => $user->can(Permission::QueueDelayBroadcast->value),
                // The writer for the serial in the chamber (VisitPolicy::write's rule): a prescriber who owns this
                // screen, or one who may write anyone's. An operator working the screen for a doctor gets no Prescribe.
                'prescribe' => $user->can(Permission::PrescriptionsWrite->value)
                    && ($doctor->user_id === $user->id || $user->can(Permission::PrescriptionsViewAny->value)),
            ],
        ]);
    }

    /** GET /panel/queue/doctor/roster?doctor=&session= — the roster alone, for the page's refresh on a queue-state change. */
    public function roster(Request $request, SessionResolver $resolver, SessionRosterBuilder $roster): JsonResponse
    {
        $user = $this->user($request);
        $doctor = $this->doctor($request, $user);
        $session = $resolver->forDoctor($doctor, (string) $request->query('session', '') ?: null);

        return response()->json([
            'session_id' => $session?->public_id,
            'version' => $session?->version,
            'roster' => $session === null ? (object) [] : (object) $roster->build($session),
        ])->header('Cache-Control', 'no-store');
    }

    private function user(Request $request): User
    {
        $user = $request->user('web');

        if (! $user instanceof User) {
            throw new AccessDeniedHttpException('queue.doctor_screen_forbidden');
        }

        return $user;
    }

    /** Reception / admin with queue.call-next; a user who IS a doctor only ever sees their own screen (ChannelGuards). */
    private static function isOperator(User $user): bool
    {
        return $user->can(Permission::QueueCallNext->value) && Doctor::query()->where('user_id', $user->id)->doesntExist();
    }

    private function doctor(Request $request, User $user): Doctor
    {
        $slug = (string) $request->query('doctor', '');

        if ($slug !== '') {
            $doctor = Doctor::query()->where('slug', $slug)->first();

            if ($doctor === null) {
                throw new NotFoundHttpException('queue.doctor_not_found');
            }

            if ($doctor->user_id !== $user->id && ! self::isOperator($user)) {
                throw new AccessDeniedHttpException('queue.doctor_screen_forbidden');
            }

            return $doctor;
        }

        $own = Doctor::query()->where('user_id', $user->id)->first();

        if ($own !== null) {
            return $own;
        }

        if (! self::isOperator($user)) {
            throw new AccessDeniedHttpException('queue.doctor_screen_forbidden');
        }

        $first = Doctor::query()->active()->orderBy('sort_order')->orderBy('name')->first();

        if ($first === null) {
            throw new NotFoundHttpException('queue.doctor_not_found');
        }

        return $first;
    }
}
