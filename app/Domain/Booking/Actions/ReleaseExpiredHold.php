<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Services\AdvancePaymentPolicy;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * One held serial's release, decided under its row lock (BRIEF §5.C, SERIAL_ENGINE §6 `no_payment`).
 *
 * The sweep (`booking:expire-holds`) reads its candidates without a lock, and a candidate is only a snapshot: a
 * gateway callback or counter cash can settle the hold between that read and the cancellation. So the row is
 * locked FOR UPDATE here and the full predicate — still pending, still unpaid, still past the cutoff — is
 * re-checked on the locked row before CancelAppointment is asked for anything. A hold that was paid, confirmed
 * or checked in meanwhile is left alone and reported as `null` so the sweep can count it as skipped.
 *
 * Each call is its own transaction, so a long sweep never holds one hold's lock while it works on another.
 */
final class ReleaseExpiredHold
{
    public function __construct(
        private readonly CancelAppointment $cancel,
        private readonly AdvancePaymentPolicy $policy,
    ) {}

    /**
     * @return array{appointment: Appointment, refund_eligible: bool, refund: array<string, mixed>}|null
     *                                                                                                   null when the row no longer qualifies
     */
    public function handle(Appointment $appointment, CarbonInterface $cutoff, Actor $actor, ?string $note = null): ?array
    {
        return DB::transaction(function () use ($appointment, $cutoff, $actor, $note): ?array {
            /** @var Appointment|null $locked */
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->first();

            if ($locked === null || ! $this->policy->isExpiredHold($locked, $cutoff)) {
                return null;
            }

            return $this->cancel->handle($locked, CancelReason::NoPayment, $actor, $note);
        });
    }
}
