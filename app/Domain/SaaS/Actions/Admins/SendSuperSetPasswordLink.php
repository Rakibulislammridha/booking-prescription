<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Admins;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Notifications\SuperSetPasswordLink;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\SuperAdmin;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;
use LogicException;

/**
 * Mint a set-password token through the `super_admins` broker and mail the link. Used for a new account created
 * without a password and for an existing operator who lost theirs; either way it is audited on the target
 * account, because "who sent a password link to whom" is exactly what an account-takeover review asks.
 */
final class SendSuperSetPasswordLink
{
    public const BROKER = 'super_admins';

    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(SuperAdmin $admin, ?SuperAdmin $by = null): void
    {
        $broker = Password::broker(self::BROKER);

        if (! $broker instanceof PasswordBroker) {
            throw new LogicException('the super_admins password broker is not the framework broker');
        }

        $expires = (int) config('auth.passwords.'.self::BROKER.'.expire', 60);
        $token = $broker->createToken($admin);

        $admin->notify(new SuperSetPasswordLink($token, $expires));

        $this->audit->record(
            CentralAuditAction::Update,
            null,
            $admin,
            null,
            ['set_password_link' => 'sent', 'expires_minutes' => $expires],
            $by !== null ? $by->id : $this->audit->currentSuperAdminId(),
        );
    }
}
