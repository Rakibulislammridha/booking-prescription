<?php

declare(strict_types=1);

namespace App\Domain\Patients\Events;

use App\Models\Tenant\Patient;
use Illuminate\Foundation\Events\Dispatchable;

final class PatientCreated
{
    use Dispatchable;

    public function __construct(public readonly Patient $patient) {}
}
