<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Contracts\DnsResolver;

/**
 * `dns_get_record()` over the host's resolver. Bound in production and local development only; the suite binds
 * `Tests\Support\FakeDnsResolver` instead, so no test can reach a network.
 *
 * PHP splits a TXT record longer than 255 bytes into `entries`; both spellings are joined so a long token is not
 * silently truncated into a mismatch.
 */
final class SystemDnsResolver implements DnsResolver
{
    /** @return array<int, string> */
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        if ($records === false) {
            return [];
        }

        $out = [];

        foreach ($records as $record) {
            $entries = $record['entries'] ?? null;

            if (is_array($entries) && $entries !== []) {
                $out[] = implode('', array_map(fn ($e): string => (string) $e, $entries));

                continue;
            }

            if (isset($record['txt']) && is_string($record['txt'])) {
                $out[] = $record['txt'];
            }
        }

        return $out;
    }
}
