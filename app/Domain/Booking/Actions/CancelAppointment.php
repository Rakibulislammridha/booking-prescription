<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Contracts\RefundProcessor;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Booking\Events\AppointmentCancelled;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Serial;
use Illuminate\Support\Facades\DB;

/**
 * Desk / site cancellation with a reason code (BRIEF §5.F): the serial is cancelled through the engine (which decides
 * refund eligibility), the appointment mirrors it, and the money question is handed to the RefundProcessor contract.
 * Idempotent: cancelling a cancelled appointment returns it unchanged.
 */
final class CancelAppointment
{
    public function __construct(
        private readonly CancelSerial $cancelSerial,
        private readonly RefundProcessor $refunds,
    ) {}

    /** @return array{appointment: Appointment, refund_eligible: bool, refund: array<string, mixed>} */
    public function handle(Appointment $appointment, CancelReason $reason, Actor $actor, ?string $note = null): array
    {
        return DB::transaction(function () use ($appointment, $reason, $actor, $note): array {
            /** @var Appointment $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === AppointmentStatus::Cancelled) {
                return ['appointment' => $locked, 'refund_eligible' => false, 'refund' => []];
            }

            $refundEligible = false;
            $serial = $locked->serial_id === null ? null : Serial::query()->find($locked->serial_id);

            if ($serial !== null && ! $serial->isTerminal()) {
                $refundEligible = $this->cancelSerial->handle($serial, $reason, $actor, $note)['refund_eligible'];
            }

            $locked->forceFill([
                'status' => AppointmentStatus::Cancelled,
                'cancel_reason_code' => $reason,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $actor->userId,
            ])->save();

            $refund = $locked->payment_status === PaymentStatus::Unpaid ? [] : $this->refunds->onCancellation($locked, $refundEligible, $actor);

            AppointmentCancelled::dispatch($locked, $reason->value, $refundEligible);

            return ['appointment' => $locked, 'refund_eligible' => $refundEligible, 'refund' => $refund];
        });
    }
}
