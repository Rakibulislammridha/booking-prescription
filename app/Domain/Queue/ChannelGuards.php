<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Services\BranchAccess;
use App\Domain\Reception\Enums\DeviceKind;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Private-channel authorisation (REALTIME.md §2). Every method first asserts that the `{tenant}` segment is the
 * current tenant's public id (the tenancy middleware already ran on /broadcasting/auth), then applies the
 * role/branch/device-kind rule. Returning false ⇒ 403 ⇒ pusher:subscription_error on the client.
 *
 * `$auth` is a staff User (guards web/sanctum) or a ReceptionDevice (guard `device`, bearer token on
 * POST /api/device/broadcasting/auth). Registered from QueueServiceProvider::boot().
 */
final class ChannelGuards
{
    /** Staff user of that branch (web/sanctum) or a reception device of that branch (device). */
    public function reception(?Authenticatable $auth, string $tenant, string $branch): bool
    {
        $row = self::branch($tenant, $branch);

        if ($row === null) {
            return false;
        }

        if ($auth instanceof User) {
            return self::staffAtBranch($auth, $row);
        }

        return $auth instanceof ReceptionDevice
            && $auth->isActive()
            && $auth->branch_id === $row->id
            && $auth->kind === DeviceKind::Reception;
    }

    /**
     * The doctor themself, or an operator (reception / admin) with permission queue.call-next. A user who IS a doctor
     * only ever sees their own screen — the permission does not open a colleague's chamber.
     */
    public function doctor(?Authenticatable $auth, string $tenant, string $doctor): bool
    {
        if (! self::tenantMatches($tenant) || ! $auth instanceof User || ! $auth->is_active) {
            return false;
        }

        $row = Doctor::query()->where('public_id', $doctor)->first();

        if ($row === null) {
            return false;
        }

        if ($row->user_id === $auth->id) {
            return true;
        }

        return $auth->can(Permission::QueueCallNext->value)
            && Doctor::query()->where('user_id', $auth->id)->doesntExist();
    }

    /** A reception_devices row with kind = display at that branch, or an active staff user. */
    public function display(?Authenticatable $auth, string $tenant, string $branch): bool
    {
        $row = self::branch($tenant, $branch);

        if ($row === null) {
            return false;
        }

        if ($auth instanceof User) {
            return $auth->is_active;
        }

        return $auth instanceof ReceptionDevice
            && $auth->isActive()
            && $auth->branch_id === $row->id
            && $auth->kind === DeviceKind::Display;
    }

    public static function tenantMatches(string $tenantPublicId): bool
    {
        $current = Tenancy::current();

        return $current !== null && hash_equals($current->public_id, $tenantPublicId);
    }

    private static function branch(string $tenant, string $branch): ?Branch
    {
        if (! self::tenantMatches($tenant)) {
            return null;
        }

        return Branch::query()->where('public_id', $branch)->first();
    }

    /** The one branch rule (BranchAccess): hospital admin anywhere; otherwise the user's default or active branch. */
    private static function staffAtBranch(User $user, Branch $branch): bool
    {
        return app(BranchAccess::class)->actsFor($user, $branch->id);
    }
}
