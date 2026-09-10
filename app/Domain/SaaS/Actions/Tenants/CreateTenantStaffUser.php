<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Actions\CreateStaffUser;
use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\SaaS\Data\CredentialReveal;
use App\Domain\SaaS\Data\TenantStaffData;
use App\Domain\SaaS\Exceptions\StaffEmailTaken;
use App\Domain\SaaS\Queries\TenantStaffDirectory;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\StaffCredentials;
use App\Domain\Shared\Actor;
use App\Models\Central\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;

/**
 * Create a staff account (usually a hospital admin) inside a clinic from the console — through the clinic's own
 * `CreateStaffUser`, so roles, the default branch, the tenant audit row and the `StaffUserCreated` event are
 * exactly what the clinic's Staff screen would have produced. The console only adds the credential hand-off and
 * the central audit row.
 */
final class CreateTenantStaffUser
{
    public function __construct(
        private readonly CreateStaffUser $create,
        private readonly StaffCredentials $credentials,
        private readonly TenantStaffDirectory $directory,
        private readonly CentralAudit $audit,
    ) {}

    /** @return array{user: array<string, mixed>, reveal: CredentialReveal} */
    public function handle(Tenant $tenant, TenantStaffData $data, ?int $superAdminId = null, ?string $ip = null): array
    {
        $password = $data->password ?? $this->credentials->temporaryPassword();
        $actor = new Actor(superAdminId: $superAdminId, ip: $ip, source: 'web');

        /** @var array{user: array<string, mixed>, reveal: CredentialReveal, id: int} $result */
        $result = Tenancy::run($tenant, function () use ($tenant, $data, $password, $actor): array {
            if (User::query()->where('email', $data->email)->exists()) {
                throw new StaffEmailTaken($data->email);
            }

            $branch = Branch::query()->where('is_main', true)->first() ?? Branch::query()->orderBy('id')->first();

            $user = $this->create->handle(new StaffUserData(
                name: $data->name,
                email: $data->email,
                role: $data->role,
                password: $password,
                mobile: $data->mobile,
                defaultBranchId: $branch?->id,
                locale: $data->locale,
                isActive: true,
                mustChangePassword: true,
            ), $actor);

            $user->load(['roles:id,name', 'defaultBranch:id,name']);

            return [
                'user' => $this->directory->row($user),
                'reveal' => $data->password === null ? $this->credentials->setPasswordLink($tenant, $user) : $this->credentials->passwordReveal($user, $password),
                'id' => $user->id,
            ];
        });

        $this->audit->record(CentralAuditAction::Create, $tenant, null, null, [
            'user_id' => $result['id'],
            'user_public_id' => $result['user']['public_id'],
            'email' => $data->email,
            'role' => $data->role->value,
            'credential' => $result['reveal']->kind,
        ], $superAdminId);

        return ['user' => $result['user'], 'reveal' => $result['reveal']];
    }
}
