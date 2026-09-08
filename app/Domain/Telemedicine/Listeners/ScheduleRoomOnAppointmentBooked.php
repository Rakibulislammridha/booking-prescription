<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Listeners;

use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Events\AppointmentBooked;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Services\Entitlements;
use App\Domain\Telemedicine\Actions\ScheduleRoom;
use App\Models\Tenant\Appointment;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Log;

/**
 * A telemedicine booking gets its room (and therefore the patient's join link) at booking time.
 *
 * Runs INLINE, not queued: the booking response shows the patient the link straight away, and a room that
 * appeared thirty seconds later would be a confusing gap on the confirmation screen.
 *
 * Gated again here, not only at the route: a booking can also arrive from an offline replay or a seeder, and a
 * tenant whose add-on lapsed must not accumulate rooms it cannot open.
 */
final class ScheduleRoomOnAppointmentBooked
{
    public function __construct(
        private readonly ScheduleRoom $scheduleRoom,
        private readonly Entitlements $entitlements,
    ) {}

    public function handle(AppointmentBooked $event): void
    {
        if ($event->channel !== BookingChannel::Telemedicine || ! Tenancy::check()) {
            return;
        }

        $tenant = Tenancy::current();

        if ($tenant === null || ! $this->entitlements->for($tenant)->enabled(PlanFeatureKey::Telemedicine)) {
            Log::channel('clinical')->info('telemedicine: room not scheduled, add-on not in plan', ['appointment_id' => $event->appointmentId]);

            return;
        }

        $appointment = Appointment::query()->with('sessionInstance')->find($event->appointmentId);

        if ($appointment !== null) {
            $this->scheduleRoom->handle($appointment);
        }
    }
}
