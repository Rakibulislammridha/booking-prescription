<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Admins;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Exceptions\CannotActOnOwnSuperAccount;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Facades\DB;

/**
 * The "locked out of the authenticator" path (ARCHITECTURE §6.5): a second operator, having re-typed their own
 * password, clears a colleague's enrolment so they can sign in on the password alone and enrol a new phone.
 *
 * It is `two_factor_reset`, not `two_factor_disabled`, in the audit log — the owner did not do this — and it
 * carries what was cleared (enrolled or not, how many recovery codes were left). Every session of the target is
 * ended too: under a `required` policy their next request lands on the enrolment screen anyway, and under any
 * policy a session that passed a challenge against a secret that no longer exists should not outlive it.
 *
 * You cannot reset your own factor here: an operator with their phone in hand disables it from the Security
 * tab with a code, and one without their phone is exactly who this action exists for — a colleague does it.
 */
final class ResetSuperAdminTwoFactor
{
    public function __construct(
        private readonly CentralAudit $audit,
        private readonly SuperTwoFactor $twoFactor,
        private readonly SuperSessionIndex $sessions,
    ) {}

    public function handle(SuperAdmin $admin, SuperAdmin $by): SuperAdmin
    {
        if ($admin->is($by)) {
            throw new CannotActOnOwnSuperAccount;
        }

        return DB::connection('pgsql')->transaction(function () use ($admin, $by): SuperAdmin {
            $before = [
                'two_factor' => $this->twoFactor->enabled($admin) ? 'enabled' : ($this->twoFactor->pendingSecret($admin) !== null ? 'enrolling' : 'none'),
                'recovery_codes' => $this->twoFactor->recoveryCodesRemaining($admin),
            ];

            $admin->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $revoked = $this->sessions->revokeAll($admin);

            $this->audit->record(CentralAuditAction::TwoFactorReset, null, $admin, $before, ['two_factor' => 'none', 'recovery_codes' => 0, 'sessions_revoked' => $revoked], $by->id);

            return $admin;
        });
    }
}
