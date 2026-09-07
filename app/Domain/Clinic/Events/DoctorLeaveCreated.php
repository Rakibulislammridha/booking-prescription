<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Events;

use App\Models\Tenant\DoctorLeave;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Consumed by Scheduling (cancel session instances in range) and Notifications (patient fan-out when emergency).
 */
final class DoctorLeaveCreated implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly DoctorLeave $leave) {}
}
