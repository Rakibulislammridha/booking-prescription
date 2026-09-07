<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/** Call a specific serial (implicit check-in when still booked) — SERIAL_ENGINE §6/§14. Lock order: serial, then session. */
final class CallSerial
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly ApplyAutoNoShow $autoNoShow,
    ) {}

    public function handle(Serial $serial, Actor $actor): Serial
    {
        return DB::transaction(function () use ($serial, $actor): Serial {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();
            $session = SessionLocks::lockSession($locked->session_instance_id);

            if (! in_array($session->status, [SessionStatus::Scheduled, SessionStatus::Running, SessionStatus::Paused], true)) {
                throw new IllegalSessionState($session, 'call a serial');
            }

            return self::call($this->transition, $this->autoNoShow, $session, $locked, $actor);
        }, attempts: 3);
    }

    /** The call itself, inside the caller's transaction with the session row locked. */
    public static function call(SerialTransition $transition, ApplyAutoNoShow $autoNoShow, SessionInstance $session, Serial $serial, Actor $actor): Serial
    {
        $called = $transition->apply($serial, SerialStatus::InConsultation, $actor, ['session' => $session]);

        CallNext::markCalled($session, $called, $actor);
        $autoNoShow->sweep($session, $called, $actor);

        return $called;
    }
}
