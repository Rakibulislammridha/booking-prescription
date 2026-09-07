<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Events;

use App\Models\Tenant\Doctor;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class DoctorCreated implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Doctor $doctor) {}
}
