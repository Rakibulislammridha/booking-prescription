<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\SaaS\Enums\TenantStatus;
use App\Models\Central\Domain;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MiddlewareTest extends TestCase
{
    public function test_ping_resolves_the_tenant_from_the_host_and_is_never_cached(): void
    {
        $this->asCentral();
        Tenancy::end();

        $response = $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test'])->getJson('/api/ping')->assertOk();

        $response->assertJsonPath('tenant', $this->tenant('a')->public_id)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertIsInt($response->json('t'));
        $this->assertSame(9001, Tenancy::id());
        $this->assertNotNull($response->headers->get('X-Request-Id'));
    }

    public function test_service_prefixes_resolve_the_same_tenant(): void
    {
        $this->withServerVariables(['HTTP_HOST' => 'queue.test-b.bp.test'])->getJson('/api/ping')->assertOk()->assertJsonPath('tenant', $this->tenant('b')->public_id);
    }

    public function test_unknown_hosts_get_404_on_tenant_surfaces(): void
    {
        $this->withServerVariables(['HTTP_HOST' => 'nobody.bp.test'])->getJson('/api/ping')->assertNotFound();
        $this->withServerVariables(['HTTP_HOST' => 'bp.test'])->getJson('/api/ping')->assertNotFound();
        $this->assertFalse(Tenancy::check());
    }

    public function test_verified_custom_domains_resolve_and_are_cached(): void
    {
        Domain::factory()->for($this->tenant('a'), 'tenant')->verified()->create(['domain' => 'clinic-a.example.org']);
        Domain::factory()->for($this->tenant('b'), 'tenant')->create(['domain' => 'pending-b.example.org']);

        $this->withServerVariables(['HTTP_HOST' => 'clinic-a.example.org'])->getJson('/api/ping')->assertOk()->assertJsonPath('tenant', $this->tenant('a')->public_id);
        $this->assertSame(9001, Cache::get(TenantResolver::cacheKey('clinic-a.example.org')));

        $this->withServerVariables(['HTTP_HOST' => 'pending-b.example.org'])->getJson('/api/ping')->assertNotFound();
    }

    public function test_suspended_tenants_get_402_and_cancelled_tenants_404(): void
    {
        $tenant = $this->tenant('a');
        $tenant->forceFill(['status' => TenantStatus::Suspended])->save();
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test'])->getJson('/api/ping')->assertStatus(402)->assertJsonPath('code', 'tenancy.suspended');

        $tenant->forceFill(['status' => TenantStatus::Cancelled])->save();
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test'])->getJson('/api/ping')->assertNotFound();

        $tenant->forceFill(['status' => TenantStatus::PastDue])->save();
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test'])->getJson('/api/ping')->assertOk();
    }

    public function test_tenant_locale_is_applied(): void
    {
        $this->tenant('b')->forceFill(['locale' => 'en'])->save();

        $this->withServerVariables(['HTTP_HOST' => 'test-b.bp.test'])->getJson('/api/ping')->assertOk();
        $this->assertSame('en', app()->getLocale());
    }
}
