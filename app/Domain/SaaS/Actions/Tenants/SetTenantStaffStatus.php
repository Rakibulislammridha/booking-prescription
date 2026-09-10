<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Actions\UpdateStaffUser;
use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Queries\TenantStaffDirectory;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\Shared\Actor;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;

/**
 * Deactivate or reactivate a clinic user from the console — through the clinic's own `UpdateStaffUser`, whose
 * deactivation path is the one that ends every live session and rotates the remember-token
 * (`StaffSessionIndex::revokeAll`, BRIEF §5.N). An account switched off by support must not stay signed in at a
 * desk any more than one switched off by the clinic.
 */
final class SetTenantStaffStatus
{
    public function __construct(
        private readonly UpdateStaffUser $update,
        private readonly TenantStaffDirectory $directory,
        private readonly CentralAudit $audit,
    ) {}

    /** @return array<string, mixed> the user's row after the change */
    public function handle(Tenant $tenant, string $userPublicId, bool $active, ?int $superAdminId = null, ?string $ip = null): array
    {
        $user = $this->directory->find($tenant, $userPublicId);
        $was = $user->is_active;
        $actor = new Actor(superAdminId: $superAdminId, ip: $ip, source: 'web');

        /** @var array<string, mixed> $row */
        $row = Tenancy::run($tenant, function () use ($user, $active, $actor): array {
            /** @var User $fresh */
            $fresh = User::query()->findOrFail($user->id);
            $role = $fresh->getRoleNames()->first();

            $updated = $this->update->handle($fresh, new StaffUserData(
                name: $fresh->name,
                email: $fresh->email,
                role: Role::from(is_string($role) ? $role : Role::Receptionist->value),
                password: null,
                mobile: $fresh->mobile,
                defaultBranchId: $fresh->default_branch_id,
                locale: $fresh->locale->value,
                isActive: $active,
                mustChangePassword: $fresh->must_change_password,
                sessionTimeoutMinutes: $fresh->session_timeout_minutes,
            ), $actor);

            $updated->load(['roles:id,name', 'defaultBranch:id,name']);

            return $this->directory->row($updated);
        });

        if ($was !== $active) {
            $this->audit->record(
                CentralAuditAction::Update,
                $tenant,
                null,
                ['user_id' => $user->id, 'is_active' => $was],
                ['user_id' => $user->id, 'user_public_id' => $user->public_id, 'email' => $user->email, 'is_active' => $active, 'sessions_revoked' => ! $active],
                $superAdminId,
            );
        }

        return $row;
    }
}
