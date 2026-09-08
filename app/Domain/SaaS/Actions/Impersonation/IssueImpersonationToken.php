<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Impersonation;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Data\ImpersonationTicket;
use App\Domain\SaaS\Exceptions\ImpersonationTokenInvalid;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\ImpersonationToken;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Mint a single-use, 60-second handoff (ARCHITECTURE §6.5, SCHEMA §2.17).
 *
 * Design notes that matter more than the code:
 *  · the token is 64 random characters and only its sha256 is stored, so the table is not a credential store;
 *  · 60 seconds is a redirect, not a session — a token copied out of a browser history is already dead;
 *  · the target user is resolved INSIDE the tenant (`Tenancy::run`), because `users.id` is per schema and a
 *    super admin naming "user 1" must mean this clinic's user 1 and no one else's;
 *  · inactive and soft-deleted users are refused: impersonation must not be a way to wake a disabled account;
 *  · minting is audited even when the token is never used, so an attempt that goes nowhere still leaves a trace.
 */
final class IssueImpersonationToken
{
    public const TTL_SECONDS = 60;

    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(SuperAdmin $admin, Tenant $tenant, ?int $userId = null, ?string $ip = null, string $scheme = 'https'): ImpersonationTicket
    {
        /** @var array{id: int, name: string}|null $target */
        $target = Tenancy::run($tenant, function () use ($userId): ?array {
            $query = User::query()->where('is_active', true);

            $user = $userId === null
                ? $query->whereHas('roles', fn ($r) => $r->where('name', Role::HospitalAdmin->value))->orderBy('id')->first()
                : $query->whereKey($userId)->first();

            return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
        });

        if ($target === null) {
            throw new ImpersonationTokenInvalid('no_target_user');
        }

        $plain = Str::random(64);
        $now = CarbonImmutable::now();

        $token = ImpersonationToken::query()->create([
            'super_admin_id' => $admin->id,
            'tenant_id' => $tenant->id,
            'user_id' => $target['id'],
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $now->addSeconds(self::TTL_SECONDS),
            'ip' => $ip,
        ]);

        $this->audit->record(
            CentralAuditAction::Create,
            $tenant,
            $token,
            null,
            ['user_id' => $target['id'], 'expires_at' => $token->expires_at->toIso8601String()],
            $admin->id,
        );

        $host = $this->hostFor($tenant);

        return new ImpersonationTicket(
            tokenId: $token->id,
            url: $scheme.'://'.$host.'/panel/impersonate/'.$plain,
            expiresAt: $token->expires_at,
            userId: $target['id'],
            userName: $target['name'],
            tenantName: $tenant->name,
        );
    }

    /** Always the platform subdomain: a custom domain may be mid-verification or pointed elsewhere today. */
    private function hostFor(Tenant $tenant): string
    {
        return $tenant->primaryHost();
    }
}
