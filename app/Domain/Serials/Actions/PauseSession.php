<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Events\SessionPaused;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/** running → paused (Doctor): the ETA freezes; SessionPaused after commit. `notes` keeps the pause start for ResumeSession. */
final class PauseSession
{
    public function handle(SessionInstance $instance, Actor $actor, ?string $reason = null): SessionInstance
    {
        return DB::transaction(function () use ($instance, $actor, $reason): SessionInstance {
            $session = SessionLocks::lockSession($instance->id);

            if ($session->status !== SessionStatus::Running) {
                throw new IllegalSessionState($session, 'pause');
            }

            $session->forceFill(['status' => SessionStatus::Paused, 'last_called_at' => $session->last_called_at, 'updated_at' => now()])->save();
            DB::table('session_instances')->where('id', $session->id)->update(['notes' => 'paused_at:'.now()->getTimestamp()]);
            CountsRecalculator::bumpVersion($session->id);
            SessionPaused::dispatch($session, $actor, ['reason' => $reason]);

            return $session->refresh();
        }, attempts: 3);
    }
}
