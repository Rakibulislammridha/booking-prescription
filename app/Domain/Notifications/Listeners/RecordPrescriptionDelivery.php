<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Events\NotificationSent;
use App\Models\Tenant\Prescription;
use App\Tenancy\Facades\Tenancy;

/**
 * P3 left `prescriptions.delivered_channels` recording an ATTEMPT because, without this module, an attempt was the
 * only fact it could honestly record. Now that gateways exist, the column means what its name says: a channel is
 * listed once a gateway has ACCEPTED the message. Runs synchronously — it is one small array append on a row that
 * is already loaded, and `RequestDelivery` no longer writes the column at all.
 */
final class RecordPrescriptionDelivery
{
    public function handle(NotificationSent $event): void
    {
        if (! Tenancy::check() || $event->eventKey !== NotificationEvent::PrescriptionReady->value) {
            return;
        }

        if ($event->notifiableId === null || $event->notifiableType !== Prescription::class) {
            return;
        }

        $prescription = Prescription::query()->find($event->notifiableId);

        if ($prescription === null || in_array($event->channel, $prescription->delivered_channels, true)) {
            return;
        }

        $prescription->forceFill(['delivered_channels' => [...$prescription->delivered_channels, $event->channel]])->save();
    }
}
