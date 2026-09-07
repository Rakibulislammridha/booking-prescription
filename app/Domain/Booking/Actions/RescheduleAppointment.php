<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Exceptions\AppointmentNotReschedulable;
use App\Domain\Booking\Services\FeeResolver;
use App\Domain\Serials\Actions\PostponeSerial;
use App\Domain\Serials\Actions\TransferSerial;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * Move a booking to another session: the same doctor's session is a postpone (SERIAL_ENGINE §9.1), another doctor's a
 * transfer (§9.2, fee re-snapshotted for the new doctor). The serial actions do the atomic part; the appointment is
 * re-pointed here (and again, idempotently, by the SerialPostponed / SerialTransferred listeners after commit). The old
 * serial gives up `appointment_id` first: `serials_appointment_id_uniq_p` allows one serial per appointment.
 */
final class RescheduleAppointment
{
    public function __construct(
        private readonly PostponeSerial $postpone,
        private readonly TransferSerial $transfer,
        private readonly FeeResolver $fees,
    ) {}

    /** @return array{appointment: Appointment, old: Serial, new: Serial} */
    public function handle(Appointment $appointment, SessionInstance $target, Actor $actor, ?string $reason = null): array
    {
        return DB::transaction(function () use ($appointment, $target, $actor, $reason): array {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $serial = $locked->serial_id === null ? null : Serial::query()->find($locked->serial_id);

            if ($serial === null || $serial->isTerminal() || ! $locked->status->isLive() || $locked->status === AppointmentStatus::Draft) {
                throw new AppointmentNotReschedulable;
            }

            $sameDoctor = $target->doctor_id === $locked->doctor_id;
            // serials.appointment_id is unique: the old serial gives the appointment up before the new one is allocated.
            $serial->forceFill(['appointment_id' => null])->save();
            $result = $sameDoctor
                ? $this->postpone->handle($serial, $target, $actor, $reason)
                : $this->transfer->handle($serial, $target, $actor, $reason);

            $columns = [
                'session_instance_id' => $target->id,
                'serial_id' => $result['new']->id,
                'scheduled_date' => $target->session_date->toDateString(),
                'slot_start_at' => $result['new']->slot_start_at,
                'status' => AppointmentStatus::Confirmed,
                'cancel_reason_code' => null,
                'cancelled_at' => null,
            ];

            if (! $sameDoctor) {
                /** @var Doctor $doctor */
                $doctor = Doctor::query()->with('profile')->findOrFail($target->doctor_id);
                $fee = $this->fees->resolve($locked->patient, $doctor, $target, $locked->channel, $locked->type);
                $columns += ['doctor_id' => $target->doctor_id, 'branch_id' => $target->branch_id] + $fee->toColumns();
            }

            $result['new']->forceFill(['appointment_id' => $locked->id])->save();
            $locked->forceFill($columns)->save();

            return ['appointment' => $locked, 'old' => $result['old'], 'new' => $result['new']];
        });
    }
}
