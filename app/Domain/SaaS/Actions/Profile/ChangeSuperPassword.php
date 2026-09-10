<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Profile;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * A new password for the operator's own account (the current one was re-checked by the form request). The
 * remember token is rotated and every OTHER session is ended: a password is changed because the old one may be
 * known to someone else, and that someone may be signed in right now. The session doing the changing stays.
 */
final class ChangeSuperPassword
{
    public function __construct(
        private readonly CentralAudit $audit,
        private readonly SuperSessionIndex $sessions,
    ) {}

    public function handle(SuperAdmin $admin, #[SensitiveParameter] string $password, ?string $keepSessionId): int
    {
        return DB::connection('pgsql')->transaction(function () use ($admin, $password, $keepSessionId): int {
            $admin->forceFill(['password' => $password])->save();

            $revoked = $this->sessions->revokeOthers($admin, $keepSessionId ?? '');
            $this->sessions->rotateRememberToken($admin);

            $this->audit->record(CentralAuditAction::PasswordChange, null, $admin, null, ['by' => 'self', 'other_sessions_revoked' => $revoked], $admin->id);

            return $revoked;
        });
    }
}
