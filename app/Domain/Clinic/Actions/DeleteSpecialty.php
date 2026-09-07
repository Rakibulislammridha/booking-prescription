<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\Specialty;

/** doctor_specialties cascades (SCHEMA §3.1): removing a specialty only unlinks it from doctors. */
final class DeleteSpecialty
{
    public function handle(Specialty $specialty, Actor $actor): void
    {
        $specialty->delete();
    }
}
