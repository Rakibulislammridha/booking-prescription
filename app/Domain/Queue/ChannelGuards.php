<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Services\BranchAccess;
use App\Domain\Clinic\Services\DoctorScope;
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
    /**
     * Staff user of that branch (web/sanctum) or a reception device of that branch (device).
     *
     * Stays branch-wide for everyone the DoctorScope restricts too, because NOTHING this channel carries names a
     * patient: `board.updated` is BoardStateBuilder's — session codes, counts and a now-serving code — and
     * `serial.status_changed`, `session.delayed`, `session.cancelled`, `doctor.arrived` and the desk's copy of
     * `serial.called` are all codes as well. That last one was not: it carried the first name, age and sex of every
     * chamber's patient until QueueBroadcaster::serialCalled() was split, and this paragraph claimed otherwise —
     * a channel guard's "the payload is harmless" is only as true as the broadcaster keeps it, so check both
     * before widening either. Narrowing a channel per subscriber is not possible anyway (one payload, many
     * listeners); what must not leak is fetched through the scoped endpoints, not broadcast.
     */
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
     *
     * The operator clause is "holds the permission AND has no doctors row", and a compounder satisfies the second
     * half — so DoctorScope is asserted here explicitly rather than left to rest on the permission gap. A user this
     * scope restricts may only ever hold a channel of a doctor they are assigned to, whatever they may hold
     * tomorrow: a live queue channel is a patient-by-patient feed, and a stale subscription outlives the page.
     */
    public function doctor(?Authenticatable $auth, string $tenant, string $doctor): bool
    {
        if (! self::tenantMatches($tenant) || ! $auth instanceof User || ! $auth->is_active) {
            return false;
        }

        $row = Doctor::query()->where('public_id', $doctor)->first();

        if ($row === null || ! app(DoctorScope::class)->allows($auth, $row->id)) {
            return false;
        }

        if ($row->user_id === $auth->id) {
            return true;
        }

        return $auth->can(Permission::QueueCallNext->value)
            && Doctor::query()->where('user_id', $auth->id)->doesntExist();
    }

    /**
     * A reception_devices row with kind = display at that branch, or a staff user of that branch whom DoctorScope
     * does not restrict (the board preview DisplayPageController offers a logged-in user).
     *
     * The staff leg was `return $auth->is_active` — no branch, no permission, no scope — while the channel carries
     * `call.next` and the private `serial.called` with a first name, an age, a sex, `vitals_taken` and the doctor's
     * public id. Any active user of any branch could therefore watch every chamber of every other branch call its
     * patients, and branch public ids are enumerable from any board prop. So: the same one-branch rule the reception
     * channel uses (BranchAccess), plus DoctorScope — this feed is every doctor's calls by design, which is the one
     * shape a compounder may not have, and a display device is not narrowable per subscriber either.
     *
     * The waiting-room screen itself is unaffected: the TV authenticates as a `display` device against
     * /api/device/broadcasting/auth (REALTIME.md §9), the device leg below. A staff preview of another branch keeps
     * the page, and falls back to the §6 polling the tiles already use.
     */
    public function display(?Authenticatable $auth, string $tenant, string $branch): bool
    {
        $row = self::branch($tenant, $branch);

        if ($row === null) {
            return false;
        }

        if ($auth instanceof User) {
            return self::staffAtBranch($auth, $row) && app(DoctorScope::class)->doctorIds($auth) === null;
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
