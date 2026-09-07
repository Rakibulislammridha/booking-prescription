<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Scheduling;

use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\User;

final class UpdateDoctorScheduleRequest extends DoctorScheduleRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');
        $schedule = $this->route('schedule');

        return $user instanceof User && $schedule instanceof DoctorSchedule && $user->can('update', $schedule);
    }
}
