<?php

declare(strict_types=1);

namespace App\Domain\Queue\Services;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which session instance a public queue URL means (REALTIME.md §5.2). Without `?session=`: the doctor's *current*
 * instance today — `running` if any, else the next `scheduled`, else the last `closed`/`cancelled` (so a late visitor
 * sees "session ended"). With `?session=`: that instance, 404 when unknown or belonging to another doctor.
 */
final class SessionResolver
{
    public function doctor(string $slug): Doctor
    {
        $doctor = Doctor::query()->where('slug', $slug)->first();

        if ($doctor === null || ! $doctor->is_active) {
            throw new NotFoundHttpException('queue.doctor_not_found');
        }

        return $doctor;
    }

    /** @throws NotFoundHttpException */
    public function resolve(string $doctorSlug, ?string $sessionPublicId = null, ?CarbonImmutable $date = null): SessionInstance
    {
        $doctor = $this->doctor($doctorSlug);
        $session = $this->forDoctor($doctor, $sessionPublicId, $date);

        if ($session === null) {
            throw new NotFoundHttpException('queue.no_session_today');
        }

        return $session;
    }

    public function forDoctor(Doctor $doctor, ?string $sessionPublicId = null, ?CarbonImmutable $date = null): ?SessionInstance
    {
        $date ??= Clock::today();

        if ($sessionPublicId !== null && $sessionPublicId !== '') {
            $pinned = SessionInstance::query()->with(['doctor', 'branch'])
                ->where('doctor_id', $doctor->id)->where('public_id', $sessionPublicId)->first();

            if ($pinned === null) {
                throw new NotFoundHttpException('queue.session_not_found');
            }

            return $pinned;
        }

        return self::pick($this->today($doctor, $date));
    }

    /**
     * Today's instances for the doctor, ordered by planned start.
     *
     * @return Collection<int, SessionInstance>
     */
    public function today(Doctor $doctor, ?CarbonImmutable $date = null): Collection
    {
        $date ??= Clock::today();

        /** @var Collection<int, SessionInstance> $rows */
        $rows = SessionInstance::query()->with(['doctor', 'branch'])
            ->where('doctor_id', $doctor->id)
            ->whereDate('session_date', $date->toDateString())
            ->orderBy('planned_start_at')->orderBy('session_code')
            ->get();

        return $rows;
    }

    /**
     * running → next scheduled → paused → the last one of the day (closed/cancelled).
     *
     * @param  Collection<int, SessionInstance>  $sessions
     */
    public static function pick(Collection $sessions): ?SessionInstance
    {
        return $sessions->first(fn (SessionInstance $s) => $s->status === SessionStatus::Running)
            ?? $sessions->first(fn (SessionInstance $s) => $s->status === SessionStatus::Scheduled)
            ?? $sessions->first(fn (SessionInstance $s) => $s->status === SessionStatus::Paused)
            ?? $sessions->last();
    }
}
