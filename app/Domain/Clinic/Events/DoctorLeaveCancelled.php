<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Events;

use App\Models\Tenant\DoctorLeave;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class DoctorLeaveCancelled implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly DoctorLeave $leave) {}
}
