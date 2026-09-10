<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Contracts\DnsResolver;
use App\Domain\SaaS\Enums\DomainType;
use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Services\ArrayDnsResolver;
use App\Domain\SaaS\Services\DomainName;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Domain;
use App\Tenancy\TenantResolver;
use Tests\TestCase;

/**
 * The Domains tab of the console: add, show the TXT proof, "verify now" against the FAKED resolver, make primary,
 * remove — and every one of those leaves an `audit_logs_central` row, because a hostname change is how a clinic's
 * booking site moves.
 */
final class TenantDomainsConsoleTest extends TestCase
{
    private ArrayDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = app(ArrayDnsResolver::class);
        $this->dns->flush();
        $this->assertInstanceOf(ArrayDnsResolver::class, app(DnsResolver::class), 'the suite must never use the system resolver');
    }

    public function test_add_verify_make_primary_and_remove_a_domain_from_the_console(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $show = route('super.tenants.show', ['tenant' => $tenant->public_id], false);

        $this->post(route('super.tenants.domains.store', ['tenant' => $tenant->public_id], false), ['domain' => 'Care.Sunrise-Hospital.com'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $domain = Domain::query()->where('tenant_id', $tenant->id)->where('type', DomainType::Custom->value)->firstOrFail();
        $this->assertSame('care.sunrise-hospital.com', $domain->domain);
        $this->assertSame(DomainVerificationStatus::Pending, $domain->verification_status);
        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Create->value)->where('auditable_type', $domain->getMorphClass())->where('auditable_id', $domain->id)->exists());

        // The page shows the token flow: the TXT name and value, and the SSL column.
        $this->get($show)->assertOk()->assertInertia(fn ($page) => $page
            ->where('domains.1.domain', 'care.sunrise-hospital.com')
            ->where('domains.1.verification_status', 'pending')
            ->where('domains.1.ssl_status', 'none')
            ->where('domains.1.instructions.txt_name', '_bp-verify.care.sunrise-hospital.com')
            ->where('domains.1.instructions.txt_value', DomainName::expectedTxt($domain->verification_token))
            ->where('domains.1.instructions.record_kind', 'CNAME'));

        // "Verify now" with nothing published: failed, audited, and the resolver still routes nothing.
        $verify = route('super.tenants.domains.verify', ['tenant' => $tenant->public_id, 'domain' => $domain->id], false);
        $this->post($verify)->assertRedirect()->assertSessionHas('flash.warning');
        $this->assertSame(DomainVerificationStatus::Failed, $domain->refresh()->verification_status);
        $unrouted = app(TenantResolver::class)->resolve('care.sunrise-hospital.com');
        $this->assertNull($unrouted);
        $failed = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Update->value)->where('auditable_id', $domain->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $failed->after['verification_status'] ?? null);
        $this->assertSame('no_txt_record', $failed->after['reason'] ?? null);

        // Publish the proof (in the fake), verify again: verified, audited, routed.
        $this->dns->set('_bp-verify.care.sunrise-hospital.com', DomainName::expectedTxt($domain->verification_token));
        $this->post($verify)->assertRedirect()->assertSessionHas('flash.success');
        $this->assertSame(DomainVerificationStatus::Verified, $domain->refresh()->verification_status);
        $routed = app(TenantResolver::class)->resolve('care.sunrise-hospital.com');
        $this->assertSame($tenant->id, $routed?->id);
        $verified = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Update->value)->where('auditable_id', $domain->id)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $verified->super_admin_id);
        $this->assertSame('verified', $verified->after['verification_status'] ?? null);
        $this->assertSame('failed', $verified->before['verification_status'] ?? null);

        // Primary: the subdomain hands over the flag; audited with the previous primary.
        $this->post(route('super.tenants.domains.primary', ['tenant' => $tenant->public_id, 'domain' => $domain->id], false))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($domain->refresh()->is_primary);
        $this->assertFalse((bool) Domain::query()->where('tenant_id', $tenant->id)->where('type', DomainType::Subdomain->value)->value('is_primary'));
        $primary = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Update->value)->where('auditable_id', $domain->id)->latest('id')->firstOrFail();
        $this->assertSame('test-a.bp.test', $primary->before['primary'] ?? null);
        $this->assertSame('care.sunrise-hospital.com', $primary->after['primary'] ?? null);

        // Remove: the flag falls back to the platform subdomain and the host stops routing.
        $this->delete(route('super.tenants.domains.destroy', ['tenant' => $tenant->public_id, 'domain' => $domain->id], false))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Domain::query()->find($domain->id));
        $this->assertTrue((bool) Domain::query()->where('tenant_id', $tenant->id)->where('type', DomainType::Subdomain->value)->value('is_primary'));
        $released = app(TenantResolver::class)->resolve('care.sunrise-hospital.com');
        $this->assertNull($released);
        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Delete->value)->where('auditable_id', $domain->id)->exists());
    }

    public function test_the_platform_subdomain_cannot_be_removed_claimed_or_verified_under_another_clinic(): void
    {
        $this->actingAsSuper();
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $subdomain = Domain::query()->where('tenant_id', $a->id)->where('type', DomainType::Subdomain->value)->firstOrFail();

        $this->delete(route('super.tenants.domains.destroy', ['tenant' => $a->public_id, 'domain' => $subdomain->id], false))->assertSessionHasErrors('domain');
        $this->assertNotNull(Domain::query()->find($subdomain->id));

        $this->post(route('super.tenants.domains.store', ['tenant' => $a->public_id], false), ['domain' => 'other.bp.test'])->assertSessionHasErrors('domain');
        $this->post(route('super.tenants.domains.store', ['tenant' => $a->public_id], false), ['domain' => 'test-b.bp.test'])->assertSessionHasErrors('domain');

        // A's domain under B's URL is a 404, not a cross-tenant write.
        $this->post(route('super.tenants.domains.verify', ['tenant' => $b->public_id, 'domain' => $subdomain->id], false))->assertNotFound();
        $this->post(route('super.tenants.domains.primary', ['tenant' => $b->public_id, 'domain' => $subdomain->id], false))->assertNotFound();
        $this->delete(route('super.tenants.domains.destroy', ['tenant' => $b->public_id, 'domain' => $subdomain->id], false))->assertNotFound();
    }
}
