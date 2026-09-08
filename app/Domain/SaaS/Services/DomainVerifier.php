<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Contracts\DnsResolver;
use App\Models\Central\Domain;

/**
 * Decides whether a custom domain's TXT proof is published, and nothing else. It owns no sockets (that is
 * `DnsResolver`) and writes no rows (that is `VerifyCustomDomain`), which is what makes it a pure unit test.
 */
final class DomainVerifier
{
    public function __construct(private readonly DnsResolver $dns) {}

    public function check(Domain $domain): DomainVerificationResult
    {
        $host = $domain->domain;
        $expected = DomainName::expectedTxt($domain->verification_token);
        $seen = [];

        foreach (DomainName::txtCandidates($host) as $candidate) {
            foreach ($this->dns->txt($candidate) as $record) {
                $record = trim($record, " \t\n\r\0\x0B\"");
                $seen[] = $record;

                if (hash_equals($expected, $record)) {
                    return new DomainVerificationResult(true, $candidate, 'ok', $seen);
                }
            }
        }

        $ours = array_values(array_filter($seen, fn (string $r) => str_starts_with($r, DomainName::TXT_PREFIX)));

        return new DomainVerificationResult(
            verified: false,
            checkedName: DomainName::txtCandidates($host)[0],
            reason: $seen === [] ? 'no_txt_record' : ($ours === [] ? 'no_verification_record' : 'token_mismatch'),
            records: $seen,
        );
    }

    /**
     * What the customer has to create, in the words their DNS panel uses. An apex cannot CNAME, so it gets an A
     * record; a subdomain gets a CNAME so the platform can move hosts without the clinic touching DNS again.
     *
     * @return array<string, string>
     */
    public static function instructions(Domain $domain, string $centralDomain, ?string $platformIp = null): array
    {
        $apex = DomainName::isApex($domain->domain);

        return [
            'txt_name' => DomainName::TXT_LABEL.'.'.$domain->domain,
            'txt_value' => DomainName::expectedTxt($domain->verification_token),
            'record_kind' => $apex ? 'A' : 'CNAME',
            'record_name' => $apex ? '@' : explode('.', $domain->domain)[0],
            'record_value' => $apex ? ($platformIp ?? '') : $centralDomain,
            'is_apex' => $apex ? '1' : '0',
        ];
    }
}
