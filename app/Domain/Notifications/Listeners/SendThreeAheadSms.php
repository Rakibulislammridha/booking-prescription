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
use App\Domain\Queue\Events\SerialApproaching;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * REALTIME.md §7 — "N patients ahead of you, please reach the chamber". The Queue module has already stamped
 * `serials.t3_notified_at` inside a single `UPDATE … RETURNING`, so this listener runs at most once per serial;
 * `dedupe_key = three_ahead:{serial_id}` is the second belt for a replayed or re-dispatched event.
 *
 * `ahead` may be smaller than the tenant's `queue.notify_ahead` when no-shows collapse the line — the message says
 * the actual count, which is why the number is a template variable and not baked into the text.
 */
final class SendThreeAheadSms implements ShouldQueue
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

    public function handle(SerialApproaching $event): void
    {
        if (! Tenancy::check() || $event->patientId === null) {
            return;
        }

        $patient = Patient::query()->find($event->patientId);
        $serial = Serial::query()->find($event->serialId);

        if (! $patient instanceof Patient || $serial === null) {
            return;
        }

        $probe = new NotificationRequest(event: NotificationEvent::ThreeAhead, channel: $this->channels->primary(NotificationEvent::ThreeAhead), patient: $patient);
        $locale = $this->recipients->locale($probe);

        $variables = [
            ...$this->variables->base($patient, $locale),
            ...$this->variables->forSerial($serial, null, $locale),
            'serial' => $event->displayCode,
            'ahead' => Localised::number($event->ahead, $locale),
            'eta' => Localised::time($serial->estimated_call_at, $locale),
        ];

        foreach ($this->channels->for(NotificationEvent::ThreeAhead) as $channel) {
            $this->queueNotification->handle(new NotificationRequest(
                event: NotificationEvent::ThreeAhead,
                channel: $channel,
                patient: $patient,
                locale: $locale,
                variables: $variables,
                notifiable: $serial,
                serialId: $serial->id,
                dedupeKey: 'three_ahead:'.$serial->id,
            ));
        }
    }
}
