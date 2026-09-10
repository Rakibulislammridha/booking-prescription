<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Services\StaffSessionIndex;
use App\Domain\SaaS\Data\CredentialReveal;
use App\Domain\SaaS\Queries\TenantStaffDirectory;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\StaffCredentials;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;

/**
 * Support's "they are locked out" button. Two modes:
 *
 *   password — a temporary password is set, shown to the operator ONCE, and every session and remember-token of
 *              the account is revoked (`StaffSessionIndex::revokeAll`): the credential changed, so whoever holds
 *              the old one is out;
 *   link     — a set-password link on the clinic's host, e-mailed and shown once. Nothing changes until the user
 *              follows it, so live sessions are left alone.
 *
 * Both set `must_change_password`. The secret itself is never audited — the row says who reset whom and how.
 */
final class ResetTenantStaffPassword
{
    public function __construct(
        private readonly TenantStaffDirectory $directory,
        private readonly StaffCredentials $credentials,
        private readonly StaffSessionIndex $sessions,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(Tenant $tenant, string $userPublicId, string $mode, ?int $superAdminId = null): CredentialReveal
    {
        $user = $this->directory->find($tenant, $userPublicId);

        /** @var CredentialReveal $reveal */
        $reveal = Tenancy::run($tenant, function () use ($tenant, $user, $mode): CredentialReveal {
            /** @var User $fresh */
            $fresh = User::query()->findOrFail($user->id);

            if ($mode === CredentialReveal::KIND_LINK) {
                $fresh->forceFill(['must_change_password' => true])->save();

                return $this->credentials->setPasswordLink($tenant, $fresh);
            }

            $password = $this->credentials->temporaryPassword();
            $fresh->forceFill(['password' => $password, 'must_change_password' => true])->save();
            $this->sessions->revokeAll($fresh);

            return $this->credentials->passwordReveal($fresh, $password);
        });

        $this->audit->record(CentralAuditAction::Update, $tenant, null, null, [
            'user_id' => $user->id,
            'user_public_id' => $user->public_id,
            'email' => $user->email,
            'password_reset' => $reveal->kind,
            'sessions_revoked' => $reveal->kind === CredentialReveal::KIND_PASSWORD,
        ], $superAdminId);

        return $reveal;
    }
}
