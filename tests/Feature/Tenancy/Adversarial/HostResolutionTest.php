<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial;

use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\Tenancy\Actions\ProvisionTenant;
use App\Domain\Tenancy\Data\ProvisionTenantData;
use App\Models\Central\Domain;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

/** Attack surface 4: host → tenant resolution. */
final class HostResolutionTest extends TestCase
{
    /**
     * EXPECTED TO FAIL until fixed (low, deployment-dependent): bootstrap/app.php trusts every proxy (`at: '*'`) with
     * the default header set, which includes X-Forwarded-Host. Under Octane/FrankenPHP there is no proxy hop that
     * strips client headers, so any client can pick the tenant with a header while the browser still sends the cookies
     * of the real host (this is why Laravel itself strips X-Forwarded-Host on Forge/Vapor).
     * Fix: trust only the real proxy IPs, or trustProxies(headers: FOR|PORT|PROTO) without HOST.
     */
    public function test_x_forwarded_host_from_an_untrusted_client_cannot_switch_the_tenant(): void
    {
        $response = $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test', 'HTTP_X_FORWARDED_HOST' => 'test-b.bp.test', 'REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/ping')->assertOk();

        $this->assertSame($this->tenant('a')->public_id, $response->json('tenant'), 'X-Forwarded-Host from the client switched the request to tenant B');
        $this->assertSame(9001, Tenancy::id());
    }

    /** Guarantee: case and port are normalised before lookup and caching. */
    public function test_host_case_and_port_are_normalised_and_cached_under_one_key(): void
    {
        $this->withServerVariables(['HTTP_HOST' => 'TEST-A.BP.TEST:8443'])->getJson('/api/ping')->assertOk()->assertJsonPath('tenant', $this->tenant('a')->public_id);
        $this->assertSame(9001, Cache::get(TenantResolver::cacheKey('test-a.bp.test')));
        $this->assertNull(Cache::get(TenantResolver::cacheKey('test-a.bp.test:8443')));
        $this->assertNull(Cache::get(TenantResolver::cacheKey('TEST-A.BP.TEST')));
    }

    /** Guarantee: unknown hosts are 404 on every tenant surface, never 500, and leave no tenancy behind. */
    public function test_unknown_hosts_get_404_on_every_tenant_surface_and_leave_no_tenancy(): void
    {
        foreach (['nobody.bp.test', '203.0.113.7', 'localhost', 'bp.test.evil.example', 'a.b.bp.test', 'queue.nobody.bp.test'] as $host) {
            $this->withServerVariables(['HTTP_HOST' => $host])->get('/panel/login')->assertNotFound();
            $this->withServerVariables(['HTTP_HOST' => $host])->getJson('/api/ping')->assertNotFound();
        }

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    /** Guarantee: a cached host stops resolving as soon as the tenant is soft-deleted. */
    public function test_soft_deleted_tenants_are_not_resolved_even_when_the_host_is_cached(): void
    {
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test'])->getJson('/api/ping')->assertOk();
        $this->assertSame(9001, Cache::get(TenantResolver::cacheKey('test-a.bp.test')));

        Tenancy::end();
        $this->tenant('a')->delete();

        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test'])->getJson('/api/ping')->assertNotFound();
        $this->assertFalse(Tenancy::check());
    }

    /** Guarantee: un-verifying or deleting a custom domain through the model forgets the resolver cache immediately. */
    public function test_unverifying_or_deleting_a_domain_forgets_the_cache(): void
    {
        $domain = Domain::factory()->for($this->tenant('a'), 'tenant')->verified()->create(['domain' => 'clinic-a.example.org']);

        $this->withServerVariables(['HTTP_HOST' => 'clinic-a.example.org'])->getJson('/api/ping')->assertOk();
        $this->assertSame(9001, Cache::get(TenantResolver::cacheKey('clinic-a.example.org')));

        Tenancy::end();
        $domain->update(['verification_status' => DomainVerificationStatus::Pending]);
        $this->assertNull(Cache::get(TenantResolver::cacheKey('clinic-a.example.org')));
        $this->withServerVariables(['HTTP_HOST' => 'clinic-a.example.org'])->getJson('/api/ping')->assertNotFound();

        $domain->update(['verification_status' => DomainVerificationStatus::Verified]);
        $this->withServerVariables(['HTTP_HOST' => 'clinic-a.example.org'])->getJson('/api/ping')->assertOk();
        Tenancy::end();
        $domain->delete();
        $this->assertNull(Cache::get(TenantResolver::cacheKey('clinic-a.example.org')));
        $this->withServerVariables(['HTTP_HOST' => 'clinic-a.example.org'])->getJson('/api/ping')->assertNotFound();
    }

    /**
     * EXPECTED TO FAIL until fixed (low): nothing reserves the central labels (super, www) or the service prefixes
     * (queue, book, display) as slugs. A tenant with slug `super` gets a verified public.domains row for
     * super.{central}, is unreachable at its primary host, yet is served at book.super.{central}; slug `queue` is
     * unreachable everywhere. Fix: reject reserved slugs in ProvisionTenant / the onboarding validation.
     */
    public function test_reserved_labels_cannot_be_tenant_slugs(): void
    {
        $refused = [];

        foreach (['super', 'www', 'queue'] as $i => $slug) {
            try {
                app(ProvisionTenant::class)->handle(ProvisionTenantData::forTests(9600 + $i, $slug, 'tenant_adv_'.$slug));
            } catch (Throwable) {
                $refused[] = $slug;
            }
        }

        $this->assertSame(['super', 'www', 'queue'], $refused, 'provisioning accepted reserved slug(s): '.implode(', ', array_diff(['super', 'www', 'queue'], $refused)));
        $this->assertFalse(Tenant::query()->where('slug', 'super')->exists());
    }
}
