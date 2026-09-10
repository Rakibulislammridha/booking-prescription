<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Data\CredentialReveal;
use App\Domain\SaaS\Jobs\SendStaffSetPasswordLinkJob;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The two ways the console hands a clinic user a way in: a temporary password shown to the operator once, or a
 * set-password link on the clinic's host — minted through the same `users` broker and `password_reset_tokens`
 * table the panel's own "forgot password" uses, so the link is consumed by `Panel\Auth\NewPasswordController`
 * with no second code path. Both leave `must_change_password` on.
 *
 * Every method here must run INSIDE `Tenancy::run()` for the clinic in question: `users` and
 * `password_reset_tokens` are tenant tables, and the broker resolves them by search path.
 */
final class StaffCredentials
{
    public function __construct(private readonly TenantLinks $links) {}

    public function temporaryPassword(): string
    {
        // Letters and digits only: this is read aloud over the phone and typed on a clinic keyboard.
        return Str::password(14, symbols: false);
    }

    public function passwordReveal(User $user, string $password): CredentialReveal
    {
        return new CredentialReveal(CredentialReveal::KIND_PASSWORD, $password, $user->name, $user->email);
    }

    /**
     * Mint a reset token for the user, queue the e-mail carrying the link on the clinic's host, and return the same
     * link for the operator. The mail is best effort (`SendStaffSetPasswordLinkJob`) — the operator has the link on
     * screen whether or not the clinic's mail arrives, and a slow relay never holds the console request.
     */
    public function setPasswordLink(Tenant $tenant, User $user): CredentialReveal
    {
        $this->assertInside($tenant);

        /** @var PasswordBroker $broker */
        $broker = Password::broker('users');
        $token = $broker->createToken($user);
        $expires = CarbonImmutable::now()->addMinutes((int) config('auth.passwords.users.expire', 60));
        $url = $this->links->panel($tenant, '/panel/reset-password/'.$token.'?email='.urlencode($user->email));

        SendStaffSetPasswordLinkJob::dispatch(
            $tenant->id,
            $tenant->name,
            $user->email,
            $user->name,
            $user->locale->value,
            $url,
            (int) config('auth.passwords.users.expire', 60),
            app(CentralAudit::class)->currentSuperAdminId(),
        );

        return new CredentialReveal(CredentialReveal::KIND_LINK, $url, $user->name, $user->email, $expires->toIso8601String());
    }

    private function assertInside(Tenant $tenant): void
    {
        if (Tenancy::id() !== $tenant->id) {
            throw new \LogicException('StaffCredentials must be used inside Tenancy::run() for the tenant concerned.');
        }
    }
}
