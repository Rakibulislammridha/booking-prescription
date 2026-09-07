<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Queue;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Queue\Services\SessionResolver;
use App\Domain\Queue\TenantChannel;
use App\Domain\Serials\Enums\SerialStatus;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The doctor screen (`panel.queue.doctor`, REALTIME.md §11): now serving, next up, call-next / start / complete /
 * skip / return through the Serials module's endpoints, live over the private doctor channel plus the session's
 * public `queue.state`, one-tap delay broadcast and the running ETA.
 */
final class DoctorScreenController extends Controller
{
    public function __invoke(Request $request, SessionResolver $resolver, QueueStateRepository $repository): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User) {
            throw new AccessDeniedHttpException('queue.doctor_screen_forbidden');
        }

        $doctor = $this->doctor($request, $user);
        $sessions = $resolver->today($doctor);
        $session = $resolver->forDoctor($doctor, (string) $request->query('session', '') ?: null);
        $tenant = Tenancy::current();

        return Inertia::render('Queue/Doctor', [
            'tenant_public_id' => $tenant?->public_id,
            'doctor_channel' => $tenant === null ? null : TenantChannel::doctorName($tenant->public_id, $doctor->public_id),
            'doctor' => ['public_id' => $doctor->public_id, 'slug' => $doctor->slug, 'name' => $doctor->name, 'name_bn' => $doctor->name_bn, 'room' => $doctor->room_label],
            'date' => Clock::today()->toDateString(),
            'session_id' => $session?->public_id,
            'state' => $session === null ? null : $repository->state($session),
            'sessions' => $sessions->map(fn (SessionInstance $s) => [
                'public_id' => $s->public_id, 'code' => $s->session_code, 'status' => $s->status->value,
                'planned_start_at' => $s->planned_start_at->toIso8601ZuluString(), 'delay_minutes' => $s->delay_minutes,
            ])->values()->all(),
            'patients' => $session === null ? [] : self::patients($session),
            'can' => [
                'call_next' => $user->can(Permission::QueueCallNext->value) || $doctor->user_id === $user->id,
                'delay' => $user->can(Permission::QueueDelayBroadcast->value),
            ],
        ]);
    }

    /**
     * Name / age / patient code per active serial — a staff-only screen; the public payload never carries these.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function patients(SessionInstance $session): array
    {
        $serials = Serial::query()
            ->where('session_instance_id', $session->id)
            ->whereIn('status', SerialStatus::activeValues())
            ->whereNotNull('patient_id')
            ->get(['id', 'public_id', 'patient_id']);

        $patients = Patient::query()->whereIn('id', $serials->pluck('patient_id')->filter()->all())->get()->keyBy('id');
        $out = [];

        foreach ($serials as $serial) {
            $patient = $patients->get($serial->patient_id);

            if ($patient instanceof Patient) {
                $out[$serial->public_id] = [
                    'name' => $patient->name,
                    'age_text' => $patient->age_text,
                    'sex' => $patient->gender?->value,
                    'patient_code' => $patient->patient_code,
                ];
            }
        }

        return $out;
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
