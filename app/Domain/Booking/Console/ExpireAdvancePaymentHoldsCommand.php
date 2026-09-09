<?php

declare(strict_types=1);

namespace App\Domain\Booking\Console;

use App\Domain\Booking\Actions\ReleaseExpiredHold;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Booking\Services\AdvancePaymentPolicy;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * booking:expire-holds — runs inside a tenant context (`tenants:run booking:expire-holds`, App\Domain\Booking\Schedule).
 *
 * A serial held for advance payment (BRIEF §5.C) that is still unpaid after `booking.advance_payment_hold_minutes`
 * goes back to the clinic through the ordinary CancelAppointment → CancelSerial path with reason `no_payment`
 * (SERIAL_ENGINE §6), so the number is released, the counts are recalculated and the queue version is bumped
 * exactly as a desk cancellation would. Nothing about the LOCKED allocation is special-cased.
 *
 * The candidate list read here is a snapshot and decides nothing: every hold is handed, one at a time and in its
 * own transaction, to ReleaseExpiredHold, which locks the row and re-checks the whole predicate (still pending,
 * still unpaid, still past the cutoff) before cancelling. Money that lands between this read and that lock keeps
 * the booking, and the row is reported as skipped rather than cancelled for "no payment".
 *
 * Idempotent and safe on an empty clinic: a hold that has since been paid is no longer `unpaid`, one that was
 * checked in at the desk is no longer `pending`, and an already cancelled appointment is a no-op in the action.
 */
final class ExpireAdvancePaymentHoldsCommand extends Command
{
    protected $signature = 'booking:expire-holds {--minutes= : Override the hold window (default: booking.advance_payment_hold_minutes)}';

    protected $description = 'Cancel self-service bookings still held unpaid for advance payment past the hold window';

    public function handle(AdvancePaymentPolicy $policy, ReleaseExpiredHold $release): int
    {
        if (! Tenancy::check()) {
            $this->components->error('booking:expire-holds needs a tenant context; run it through tenants:run.');

            return self::FAILURE;
        }

        $minutes = $this->option('minutes');
        $window = is_numeric($minutes) ? max(1, (int) $minutes) : $policy->holdMinutes();
        $cutoff = now()->subMinutes($window);

        $expired = 0;
        $skipped = 0;
        $failed = 0;

        // Ids only: the rows are re-read under their own lock inside ReleaseExpiredHold (AdvancePaymentPolicy::isExpiredHold).
        Appointment::query()
            ->select('id')
            ->where('status', AppointmentStatus::Pending->value)
            ->where('payment_status', PaymentStatus::Unpaid->value)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function (Collection $appointments) use ($release, $cutoff, &$expired, &$skipped, &$failed): void {
                /** @var Appointment $appointment */
                foreach ($appointments as $appointment) {
                    try {
                        if ($release->handle($appointment, $cutoff, Actor::system(), __('booking.hold.expired')) === null) {
                            $skipped++;
                        } else {
                            $expired++;
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        report($e);
                        $this->components->error("Appointment #{$appointment->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->components->info("booking:expire-holds: {$expired} unpaid hold(s) released after {$window} minute(s).");

        if ($skipped > 0) {
            $this->components->info("{$skipped} hold(s) skipped: settled or moved on between the candidate read and the row lock.");
        }

        if ($failed > 0) {
            $this->components->error("{$failed} hold(s) failed.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
