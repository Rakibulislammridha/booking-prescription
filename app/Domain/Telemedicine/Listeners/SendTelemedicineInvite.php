<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Listeners;

use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\EventChannels;
use App\Domain\Notifications\Services\NotificationVariables;
use App\Domain\Notifications\Services\RecipientResolver;
use App\Domain\Telemedicine\Events\TelemedicineInviteIssued;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The join link reaches the patient through the Notifications module — this module builds no sender of its own
 * (BRIEF §5.J). It composes Notifications' own `QueueNotification` action, which applies consent, quiet hours,
 * templates, dedupe and the delivery log exactly as it does for every other message.
 *
 * The event key is `telemedicine_invite`; its default body is the one message in the catalogue that must carry
 * `{{link}}`, because for a video consultation the link IS the appointment.
 */
final class SendTelemedicineInvite implements ShouldQueue
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

    public function handle(TelemedicineInviteIssued $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $patient = Patient::query()->find($event->patientId);
        $appointment = Appointment::query()->find($event->appointmentId);

        if (! $patient instanceof Patient || $appointment === null) {
            return;
        }

        $serial = $event->serialId === null ? null : Serial::query()->find($event->serialId);
        $base = new NotificationRequest(
            event: NotificationEvent::TelemedicineInvite,
            channel: $this->channels->primary(NotificationEvent::TelemedicineInvite),
            patient: $patient,
            notifiable: $appointment,
            serialId: $event->serialId,
            dedupeKey: 'telemedicine_invite:'.$event->roomId,
        );

        $locale = $this->recipients->locale($base);
        $variables = [
            ...$this->variables->base($patient, $locale),
            ...($serial === null ? [] : $this->variables->forSerial($serial, null, $locale)),
            'link' => $event->joinUrl,
        ];

        foreach ($this->channels->for(NotificationEvent::TelemedicineInvite) as $channel) {
            $this->queueNotification->handle(new NotificationRequest(
                event: NotificationEvent::TelemedicineInvite,
                channel: $channel,
                patient: $patient,
                locale: $locale,
                variables: $variables,
                payload: ['link' => $event->joinUrl, 'expires_at' => $event->expiresAt],
                notifiable: $appointment,
                serialId: $event->serialId,
                dedupeKey: 'telemedicine_invite:'.$event->roomId,
            ));
        }
    }
}
