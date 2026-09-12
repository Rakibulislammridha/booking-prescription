<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\DoctorArrived;
use App\Domain\Serials\Events\SerialCalled;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * SERIAL_ENGINE §14: picks the checked_in serial with the lowest position (FOR UPDATE SKIP LOCKED), calls it
 * (called_at, now_serving_serial_id, actual_start_at, scheduled → running), then runs the auto no-show sweep (§8).
 * When nothing is checked in returns ['called' => null, 'waiting_booked' => n] — no exception.
 * Lock order: session row first (serialises call-next per session), then the candidate serial.
 */
final class CallNext
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly ApplyAutoNoShow $autoNoShow,
    ) {}

    /**
     * The selection rule of handle(), applied to serials already in memory: the checked_in row with the lowest
     * position, ties by number. The reception board lists its rows by NUMBER (the order the waiting room reads)
     * and marks the one this action would call, so the desk is never surprised by a priority insert; it uses this
     * rather than restating the rule, and it runs no query for it. `null` when nothing is checked in.
     *
     * @param  iterable<Serial>  $serials
     */
    public static function nextOf(iterable $serials): ?Serial
    {
        $next = null;

        foreach ($serials as $serial) {
            if ($serial->status !== SerialStatus::CheckedIn) {
                continue;
            }

            if ($next === null || $serial->position < $next->position || ($serial->position === $next->position && $serial->number < $next->number)) {
                $next = $serial;
            }
        }

        return $next;
    }

    /** @return array{called: Serial|null, waiting_booked: int} */
    public function handle(SessionInstance $instance, Actor $actor): array
    {
        return DB::transaction(function () use ($instance, $actor): array {
            $session = SessionLocks::lockSession($instance->id);

            if (! in_array($session->status, [SessionStatus::Scheduled, SessionStatus::Running, SessionStatus::Paused], true)) {
                throw new IllegalSessionState($session, 'call the next serial');
            }

            $candidate = Serial::query()
                ->where('session_instance_id', $session->id)
                ->where('status', SerialStatus::CheckedIn->value)
                ->orderBy('position')->orderBy('number')
                ->lock('for update skip locked')
                ->first();

            if ($candidate === null) {
                return ['called' => null, 'waiting_booked' => Serial::query()->where('session_instance_id', $session->id)->where('status', SerialStatus::Booked->value)->count()];
            }

            $called = CallSerial::call($this->transition, $this->autoNoShow, $session, $candidate, $actor);

            return ['called' => $called, 'waiting_booked' => Serial::query()->where('session_instance_id', $session->id)->where('status', SerialStatus::Booked->value)->count()];
        }, attempts: 3);
    }

    /** Shared by CallNext/CallSerial: session state + now_serving + events, under the session lock. */
    public static function markCalled(SessionInstance $session, Serial $called, Actor $actor): void
    {
        $previous = $session->now_serving_serial_id;
        $started = $session->status === SessionStatus::Scheduled;

        $session->forceFill([
            'now_serving_serial_id' => $called->id,
            'last_called_at' => now(),
            'actual_start_at' => $session->actual_start_at ?? now(),
            'status' => $started ? SessionStatus::Running : $session->status,
        ])->save();

        if ($started) {
            DoctorArrived::dispatch($session, $actor, ['trigger' => 'call']);
        }

        SerialCalled::dispatch($called, $session, $previous);
    }
}
