<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\NotificationVariables;
use App\Domain\Notifications\Support\Localised;
use App\Domain\Prescription\Events\PrescriptionDeliveryRequested;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The explicit "send this prescription to the patient" button (PRESCRIPTION.md §7.7). Unlike the automatic
 * `prescription_ready` fan-out, this one is a deliberate human action on a chosen channel and address, so it is
 * NOT deduped — a receptionist resending to a corrected mobile number must actually resend.
 *
 * What travels is the verification URL, never a storage path: `/rx/{code}` is the public, auditable copy and works
 * on a feature phone browser. Email additionally carries the PDF, which is why an email whose PDF is still
 * rendering is parked for `notifications.prescription.pdf_wait_seconds` and promoted the moment `PdfReady` lands
 * (AttachPdfOnReady) — and sent with the link alone if the render never finishes.
 */
final class DeliverPrescription implements ShouldQueue
{
    use InteractsWithQueue, TenantAware;

    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(
        private readonly QueueNotification $queueNotification,
        private readonly NotificationVariables $variables,
    ) {
        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(PrescriptionDeliveryRequested $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $channel = NotificationChannel::tryFrom($event->channel);
        $patient = Patient::query()->find($event->patientId);
        $prescription = Prescription::query()->find($event->prescriptionId);

        if ($channel === null || ! $patient instanceof Patient || $prescription === null || $event->to === null || trim($event->to) === '') {
            return;
        }

        $locale = Locale::tryFrom($event->language) ?? $patient->preferred_language;
        $needsPdf = $channel === NotificationChannel::Email && $event->pdfPath === null;

        $variables = [
            ...$this->variables->base($patient, $locale),
            'doctor' => $this->variables->doctorName($this->variables->doctor((int) $prescription->doctor_id), $locale),
            'date' => Localised::date($prescription->issued_at, $locale),
            'link' => $event->verificationUrl,
        ];

        $this->queueNotification->handle(new NotificationRequest(
            event: NotificationEvent::PrescriptionReady,
            channel: $channel,
            patient: $patient,
            recipient: trim($event->to),
            locale: $locale,
            variables: $variables,
            payload: ['link' => $event->verificationUrl, 'pdf_path' => $event->pdfPath, 'manual' => true],
            notifiable: $prescription,
            scheduledFor: $needsPdf ? CarbonImmutable::now()->addSeconds($this->pdfWaitSeconds()) : null,
        ));
    }

    private function pdfWaitSeconds(): int
    {
        return max(5, (int) config('notifications.prescription.pdf_wait_seconds', 120));
    }
}
