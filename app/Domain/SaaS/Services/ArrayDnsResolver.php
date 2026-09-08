<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Contracts\DnsResolver;

/**
 * An in-memory resolver. Bound as a singleton in the `testing` environment so that NO test can reach a real
 * resolver: a suite that does DNS is a suite that fails on a train, and a five-second lookup timeout in a
 * feature test is indistinguishable from a deadlock.
 *
 * It is production code, not a test double in disguise: it is also the right implementation for a staging box
 * with no outbound DNS, and it keeps the seam honest by being the only other `DnsResolver` in the tree.
 */
final class ArrayDnsResolver implements DnsResolver
{
    /** @var array<string, array<int, string>> */
    private array $records = [];

    /** @param  array<int, string>|string  $values */
    public function set(string $host, array|string $values): self
    {
        $this->records[mb_strtolower($host)] = is_string($values) ? [$values] : array_values($values);

        return $this;
    }

    public function forget(string $host): self
    {
        unset($this->records[mb_strtolower($host)]);

        return $this;
    }

    public function flush(): self
    {
        $this->records = [];

        return $this;
    }

    /** @return array<int, string> */
    public function txt(string $host): array
    {
        return $this->records[mb_strtolower($host)] ?? [];
    }
}
