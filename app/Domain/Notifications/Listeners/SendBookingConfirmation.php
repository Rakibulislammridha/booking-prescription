<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Booking\Events\AppointmentBooked;
use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\EventChannels;
use App\Domain\Notifications\Services\NotificationVariables;
use App\Domain\Notifications\Services\RecipientResolver;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * BRIEF §5.C — "mobile number → … → serial assigned → payment → confirmation". Deduped on the appointment, so a
 * replayed offline booking event or a double dispatch confirms once.
 */
final class SendBookingConfirmation implements ShouldQueue
{
    use InteractsWithQueue, TenantAware;

    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(
        private readonly QueueNotification $queueNotification,
        private readonly NotificationVariables $variables,
        private readonly RecipientResolver $recipients,
        private readonly EventChannels $channels,
    ) {
        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(AppointmentBooked $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $serialId = $event->serial['id'] ?? null;
        $serial = is_int($serialId) || is_string($serialId) ? Serial::query()->find((int) $serialId) : null;
        $patientId = $event->appointment['patient_id'] ?? null;
        $patient = $patientId === null ? null : Patient::query()->find((int) $patientId);

        if ($serial === null || ! $patient instanceof Patient) {
            return;
        }

        $appointment = Appointment::query()->find($event->appointmentId);
        $base = new NotificationRequest(
            event: NotificationEvent::BookingConfirmed,
            channel: $this->channels->primary(NotificationEvent::BookingConfirmed),
            patient: $patient,
            notifiable: $appointment,
            serialId: $serial->id,
            dedupeKey: 'booking_confirmed:'.$event->appointmentId,
        );

        $locale = $this->recipients->locale($base);
        $variables = [...$this->variables->base($patient, $locale), ...$this->variables->forSerial($serial, null, $locale)];

        foreach ($this->channels->for(NotificationEvent::BookingConfirmed) as $channel) {
            $this->queueNotification->handle(new NotificationRequest(
                event: NotificationEvent::BookingConfirmed,
                channel: $channel,
                patient: $patient,
                locale: $locale,
                variables: $variables,
                notifiable: $appointment,
                serialId: $serial->id,
                dedupeKey: 'booking_confirmed:'.$event->appointmentId,
            ));
        }
    }
}
