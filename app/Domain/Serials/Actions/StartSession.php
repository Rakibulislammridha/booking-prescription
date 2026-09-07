<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Events\DoctorArrived;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/** scheduled → running (Doctor/Reception "start", or DoctorArrived): actual_start_at = now(); DoctorArrived after commit. */
final class StartSession
{
    public function handle(SessionInstance $instance, Actor $actor): SessionInstance
    {
        return DB::transaction(function () use ($instance, $actor): SessionInstance {
            $session = SessionLocks::lockSession($instance->id);

            if ($session->status === SessionStatus::Running) {
                return $session;   // idempotent
            }

            if ($session->status !== SessionStatus::Scheduled) {
                throw new IllegalSessionState($session, 'start');
            }

            $session->forceFill(['status' => SessionStatus::Running, 'actual_start_at' => $session->actual_start_at ?? now()])->save();
            CountsRecalculator::bumpVersion($session->id);
            DoctorArrived::dispatch($session, $actor, ['trigger' => 'start']);

            return $session;
        }, attempts: 3);
    }
}
