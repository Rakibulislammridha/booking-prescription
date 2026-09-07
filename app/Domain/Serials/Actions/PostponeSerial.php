<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialPostponed;
use App\Domain\Serials\Exceptions\NoNextSession;
use App\Domain\Serials\Exceptions\TransferTargetInvalid;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * Postpone to the doctor's next session (SERIAL_ENGINE §9.1): old → postponed, new serial allocated in the target
 * (online → online, everything else → counter; clientEventId "postpone:{old.public_id}" makes a retry idempotent),
 * both directions linked; the appointment move is the Booking module's reaction to SerialPostponed (appointments
 * table is not ours). If the target pool is exhausted the whole transaction rolls back.
 */
final class PostponeSerial
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly AllocateSerial $allocate,
        private readonly SerialEventWriter $events,
    ) {}

    /** @return array{old: Serial, new: Serial} */
    public function handle(Serial $serial, ?SessionInstance $target, Actor $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($serial, $target, $actor, $reason): array {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();
            /** @var SessionInstance $source */
            $source = SessionInstance::query()->findOrFail($locked->session_instance_id);
            $target ??= $this->nextInstance($source);

            if ($target->id === $source->id) {
                throw new TransferTargetInvalid('The target must be a different session of the same doctor.');
            }

            if ($target->doctor_id !== $source->doctor_id) {
                throw new TransferTargetInvalid('Postpone keeps the doctor; use a transfer to move to another doctor.');
            }

            $old = $this->transition->apply($locked, SerialStatus::Postponed, $actor, ['session' => $source, 'reason' => $reason]);

            $new = ($this->allocate)(new AllocationRequest(
                sessionInstanceId: $target->id,
                pool: $old->pool === SerialPool::Online ? SerialPool::Online : SerialPool::Counter,
                source: $old->source,
                priority: $old->priority,
                patientId: $old->patient_id,
                appointmentId: $old->appointment_id,
                clientEventId: self::clientEventId($old),
                actorUserId: $actor->userId,
                transferredFromSerialId: $old->id,
            ));

            $old->forceFill(['postponed_to_serial_id' => $new->id])->save();

            $this->events->write($source, $old, SerialEventType::Postponed, ['to_serial' => $new->display_code, 'to_session' => $target->public_id, 'reason' => $reason], $actor, ['reason' => $reason]);
            SerialPostponed::dispatch($old, $new);

            return ['old' => $old, 'new' => $new];
        });
    }

    /**
     * The idempotency key of a retried postpone. SERIAL_ENGINE writes it as "postpone:{old.public_id}", which cannot
     * fit serials.client_event_id char(26); the key keeps the ULID shape instead: 'P' + the last 25 chars of the old
     * serial's public_id (unique per old serial; 'P' is a valid Crockford base32 digit).
     */
    public static function clientEventId(Serial $old): string
    {
        return 'P'.substr($old->public_id, 1);
    }

    /** Today's next code after this one at the same branch, else the doctor's first later instance (§9.1). */
    private function nextInstance(SessionInstance $source): SessionInstance
    {
        $next = SessionInstance::query()
            ->where('doctor_id', $source->doctor_id)->where('branch_id', $source->branch_id)
            ->open()
            ->where(fn ($q) => $q
                ->where(fn ($d) => $d->whereDate('session_date', $source->session_date->toDateString())->where('session_code', '>', $source->session_code))
                ->orWhereDate('session_date', '>', $source->session_date->toDateString()))
            ->orderBy('session_date')->orderBy('session_code')
            ->first();

        return $next ?? throw new NoNextSession;
    }
}
