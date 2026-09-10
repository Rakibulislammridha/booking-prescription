<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Profile;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Actions\Admins\SendSuperSetPasswordLink;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Models\Central\SuperAdmin;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * The set-password link's landing: the `super_admins` broker checks the token, this sets the password. Every
 * session the account holds is ended and the remember token rotated, because whoever held the old password may
 * still be holding a console. The audit row names the account itself as the actor — nobody is signed in.
 *
 * @phpstan-type Credentials array{token: string, email: string, password: string, password_confirmation: string}
 */
final class CompleteSuperPasswordReset
{
    public function __construct(
        private readonly CentralAudit $audit,
        private readonly SuperSessionIndex $sessions,
    ) {}

    /**
     * @param  Credentials  $credentials
     * @return string one of the `Password::*` status constants
     */
    public function handle(#[SensitiveParameter] array $credentials): string
    {
        return (string) Password::broker(SendSuperSetPasswordLink::BROKER)->reset(
            $credentials,
            function (SuperAdmin $admin, #[SensitiveParameter] string $password): void {
                $admin->forceFill(['password' => $password])->save();
                $revoked = $this->sessions->revokeAll($admin);

                $this->audit->record(CentralAuditAction::PasswordChange, null, $admin, null, ['by' => 'link', 'sessions_revoked' => $revoked], $admin->id);

                event(new PasswordReset($admin));
            },
        );
    }
}
