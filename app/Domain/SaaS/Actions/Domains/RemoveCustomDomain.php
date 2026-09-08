<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Domains;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\DomainType;
use App\Domain\SaaS\Exceptions\DomainNotVerified;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\Domain;
use Illuminate\Support\Facades\DB;

/**
 * Only custom rows can be removed: the `{slug}.{central}` subdomain is how a tenant is reachable at all, and a
 * tenant with no host is a tenant nobody can log in to. Removing the current primary hands the flag back to the
 * platform subdomain in the same transaction.
 */
final class RemoveCustomDomain
{
    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(Domain $domain): void
    {
        if ($domain->type !== DomainType::Custom) {
            throw new DomainNotVerified($domain->domain, 'platform_subdomain');
        }

        $tenant = $domain->tenant;
        $wasPrimary = $domain->is_primary;

        DB::connection('pgsql')->transaction(function () use ($domain, $wasPrimary): void {
            $domain->delete();

            if ($wasPrimary) {
                $fallback = Domain::query()->where('tenant_id', $domain->tenant_id)->where('type', DomainType::Subdomain->value)->first();
                $fallback?->forceFill(['is_primary' => true])->save();
            }
        });

        $this->audit->record(CentralAuditAction::Delete, $tenant, $domain, ['domain' => $domain->domain], null);
    }
}
