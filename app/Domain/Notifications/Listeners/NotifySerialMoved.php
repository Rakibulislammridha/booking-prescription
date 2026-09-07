<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\EventChannels;
use App\Domain\Notifications\Services\NotificationVariables;
use App\Domain\Notifications\Services\RecipientResolver;
use App\Domain\Serials\Events\SerialPostponed;
use App\Domain\Serials\Events\SerialTransferred;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * "Your serial moved" — SERIAL_ENGINE §9.2 transfer (another doctor) and postpone (the next session). One listener
 * for both because the message is the same shape: old serial, new serial, where and when to turn up now.
 * Deduped on the NEW serial, which is created once per move.
 */
final class NotifySerialMoved implements ShouldQueue
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

    public function handle(SerialTransferred|SerialPostponed $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $notificationEvent = $event instanceof SerialTransferred ? NotificationEvent::SerialTransferred : NotificationEvent::SerialPostponed;
        $newId = $event->new['id'] ?? null;
        $new = is_numeric($newId) ? Serial::query()->find((int) $newId) : null;
        $patientId = $event->new['patient_id'] ?? null;
        $patient = is_numeric($patientId) ? Patient::query()->find((int) $patientId) : null;

        if ($new === null || ! $patient instanceof Patient) {
            return;
        }

        $session = SessionInstance::query()->find($new->session_instance_id);
        $probe = new NotificationRequest(event: $notificationEvent, channel: $this->channels->primary($notificationEvent), patient: $patient);
        $locale = $this->recipients->locale($probe);

        $variables = [
            ...$this->variables->base($patient, $locale),
            ...$this->variables->forSerial($new, $session, $locale),
            'serial' => (string) ($event->old['display_code'] ?? ''),
            'new_serial' => $new->display_code,
            'new_doctor' => $session === null ? '' : $this->variables->doctorName($this->variables->doctor($session->doctor_id), $locale),
        ];

        foreach ($this->channels->for($notificationEvent) as $channel) {
            $this->queueNotification->handle(new NotificationRequest(
                event: $notificationEvent,
                channel: $channel,
                patient: $patient,
                locale: $locale,
                variables: $variables,
                notifiable: $new,
                serialId: $new->id,
                dedupeKey: $notificationEvent->value.':'.$new->id,
            ));
        }
    }
}
