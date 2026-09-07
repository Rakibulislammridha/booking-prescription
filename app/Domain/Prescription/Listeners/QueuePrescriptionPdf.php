<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Listeners;

use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\Prescription\Jobs\GeneratePrescriptionPdf;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * PRESCRIPTION.md §6.1 step 9 / §7.5 — issuing queues the PDF. The listener itself is synchronous: its only job is
 * to push one job onto the `pdf` queue from the tenant context that issued, so the writer tab can start waiting
 * for PdfReady in the same breath as the 200.
 */
final class QueuePrescriptionPdf
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function handle(PrescriptionIssued $event): void
    {
        if (! (bool) config('prescription.pdf.on_issue', true)) {
            return;
        }

        $this->bus->dispatch((new GeneratePrescriptionPdf($event->prescriptionId))->forTenant($event->tenantId));
    }
}
