<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Exceptions\CannotDeactivateSelf;
use App\Domain\Clinic\Exceptions\CannotDemoteSelf;
use App\Domain\Shared\Actor;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;

/**
 * The two self-lockout guards live here rather than in the form request: they are rules about the clinic, not about
 * the shape of a payload, and they must hold for every caller (BRIEF §5.A).
 */
final class UpdateStaffUser
{
    public function handle(User $user, StaffUserData $data, Actor $actor): User
    {
        if (! $data->isActive && $actor->userId === $user->id) {
            throw new CannotDeactivateSelf;
        }

        if ($actor->userId === $user->id && $data->role !== Role::HospitalAdmin && $user->hasRole(Role::HospitalAdmin->value)) {
            throw new CannotDemoteSelf;
        }

        return DB::transaction(function () use ($user, $data): User {
            $user->fill([
                'name' => $data->name,
                'email' => $data->email,
                'mobile' => $data->mobile,
                'default_branch_id' => $data->defaultBranchId,
                'locale' => $data->locale,
                'is_active' => $data->isActive,
                'must_change_password' => $data->mustChangePassword,
                'session_timeout_minutes' => $data->sessionTimeoutMinutes,
            ]);

            if ($data->password !== null) {
                $user->password = $data->password;
            }

            $user->save();
            $user->syncRoles([$data->role->value]);

            return $user;
        });
    }
}
