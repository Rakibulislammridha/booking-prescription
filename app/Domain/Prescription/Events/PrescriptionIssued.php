<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ARCHITECTURE §5.4: consumers — Prescription (GeneratePrescriptionPdf, RecordDoctorUsage), Notifications
 * (SendPrescriptionReady), Serials (CompleteConsultationOnPrescriptionIssued), SaaS (usage `prescriptions`).
 */
final class PrescriptionIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $prescriptionId,
        public readonly int $visitId,
        public readonly int $patientId,
        public readonly int $doctorId,
        public readonly ?int $serialId,
        public readonly string $prescriptionPublicId,
        public readonly int $version,
        public readonly string $verificationCode,
    ) {}
}
