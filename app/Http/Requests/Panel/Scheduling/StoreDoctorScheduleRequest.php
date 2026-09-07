<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Scheduling;

use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\User;

final class StoreDoctorScheduleRequest extends DoctorScheduleRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');
        $doctorId = $this->integer('doctor_id') ?: null;

        return $user instanceof User && $user->can('create', [DoctorSchedule::class, $doctorId]);
    }
}
