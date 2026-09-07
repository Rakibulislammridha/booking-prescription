<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialTransferred;
use App\Domain\Serials\Exceptions\TransferTargetInvalid;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * Transfer to another doctor (SERIAL_ENGINE §9.2): the target belongs to a different doctor; old → cancelled with
 * reason `transferred` + transferred_to_serial_id; new serial in the target session; events transferred_out /
 * transferred_in; SerialTransferred(old, new, old_fee_snapshot, target_fee) for billing.
 */
final class TransferSerial
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly AllocateSerial $allocate,
        private readonly SerialEventWriter $events,
    ) {}

    /** "transfer:{old.public_id}" of SERIAL_ENGINE §9.2 in the char(26) ULID shape: 'T' + the last 25 chars of the old public_id. */
    public static function clientEventId(Serial $old): string
    {
        return 'T'.substr($old->public_id, 1);
    }

    /** @return array{old: Serial, new: Serial, fee_delta_expected: int} */
    public function handle(Serial $serial, SessionInstance $target, Actor $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($serial, $target, $actor, $reason): array {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();
            /** @var SessionInstance $source */
            $source = SessionInstance::query()->findOrFail($locked->session_instance_id);

            if ($target->doctor_id === $source->doctor_id) {
                throw new TransferTargetInvalid('Same-doctor moves are postpones; a transfer needs another doctor\'s session.');
            }

            $new = ($this->allocate)(new AllocationRequest(
                sessionInstanceId: $target->id,
                pool: $locked->pool === SerialPool::Online ? SerialPool::Online : SerialPool::Counter,
                source: $locked->source,
                priority: $locked->priority,
                patientId: $locked->patient_id,
                appointmentId: null,   // appointments.serial_id is unique; the Booking module re-points it on SerialTransferred
                clientEventId: self::clientEventId($locked),
                actorUserId: $actor->userId,
                transferredFromSerialId: $locked->id,
            ));

            $old = $this->transition->apply($locked, SerialStatus::Cancelled, $actor, ['session' => $source, 'cancel_reason_code' => CancelReason::Transferred, 'reason' => $reason]);
            $old->forceFill(['transferred_to_serial_id' => $new->id])->save();

            $this->events->write($source, $old, SerialEventType::TransferredOut, ['related_serial_id' => $new->id, 'target_doctor_id' => $target->doctor_id, 'reason' => $reason], $actor, ['reason' => $reason]);
            $this->events->write($target, $new, SerialEventType::TransferredIn, ['related_serial_id' => $old->id, 'target_doctor_id' => $target->doctor_id, 'from_serial' => $old->display_code], $actor);

            SerialTransferred::dispatch($old, $new, $source->fee_new_paisa, $target->fee_new_paisa);

            return ['old' => $old, 'new' => $new, 'fee_delta_expected' => $target->fee_new_paisa - $source->fee_new_paisa];
        });
    }
}
