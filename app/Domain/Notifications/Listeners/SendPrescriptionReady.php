<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\EventChannels;
use App\Domain\Notifications\Services\NotificationVariables;
use App\Domain\Notifications\Services\RecipientResolver;
use App\Domain\Notifications\Support\Localised;
use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\Prescription\Services\VerificationUrl;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * BRIEF §5.G.4 — "delivery by SMS, WhatsApp or email". This is the automatic one that fires when a prescription is
 * issued and carries the QR verification URL; the explicit "send to the patient" button goes through
 * `DeliverPrescription` instead. Deduped per prescription VERSION (an amendment is a new row, hence a new send).
 */
final class SendPrescriptionReady implements ShouldQueue
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

    public function handle(PrescriptionIssued $event): void
    {
        if (! Tenancy::check() || ! (bool) config('notifications.prescription.auto_notify', true)) {
            return;
        }

        $patient = Patient::query()->find($event->patientId);
        $prescription = Prescription::query()->find($event->prescriptionId);

        if (! $patient instanceof Patient || $prescription === null) {
            return;
        }

        $probe = new NotificationRequest(event: NotificationEvent::PrescriptionReady, channel: $this->channels->primary(NotificationEvent::PrescriptionReady), patient: $patient);
        $locale = $this->recipients->locale($probe);
        $link = VerificationUrl::for($event->verificationCode);

        $variables = [
            ...$this->variables->base($patient, $locale),
            'doctor' => $this->variables->doctorName($this->variables->doctor($event->doctorId), $locale),
            'date' => Localised::date($prescription->issued_at, $locale),
            'link' => $link,
        ];

        foreach ($this->channels->for(NotificationEvent::PrescriptionReady) as $channel) {
            $this->queueNotification->handle(new NotificationRequest(
                event: NotificationEvent::PrescriptionReady,
                channel: $channel,
                patient: $patient,
                locale: $locale,
                variables: $variables,
                payload: ['link' => $link],
                notifiable: $prescription,
                serialId: $event->serialId,
                dedupeKey: 'prescription_ready:'.$event->prescriptionId,
            ));
        }
    }
}
