<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\PatientMedication;
use App\Support\Clock;

/** "Stop" a long-term medication: is_active = false, ended_on = today. */
final class RemoveMedication
{
    public function handle(PatientMedication $medication, Actor $actor): PatientMedication
    {
        $medication->fill(['is_active' => false, 'ended_on' => $medication->ended_on ?? Clock::today()])->save();

        return $medication;
    }
}
