<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Events\DoctorLeaveCancelled;
use App\Domain\Clinic\Exceptions\LeaveAlreadyCancelled;
use App\Domain\Shared\Actor;
use App\Models\Tenant\DoctorLeave;

final class CancelDoctorLeave
{
    public function handle(DoctorLeave $leave, Actor $actor): DoctorLeave
    {
        if ($leave->is_cancelled) {
            throw new LeaveAlreadyCancelled;
        }

        $leave->forceFill(['is_cancelled' => true])->save();

        event(new DoctorLeaveCancelled($leave));

        return $leave;
    }
}
