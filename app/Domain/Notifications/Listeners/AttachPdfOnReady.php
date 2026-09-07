<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Prescription\Events\PdfReady;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Prescription;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The other half of DeliverPrescription: an email parked while the PDF rendered is now given the path and released
 * at once, instead of waiting out its timer. Idempotent — a second PdfReady finds nothing left in `scheduled`.
 */
final class AttachPdfOnReady implements ShouldQueue
{
    use InteractsWithQueue, TenantAware;

    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct()
    {
        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(PdfReady $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $prescription = Prescription::query()->find($event->prescriptionId);

        if ($prescription === null) {
            return;
        }

        $pending = Notification::query()
            ->where('notifiable_type', $prescription->getMorphClass())
            ->where('notifiable_id', $prescription->id)
            ->where('channel', NotificationChannel::Email->value)
            ->where('status', NotificationStatus::Scheduled->value)
            ->get();

        foreach ($pending as $notification) {
            $payload = $notification->payload;
            $payload['pdf_path'] = $event->path;

            $notification->forceFill(['payload' => $payload, 'status' => NotificationStatus::Queued, 'scheduled_for' => null])->save();
            SendNotificationJob::dispatch($notification->id)->onQueue('notifications');
        }
    }
}
