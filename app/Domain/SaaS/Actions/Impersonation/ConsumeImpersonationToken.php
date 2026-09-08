<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Impersonation;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Events\ImpersonationStarted;
use App\Domain\SaaS\Exceptions\ImpersonationTokenInvalid;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\ImpersonationToken;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Spend the token, once.
 *
 * The claim is ONE conditional UPDATE:
 *
 *     update public.impersonation_tokens set consumed_at = now()
 *      where token_hash = ? and tenant_id = ? and consumed_at is null and expires_at > now()
 *
 * and the caller is only let in when it affected exactly one row. That is what makes the token single-use under
 * concurrency: two browsers replaying the same URL at the same instant both run this statement, Postgres
 * serialises them on the row, and the second one updates nothing and is refused. A check-then-write would let
 * both in.
 *
 * The tenant is passed in from the RESOLVED HOST, so a token minted for clinic A cannot be spent on clinic B even
 * if the URL is edited — the `tenant_id` predicate is part of the claim, not a check afterwards.
 */
final class ConsumeImpersonationToken
{
    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(string $plainToken, Tenant $tenant): User
    {
        $hash = hash('sha256', $plainToken);
        $now = CarbonImmutable::now();

        $claimed = DB::connection('pgsql')->table('public.impersonation_tokens')
            ->where('token_hash', $hash)
            ->where('tenant_id', $tenant->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $now)
            ->update(['consumed_at' => $now]);

        if ($claimed !== 1) {
            throw new ImpersonationTokenInvalid('unknown_expired_or_consumed');
        }

        $token = ImpersonationToken::query()->where('token_hash', $hash)->firstOrFail();

        // Tenancy is already initialised: this runs on the tenant's own host, inside the panel route group.
        $user = User::query()->where('is_active', true)->find($token->user_id);

        if ($user === null) {
            throw new ImpersonationTokenInvalid('target_user_gone');
        }

        $this->audit->record(
            CentralAuditAction::Impersonate,
            $tenant,
            $token,
            null,
            ['user_id' => $user->id, 'user_name' => $user->name],
            $token->super_admin_id,
        );

        ImpersonationStarted::dispatch($tenant->id, $token->super_admin_id, $user->id);

        return $user;
    }
}
