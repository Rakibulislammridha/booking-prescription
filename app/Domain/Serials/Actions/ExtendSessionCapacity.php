<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Events\SessionCapacityExtended;
use App\Domain\Serials\Exceptions\ExtensionNotPermitted;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;

/**
 * "The doctor agrees to see 5 more" (SERIAL_ENGINE §3.4): buffer.range_end += k, max_serials += k — an append above
 * every existing number, so it needs no coordination with online booking, blocks or the desk. Authorised: Doctor
 * (own sessions), Hospital Admin, Super Admin; a receptionist up to serial.receptionist_extension_limit per session.
 */
final class ExtendSessionCapacity
{
    public function __construct(
        private readonly SerialEventWriter $events,
        private readonly Settings $settings,
        private readonly CapacityService $capacity,
    ) {}

    /** @throws IllegalSessionState */
    public function handle(SessionInstance $instance, int $extraSerials, Actor $actor, ?string $reason = null): SessionInstance
    {
        if ($extraSerials < 1) {
            throw new IllegalSessionState($instance, 'extend by less than one serial');
        }

        $this->assertPermitted($instance, $extraSerials, $actor);

        $session = DB::transaction(function () use ($instance, $extraSerials, $actor, $reason): SessionInstance {
            $buffer = SessionLocks::lockPool($instance->id, SerialPool::Buffer);
            $session = SessionLocks::lockSession($instance->id);

            if (! $session->acceptsSerials()) {
                throw new IllegalSessionState($session, 'extend');
            }

            $buffer->forceFill(['range_end' => $buffer->range_end + $extraSerials])->save();
            $session->forceFill(['max_serials' => $session->max_serials + $extraSerials, 'buffer_quota' => $session->buffer_quota + $extraSerials])->save();

            $this->events->write($session, null, SerialEventType::CapacityExtended, ['by' => $extraSerials, 'reason' => $reason, 'buffer_range_end' => $buffer->range_end], $actor, ['reason' => $reason]);
            CountsRecalculator::bumpVersion($session->id);
            SessionCapacityExtended::dispatch($session, $actor, ['by' => $extraSerials, 'reason' => $reason, 'max_serials' => $session->max_serials]);

            return $session;
        }, attempts: 3);

        $this->capacity->forget($session->public_id);

        return $session;
    }

    /** Receptionists: at most serial.receptionist_extension_limit per session (summed from the capacity_extended events they wrote). */
    private function assertPermitted(SessionInstance $instance, int $extra, Actor $actor): void
    {
        if ($actor->userId === null) {
            return;   // system / super admin paths are authorised upstream
        }

        $user = User::query()->find($actor->userId);

        if ($user === null || $user->hasRole(Role::HospitalAdmin->value) || $user->hasRole(Role::Doctor->value)) {
            return;
        }

        if (! $user->hasRole(Role::Receptionist->value)) {
            return;
        }

        $limit = max(0, (int) $this->settings->get('serial.receptionist_extension_limit'));
        $already = (int) DB::table('serial_events')
            ->where('session_instance_id', $instance->id)
            ->where('type', SerialEventType::CapacityExtended->value)
            ->where('actor_user_id', $user->id)
            ->sum(DB::raw("(meta->>'by')::int"));

        if ($already + $extra > $limit) {
            throw new ExtensionNotPermitted($limit);
        }
    }
}
