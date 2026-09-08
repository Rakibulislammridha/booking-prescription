<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Domains;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\DomainType;
use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\SslStatus;
use App\Domain\SaaS\Exceptions\DomainAlreadyClaimed;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\DomainName;
use App\Domain\SaaS\Services\PlanLimits;
use App\Models\Central\Domain;
use App\Models\Central\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Claim a hostname for a tenant. The row starts `pending`, which means `TenantResolver` ignores it completely
 * (it only ever joins on `verification_status = 'verified'`) — so an unverified claim can never steal traffic,
 * and the verification job is free to be slow, retried, or never to succeed at all.
 *
 * The platform's own names are refused outright: `bp.localhost`, `super.bp.localhost` and every `{slug}.bp.…`
 * subdomain are resolved by slug, not by this table, and a row claiming one would be a live confusion attack.
 */
final class AddCustomDomain
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(Tenant $tenant, string $host, bool $requireFeature = true): Domain
    {
        if ($requireFeature) {
            $this->limits->assertFeature($tenant, PlanFeatureKey::CustomDomain);
        }

        $host = DomainName::normalise($host);

        if (! DomainName::isValid($host) || DomainName::isPlatformHost($host, (string) config('tenancy.central_domain'))) {
            throw new DomainAlreadyClaimed($host);
        }

        if (Domain::query()->where('domain', $host)->exists()) {
            throw new DomainAlreadyClaimed($host);
        }

        try {
            $domain = Domain::query()->create([
                'tenant_id' => $tenant->id,
                'domain' => $host,
                'type' => DomainType::Custom,
                'is_primary' => false,
                'verification_status' => DomainVerificationStatus::Pending,
                'verification_token' => Str::lower(Str::random(40)),
                'ssl_status' => SslStatus::None,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw new DomainAlreadyClaimed($host);
            }

            throw $e;
        }

        $this->audit->record(CentralAuditAction::Create, $tenant, $domain, null, ['domain' => $host, 'type' => 'custom']);

        return $domain;
    }
}
