<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\DoctorData;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Events\DoctorCreated;
use App\Domain\Clinic\Exceptions\UserAlreadyLinkedToDoctor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\DoctorProfile;
use App\Models\Tenant\DoctorSpecialty;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;

/**
 * Doctor row + profile (fees / follow-up window) + default pad settings + specialties, in one transaction.
 */
final class CreateDoctor
{
    public function handle(DoctorData $data, Actor $actor): Doctor
    {
        return DB::transaction(function () use ($data): Doctor {
            if ($data->userId !== null && Doctor::query()->where('user_id', $data->userId)->exists()) {
                throw new UserAlreadyLinkedToDoctor;
            }

            $doctor = Doctor::query()->create($data->toAttributes());

            DoctorProfile::query()->create(['doctor_id' => $doctor->id] + $data->profile->toAttributes());
            DoctorPadSetting::query()->create(['doctor_id' => $doctor->id] + DoctorPadSetting::defaults());

            self::syncSpecialties($doctor, $data->specialtyIds, $data->primarySpecialtyId);

            if ($data->userId !== null) {
                User::query()->find($data->userId)?->assignRole(Role::Doctor->value);
            }

            event(new DoctorCreated($doctor));

            return $doctor;
        });
    }

    /** @param  array<int, int>  $specialtyIds */
    public static function syncSpecialties(Doctor $doctor, array $specialtyIds, ?int $primarySpecialtyId): void
    {
        $specialtyIds = array_values(array_unique($specialtyIds));
        $primary = $primarySpecialtyId !== null && in_array($primarySpecialtyId, $specialtyIds, true) ? $primarySpecialtyId : ($specialtyIds[0] ?? null);

        DoctorSpecialty::query()->where('doctor_id', $doctor->id)->whereNotIn('specialty_id', $specialtyIds)->delete();
        DoctorSpecialty::query()->where('doctor_id', $doctor->id)->update(['is_primary' => false]);

        foreach ($specialtyIds as $specialtyId) {
            DoctorSpecialty::query()->updateOrCreate(
                ['doctor_id' => $doctor->id, 'specialty_id' => $specialtyId],
                ['is_primary' => $specialtyId === $primary],
            );
        }
    }
}
