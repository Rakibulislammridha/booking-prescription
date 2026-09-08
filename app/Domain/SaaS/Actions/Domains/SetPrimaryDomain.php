<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Domains;

use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Exceptions\DomainNotVerified;
use App\Models\Central\Domain;
use Illuminate\Support\Facades\DB;

/**
 * The primary domain is the host that goes into SMS links, prescription QR codes and printed slips, so only a
 * VERIFIED row may become it — an unverified primary would print an address that does not resolve.
 * `domains_tenant_id_uniq_p` allows exactly one primary per tenant, so the demote and the promote are one
 * transaction.
 */
final class SetPrimaryDomain
{
    public function handle(Domain $domain): Domain
    {
        if ($domain->verification_status !== DomainVerificationStatus::Verified) {
            throw new DomainNotVerified($domain->domain, 'not_verified');
        }

        DB::connection('pgsql')->transaction(function () use ($domain): void {
            Domain::query()->where('tenant_id', $domain->tenant_id)->where('is_primary', true)->whereKeyNot($domain->id)
                ->get()->each(fn (Domain $other) => $other->forceFill(['is_primary' => false])->save());

            $domain->forceFill(['is_primary' => true])->save();
        });

        return $domain;
    }
}
