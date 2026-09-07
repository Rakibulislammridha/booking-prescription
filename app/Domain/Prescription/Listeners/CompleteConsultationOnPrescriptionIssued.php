<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Listeners;

use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\Serials\Actions\CompleteConsultation;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;

/**
 * ARCHITECTURE §5.4 lists this listener under Serials; Serials did not ship it, so it lives here for now
 * (cross-module write through Serials' own CompleteConsultation action). Idempotent: only an `in_consultation` serial
 * is completed.
 */
final class CompleteConsultationOnPrescriptionIssued
{
    public function __construct(private readonly CompleteConsultation $complete) {}

    public function handle(PrescriptionIssued $event): void
    {
        if ($event->serialId === null) {
            return;
        }

        $serial = Serial::query()->find($event->serialId);

        if ($serial === null || $serial->status !== SerialStatus::InConsultation) {
            return;
        }

        $this->complete->handle($serial, Actor::system());
    }
}
