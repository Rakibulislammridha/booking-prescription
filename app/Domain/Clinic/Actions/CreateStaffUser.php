<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Events\StaffUserCreated;
use App\Domain\Shared\Actor;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateStaffUser
{
    public function handle(StaffUserData $data, Actor $actor): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data->name,
                'email' => $data->email,
                'mobile' => $data->mobile,
                'password' => $data->password ?? Str::password(16),
                'default_branch_id' => $data->defaultBranchId,
                'locale' => $data->locale,
                'is_active' => $data->isActive,
                'must_change_password' => $data->mustChangePassword || $data->password === null,
                'session_timeout_minutes' => $data->sessionTimeoutMinutes,
            ]);

            $user->syncRoles([$data->role->value]);

            event(new StaffUserCreated($user));

            return $user;
        });
    }
}
