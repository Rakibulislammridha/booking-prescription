<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\DoctorData;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Exceptions\UserAlreadyLinkedToDoctor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorProfile;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;

final class UpdateDoctor
{
    public function handle(Doctor $doctor, DoctorData $data, Actor $actor): Doctor
    {
        return DB::transaction(function () use ($doctor, $data): Doctor {
            if ($data->userId !== null && Doctor::query()->where('user_id', $data->userId)->whereKeyNot($doctor->id)->exists()) {
                throw new UserAlreadyLinkedToDoctor;
            }

            $doctor->fill($data->toAttributes())->save();

            DoctorProfile::query()->updateOrCreate(['doctor_id' => $doctor->id], $data->profile->toAttributes());
            CreateDoctor::syncSpecialties($doctor, $data->specialtyIds, $data->primarySpecialtyId);

            if ($data->userId !== null) {
                User::query()->find($data->userId)?->assignRole(Role::Doctor->value);
            }

            return $doctor->refresh();
        });
    }
}
