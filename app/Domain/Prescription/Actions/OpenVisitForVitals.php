<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Exceptions\SerialNotPresent;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\Visit;

/**
 * The desk's way into an encounter (BRIEF §5.G.2: vitals are "entered by the compounder before the doctor sees
 * the patient"). It is StartVisit with one rule the doctor-side entries do not need, because for them the serial
 * has already been called: the patient must actually be here. A visit on a merely BOOKED serial would stamp the
 * patient's `visit_count` / `last_visit_at` for a consultation that has not happened, and would exist before
 * anyone at the desk has seen the person — so a serial that is not checked in (or already in the chamber) is
 * refused before StartVisit is asked for anything.
 *
 * Idempotent through StartVisit: the row's button pressed twice opens the same visit.
 */
final class OpenVisitForVitals
{
    public function __construct(private readonly StartVisit $startVisit) {}

    /** @throws SerialNotPresent */
    public function handle(Serial $serial, Actor $actor): Visit
    {
        if (! $serial->status->isPresent()) {
            throw new SerialNotPresent($serial->id, $serial->status);
        }

        return $this->startVisit->handle($serial, $actor, 'reception_desk');
    }
}
