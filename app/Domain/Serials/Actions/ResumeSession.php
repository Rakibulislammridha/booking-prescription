<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Events\SessionResumed;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/** paused → running (Doctor): pause_seconds accumulates the interval; SessionResumed after commit. */
final class ResumeSession
{
    public function handle(SessionInstance $instance, Actor $actor): SessionInstance
    {
        return DB::transaction(function () use ($instance, $actor): SessionInstance {
            $session = SessionLocks::lockSession($instance->id);

            if ($session->status !== SessionStatus::Paused) {
                throw new IllegalSessionState($session, 'resume');
            }

            $pausedAt = null;

            if (is_string($session->notes) && str_starts_with($session->notes, 'paused_at:')) {
                $pausedAt = (int) substr($session->notes, strlen('paused_at:'));
            }

            $interval = $pausedAt === null ? max(0, now()->getTimestamp() - $session->updated_at->getTimestamp()) : max(0, now()->getTimestamp() - $pausedAt);

            $session->forceFill(['status' => SessionStatus::Running, 'pause_seconds' => $session->pause_seconds + $interval, 'notes' => null])->save();
            CountsRecalculator::bumpVersion($session->id);
            SessionResumed::dispatch($session, $actor, ['paused_seconds' => $interval]);

            return $session;
        }, attempts: 3);
    }
}
