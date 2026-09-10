<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Exceptions\StaffUserNotFound;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;

/**
 * A clinic's staff as the super console reads them — INSIDE `Tenancy::run()`, which is the one sanctioned way
 * for control-plane code to open a clinic's tables (CONVENTIONS §4), and never with a `search_path` left behind.
 *
 * Read-only and deliberately shallow: name, role, active, last login. No patients, no prescriptions — support
 * needs to know who can sign in, not what they wrote.
 */
final class TenantStaffDirectory
{
    public const LIMIT = 200;

    /** @return array<int, array<string, mixed>> hospital admins first, then by name */
    public function list(Tenant $tenant): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = Tenancy::run($tenant, fn (): array => User::query()
            ->with(['roles:id,name', 'defaultBranch:id,name'])
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (User $user): array => $this->row($user))
            ->sortBy(fn (array $row): int => $row['role'] === Role::HospitalAdmin->value ? 0 : 1, SORT_NUMERIC, false)
            ->values()
            ->all());

        return $rows;
    }

    /** Resolve `{user}` from a super URL inside the clinic. Soft-deleted accounts are not found: nothing may wake them. */
    public function find(Tenant $tenant, string $publicId): User
    {
        /** @var User|null $user */
        $user = Tenancy::run($tenant, fn (): ?User => User::query()->where('public_id', $publicId)->first());

        return $user ?? throw new StaffUserNotFound($publicId);
    }

    /** @return array<string, mixed> */
    public function row(User $user): array
    {
        $role = $user->getRoleNames()->first();

        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'role' => is_string($role) ? $role : null,
            'roles' => $user->getRoleNames()->values()->all(),
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'branch_name' => $user->defaultBranch?->name,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'last_login_ip' => $user->last_login_ip,
            'created_at' => $user->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
