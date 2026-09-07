<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Events\PrescriptionDeliveryRequested;
use App\Domain\Prescription\Exceptions\NotLatestVersion;
use App\Domain\Prescription\Jobs\GeneratePrescriptionPdf;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Prescription\Services\SnapshotBuilder;
use App\Domain\Prescription\Services\VerificationUrl;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Prescription;

/**
 * POST …/send {channel, to?} → PrescriptionDeliveryRequested (Notifications owns templates and gateways), audit
 * `share` (§7.7). Two things happen here that the Notifications module cannot do for itself:
 *
 *  - if the PDF is not rendered yet the generation job is queued now, so the listener that chains after PdfReady
 *    has something to attach instead of waiting on a job nobody dispatched;
 *  - the channel used to be recorded on `prescriptions.delivered_channels` here, at request time. The
 *    Notifications module now owns that column: `App\Domain\Notifications\Listeners\RecordPrescriptionDelivery`
 *    appends the channel on `NotificationSent`, so the issued view shows what a gateway ACCEPTED rather than what
 *    somebody clicked. Nothing in this action writes the column any more.
 */
final class RequestDelivery
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Prescription $rx, string $channel, ?string $to, Actor $actor): PrescriptionDeliveryRequested
    {
        if ($rx->status !== PrescriptionStatus::Issued) {
            throw new NotLatestVersion($rx->id, "status is {$rx->status->value}; only an issued prescription can be sent");
        }

        $patient = $rx->patient;
        $to = $to !== null && trim($to) !== '' ? trim($to) : ($channel === 'email' ? $patient->email : $patient->mobile);
        $event = new PrescriptionDeliveryRequested($rx->tenant_id, $rx->id, $rx->patient_id, $channel, $to, VerificationUrl::for((string) $rx->verification_code), $rx->pdf_path, $rx->language->value);

        if ($rx->pdf_path === null) {
            GeneratePrescriptionPdf::dispatch($rx->id)->onQueue('pdf');
        }

        $this->auditor->sent($rx, $channel, $channel === 'email' ? (string) preg_replace('/^(.).*(@.*)$/', '$1***$2', (string) $to) : SnapshotBuilder::maskMobile($to));
        event($event);

        return $event;
    }
}
