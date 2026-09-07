<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Tenancy\TenantResolver;
use PHPUnit\Framework\TestCase;

final class TenantResolverTest extends TestCase
{
    public function test_hosts_are_lower_cased_and_stripped_of_ports(): void
    {
        $this->assertSame('demo.bp.localhost', TenantResolver::normalise('Demo.BP.localhost:8000'));
        $this->assertSame('tenancy:host:demo.bp.localhost', TenantResolver::cacheKey('demo.bp.localhost'));
    }
}
