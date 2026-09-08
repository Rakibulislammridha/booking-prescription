<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Domains;

use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Events\CustomDomainVerified;
use App\Domain\SaaS\Services\DomainVerificationResult;
use App\Domain\SaaS\Services\DomainVerifier;
use App\Models\Central\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Run the DNS check and write the outcome. Idempotent, and safe to call from a job, a console sweep, or a
 * "check now" button — re-verification is simply calling it again.
 *
 * `$demoteOnFailure` is the whole re-verification policy in one flag:
 *   · a customer pressing "check now", or a super admin re-verifying, passes TRUE — they asked for the truth;
 *   · the scheduled sweep passes FALSE for rows that are already `verified`, so a transient DNS outage, a
 *     registrar's maintenance window or our own resolver failing cannot take a live clinic's booking site down.
 *     The sweep still records `last_checked_at` and logs, and the super console shows the stale check.
 *
 * `Domain::booted()` flushes the `tenancy:host:{host}` resolver cache on save, so a domain becomes live on the
 * next request without a deploy.
 */
final class VerifyCustomDomain
{
    public function __construct(private readonly DomainVerifier $verifier) {}

    public function handle(Domain $domain, bool $demoteOnFailure = true): DomainVerificationResult
    {
        $result = $this->verifier->check($domain);
        $now = CarbonImmutable::now();
        $wasVerified = $domain->verification_status === DomainVerificationStatus::Verified;

        if ($result->verified) {
            $domain->forceFill([
                'verification_status' => DomainVerificationStatus::Verified,
                'verified_at' => $domain->getAttribute('verified_at') ?? $now,
                'last_checked_at' => $now,
            ])->save();

            if (! $wasVerified) {
                CustomDomainVerified::dispatch($domain->tenant_id, $domain->id, $domain->domain);
            }

            return $result;
        }

        if ($wasVerified && ! $demoteOnFailure) {
            $domain->forceFill(['last_checked_at' => $now])->save();
            Log::warning('saas.domain.reverify_failed', ['domain' => $domain->domain, 'reason' => $result->reason, 'tenant_id' => $domain->tenant_id]);

            return $result;
        }

        $domain->forceFill([
            'verification_status' => DomainVerificationStatus::Failed,
            'verified_at' => null,
            'last_checked_at' => $now,
        ])->save();

        return $result;
    }
}
