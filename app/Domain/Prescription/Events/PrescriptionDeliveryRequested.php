<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** PRESCRIPTION.md §7.7 — Notifications' DeliverPrescription listens (waits for PdfReady when pdfPath is null). */
final class PrescriptionDeliveryRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $prescriptionId,
        public readonly int $patientId,
        public readonly string $channel,                // sms | whatsapp | email
        public readonly ?string $to,
        public readonly string $verificationUrl,
        public readonly ?string $pdfPath,
        public readonly string $language,
    ) {}
}
