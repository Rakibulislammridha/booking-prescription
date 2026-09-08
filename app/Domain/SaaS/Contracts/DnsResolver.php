<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Contracts;

/**
 * The one seam between domain verification and the network.
 *
 * Everything about verification — apex vs subdomain, which names to try, what a wrong token means, when to
 * re-check — is decided in `App\Domain\SaaS\Services\DomainVerifier`, which owns no sockets. This interface is the
 * only thing that talks to a resolver, so the test suite swaps in `FakeDnsResolver` and never touches the network
 * (a DNS lookup in a test is a flake and a 5-second timeout waiting to happen).
 */
interface DnsResolver
{
    /**
     * Every TXT record published at `$host`, unquoted and concatenated per record.
     *
     * @return array<int, string> empty when the name does not exist or publishes no TXT
     */
    public function txt(string $host): array;
}
