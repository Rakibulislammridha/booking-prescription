<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Listeners;

use App\Domain\Clinic\Enums\LeaveType;
use App\Domain\Clinic\Events\DoctorLeaveCreated;
use App\Domain\Serials\Actions\CancelSession;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SessionInstance;

/**
 * SERIAL_ENGINE §2.1 row 2: a new leave cancels the doctor's existing open instances in range (at the leave's branch,
 * or every branch). Emergency leaves notify immediately — CancelSession's SessionCancelled carries notify_patients.
 */
final class CancelSessionsForLeave
{
    public function __construct(private readonly CancelSession $cancelSession) {}

    public function handle(DoctorLeaveCreated $event): void
    {
        $leave = $event->leave;

        $instances = SessionInstance::query()
            ->where('doctor_id', $leave->doctor_id)
            ->when($leave->branch_id !== null, fn ($q) => $q->where('branch_id', $leave->branch_id))
            ->whereBetween('session_date', [$leave->starts_on->toDateString(), $leave->ends_on->toDateString()])
            ->open()
            ->get();

        foreach ($instances as $instance) {
            $this->cancelSession->handle(
                $instance,
                $leave->created_by_user_id === null ? Actor::system() : Actor::user($leave->created_by_user_id),
                $leave->type === LeaveType::Emergency ? 'emergency_leave' : 'doctor_leave',
                $leave->notify_patients,
            );
        }
    }
}
