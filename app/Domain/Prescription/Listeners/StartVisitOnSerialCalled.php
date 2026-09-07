<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Listeners;

use App\Domain\Prescription\Actions\StartVisit;
use App\Domain\Prescription\Exceptions\SerialHasNoPatient;
use App\Domain\Serials\Events\SerialCalled;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use Illuminate\Support\Facades\Log;

/**
 * Serials ships no ConsultationStarted domain event: the serial reaches `in_consultation` through CallNext/CallSerial,
 * which dispatch SerialCalled after commit — the visit opens there (idempotent, one visit per serial). Runs inline:
 * the writer must find the visit the moment the doctor's screen navigates.
 */
final class StartVisitOnSerialCalled
{
    public function __construct(private readonly StartVisit $startVisit) {}

    public function handle(SerialCalled $event): void
    {
        $serial = Serial::query()->with('sessionInstance')->find($event->serialId);

        if ($serial === null) {
            return;
        }

        try {
            $this->startVisit->handle($serial, Actor::system(), 'serial_called');
        } catch (SerialHasNoPatient) {
            Log::channel('clinical')->info('visit not started: serial without patient', ['serial_id' => $serial->id]);
        }
    }
}
