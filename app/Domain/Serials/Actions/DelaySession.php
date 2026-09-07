<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Events\SessionDelayed;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * Broadcast delay (absolute, not additive): delay_minutes; event `delayed`; SessionDelayed after commit (the Queue
 * module broadcasts, Notifications fans out per queue.delay_notify_min_change).
 */
final class DelaySession
{
    public function __construct(private readonly SerialEventWriter $events) {}

    public function handle(SessionInstance $instance, int $delayMinutes, Actor $actor, ?string $message = null): SessionInstance
    {
        return DB::transaction(function () use ($instance, $delayMinutes, $actor, $message): SessionInstance {
            $session = SessionLocks::lockSession($instance->id);

            if (! $session->acceptsSerials()) {
                throw new IllegalSessionState($session, 'delay');
            }

            $previous = $session->delay_minutes;
            $session->forceFill(['delay_minutes' => max(0, $delayMinutes)])->save();

            $this->events->write($session, null, SerialEventType::Delayed, ['delay_minutes' => $session->delay_minutes, 'previous' => $previous, 'message' => $message], $actor, ['reason' => $message]);
            CountsRecalculator::bumpVersion($session->id);
            SessionDelayed::dispatch($session, $actor, ['delay_minutes' => $session->delay_minutes, 'previous_delay_minutes' => $previous, 'message' => $message]);

            return $session;
        }, attempts: 3);
    }
}
