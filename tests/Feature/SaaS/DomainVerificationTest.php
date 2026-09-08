<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\SaaS\Actions\Domains\AddCustomDomain;
use App\Domain\SaaS\Actions\Domains\SetPrimaryDomain;
use App\Domain\SaaS\Actions\Domains\VerifyCustomDomain;
use App\Domain\SaaS\Contracts\DnsResolver;
use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Events\CustomDomainVerified;
use App\Domain\SaaS\Exceptions\DomainAlreadyClaimed;
use App\Domain\SaaS\Exceptions\DomainNotVerified;
use App\Domain\SaaS\Exceptions\FeatureNotInPlan;
use App\Domain\SaaS\Services\ArrayDnsResolver;
use App\Domain\SaaS\Services\DomainName;
use App\Domain\SaaS\Services\DomainVerifier;
use App\Models\Central\Domain;
use App\Models\Central\Tenant;
use App\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Event;
use Tests\Feature\SaaS\Concerns\ControlsPlanLimits;
use Tests\TestCase;

/**
 * Custom domains, with the resolver faked — a DNS lookup inside a test suite is a five-second flake waiting for
 * a train tunnel. `ArrayDnsResolver` is bound as a singleton in the testing environment precisely so that no
 * test can reach a real resolver even by accident.
 */
final class DomainVerificationTest extends TestCase
{
    use ControlsPlanLimits;

    private ArrayDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = app(ArrayDnsResolver::class);
        $this->dns->flush();
        $this->assertInstanceOf(ArrayDnsResolver::class, app(DnsResolver::class), 'the suite must never use the system resolver');
    }

    public function test_a_domain_starts_pending_and_routes_nothing_until_it_is_verified(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, true);

        $domain = app(AddCustomDomain::class)->handle($tenant, 'Queue.Hospital.COM');

        $this->assertSame('queue.hospital.com', $domain->domain, 'hostnames are stored lower-case (SCHEMA §2.7)');
        $this->assertSame(DomainVerificationStatus::Pending, $domain->verification_status);
        $this->assertNull(app(TenantResolver::class)->resolve('queue.hospital.com'), 'an unverified row must route nothing');
    }

    public function test_the_right_txt_record_verifies_the_domain_and_the_resolver_picks_it_up(): void
    {
        Event::fake([CustomDomainVerified::class]);
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, true);
        $domain = app(AddCustomDomain::class)->handle($tenant, 'care.hospital.com');

        $this->dns->set('_bp-verify.care.hospital.com', DomainName::expectedTxt($domain->verification_token));
        $result = app(VerifyCustomDomain::class)->handle($domain);

        $this->assertTrue($result->verified);
        $this->assertSame(DomainVerificationStatus::Verified, $domain->refresh()->verification_status);
        $this->assertNotNull($domain->getAttribute('verified_at'));
        Event::assertDispatched(CustomDomainVerified::class);

        $resolved = app(TenantResolver::class)->resolve('care.hospital.com');
        $this->assertInstanceOf(Tenant::class, $resolved);
        $this->assertSame($tenant->id, $resolved->id);
    }

    /**
     * Documented interaction, not a SaaS bug: `TenantResolver` strips a leading SERVICE label
     * (`config('tenancy.service_prefixes')` = queue / book / display) before it looks a host up, so
     * `queue.hospital.com` resolves by asking for `hospital.com`. A clinic that wants the queue on
     * `queue.hospital.com` therefore registers `hospital.com` — which is what the platform subdomains do too
     * (`queue.demo.bp.test` → `demo.bp.test`). This test pins the behaviour so the next person to read
     * SCHEMA §2.7's `queue.hospital.com` example knows which row to create.
     */
    public function test_a_service_prefixed_custom_host_resolves_through_its_base_domain(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, true);

        $prefixed = app(AddCustomDomain::class)->handle($tenant, 'queue.hospital.com');
        $this->dns->set('_bp-verify.queue.hospital.com', DomainName::expectedTxt($prefixed->verification_token));
        app(VerifyCustomDomain::class)->handle($prefixed);

        $this->assertNull(app(TenantResolver::class)->resolve('queue.hospital.com'), 'the `queue.` label is stripped before the lookup');

        $base = app(AddCustomDomain::class)->handle($tenant, 'hospital.com');
        $this->dns->set('_bp-verify.hospital.com', DomainName::expectedTxt($base->verification_token));
        app(VerifyCustomDomain::class)->handle($base);

        $resolved = app(TenantResolver::class)->resolve('queue.hospital.com');
        $this->assertInstanceOf(Tenant::class, $resolved);
        $this->assertSame($tenant->id, $resolved->id);
    }

    public function test_a_wrong_token_fails_with_a_reason_that_tells_the_customer_what_to_fix(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, true);
        $domain = app(AddCustomDomain::class)->handle($tenant, 'queue.hospital.com');

        // Nothing published at all.
        $this->assertSame('no_txt_record', app(VerifyCustomDomain::class)->handle($domain)->reason);
        $this->assertSame(DomainVerificationStatus::Failed, $domain->refresh()->verification_status);

        // Somebody else's TXT records, but no verification record.
        $this->dns->set('_bp-verify.queue.hospital.com', ['v=spf1 include:_spf.example.com ~all']);
        $this->assertSame('no_verification_record', app(VerifyCustomDomain::class)->handle($domain)->reason);

        // Our record, but the token of a DIFFERENT domain — the exact copy-paste mistake.
        $this->dns->set('_bp-verify.queue.hospital.com', DomainName::expectedTxt('some-other-tenants-token'));
        $this->assertSame('token_mismatch', app(VerifyCustomDomain::class)->handle($domain)->reason);
        $this->assertSame(DomainVerificationStatus::Failed, $domain->refresh()->verification_status);
        $this->assertNull(app(TenantResolver::class)->resolve('queue.hospital.com'));

        // And the right one still works afterwards: failure is not terminal.
        $this->dns->set('_bp-verify.queue.hospital.com', DomainName::expectedTxt($domain->verification_token));
        $this->assertTrue(app(VerifyCustomDomain::class)->handle($domain)->verified);
    }

    public function test_the_bare_hostname_is_accepted_as_a_fallback_for_panels_without_underscore_labels(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, true);
        $domain = app(AddCustomDomain::class)->handle($tenant, 'hospital.com');

        $this->dns->set('hospital.com', ['"'.DomainName::expectedTxt($domain->verification_token).'"']);

        $this->assertTrue(app(VerifyCustomDomain::class)->handle($domain)->verified);
    }

    public function test_apex_and_subdomain_get_different_dns_instructions(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, true);

        $apex = app(AddCustomDomain::class)->handle($tenant, 'hospital.com.bd');
        $sub = app(AddCustomDomain::class)->handle($tenant, 'queue.hospital.com.bd');

        $this->assertTrue(DomainName::isApex('hospital.com.bd'), 'com.bd is a two-label public suffix');
        $this->assertFalse(DomainName::isApex('queue.hospital.com.bd'));

        $apexInstructions = DomainVerifier::instructions($apex, 'bp.test', '203.0.113.10');
        $subInstructions = DomainVerifier::instructions($sub, 'bp.test');

        $this->assertSame('A', $apexInstructions['record_kind'], 'an apex cannot carry a CNAME (RFC 1034 §3.6.2)');
        $this->assertSame('@', $apexInstructions['record_name']);
        $this->assertSame('203.0.113.10', $apexInstructions['record_value']);
        $this->assertSame('CNAME', $subInstructions['record_kind']);
        $this->assertSame('queue', $subInstructions['record_name']);
        $this->assertSame('bp.test', $subInstructions['record_value']);
        $this->assertSame('_bp-verify.hospital.com.bd', $apexInstructions['txt_name']);
    }

    public function test_re_verification_never_demotes_a_live_domain_from_the_scheduled_sweep(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, true);
        $domain = app(AddCustomDomain::class)->handle($tenant, 'queue.hospital.com');
        $this->dns->set('_bp-verify.queue.hospital.com', DomainName::expectedTxt($domain->verification_token));
        app(VerifyCustomDomain::class)->handle($domain);

        // The record disappears — a registrar outage, or our resolver failing.
        $this->dns->flush();

        // The sweep records the check but leaves the clinic's site up.
        app(VerifyCustomDomain::class)->handle($domain->refresh(), demoteOnFailure: false);
        $this->assertSame(DomainVerificationStatus::Verified, $domain->refresh()->verification_status);
        $this->assertNotNull($domain->getAttribute('last_checked_at'));

        // A deliberate re-verification does demote it.
        app(VerifyCustomDomain::class)->handle($domain, demoteOnFailure: true);
        $this->assertSame(DomainVerificationStatus::Failed, $domain->refresh()->verification_status);
        $this->assertNull(app(TenantResolver::class)->resolve('queue.hospital.com'));
    }

    public function test_the_scheduled_sweep_verifies_pending_domains_and_leaves_verified_ones_alone(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, true);
        $pending = app(AddCustomDomain::class)->handle($tenant, 'new.hospital.com');
        $live = app(AddCustomDomain::class)->handle($tenant, 'old.hospital.com');
        $this->dns->set('_bp-verify.old.hospital.com', DomainName::expectedTxt($live->verification_token));
        app(VerifyCustomDomain::class)->handle($live);

        $this->dns->set('_bp-verify.new.hospital.com', DomainName::expectedTxt($pending->verification_token));
        $this->dns->forget('_bp-verify.old.hospital.com');

        $this->artisan('saas:verify-domains')->assertSuccessful();

        $this->assertSame(DomainVerificationStatus::Verified, $pending->refresh()->verification_status);
        $this->assertSame(DomainVerificationStatus::Verified, $live->refresh()->verification_status);
    }

    public function test_a_hostname_can_only_be_claimed_once_and_never_from_the_platform_itself(): void
    {
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        $this->setToggle($tenantA, PlanFeatureKey::CustomDomain, true);
        $this->setToggle($tenantB, PlanFeatureKey::CustomDomain, true);

        app(AddCustomDomain::class)->handle($tenantA, 'queue.hospital.com');

        $this->assertThrows(fn () => app(AddCustomDomain::class)->handle($tenantB, 'queue.hospital.com'), DomainAlreadyClaimed::class);
        $this->assertThrows(fn () => app(AddCustomDomain::class)->handle($tenantB, 'super.bp.test'), DomainAlreadyClaimed::class);
        $this->assertThrows(fn () => app(AddCustomDomain::class)->handle($tenantB, 'bp.test'), DomainAlreadyClaimed::class);
        $this->assertThrows(fn () => app(AddCustomDomain::class)->handle($tenantB, 'other.bp.test'), DomainAlreadyClaimed::class);
        $this->assertThrows(fn () => app(AddCustomDomain::class)->handle($tenantB, 'not a hostname'), DomainAlreadyClaimed::class);
    }

    public function test_a_plan_without_the_custom_domain_module_cannot_add_one(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, false);

        $this->assertThrows(fn () => app(AddCustomDomain::class)->handle($tenant, 'queue.hospital.com'), FeatureNotInPlan::class);

        // The super admin's own path bypasses the plan check on purpose.
        $domain = app(AddCustomDomain::class)->handle($tenant, 'queue.hospital.com', requireFeature: false);
        $this->assertSame('queue.hospital.com', $domain->domain);
    }

    public function test_only_a_verified_domain_can_become_primary(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, true);
        $domain = app(AddCustomDomain::class)->handle($tenant, 'queue.hospital.com');

        $this->assertThrows(fn () => app(SetPrimaryDomain::class)->handle($domain), DomainNotVerified::class);

        $this->dns->set('_bp-verify.queue.hospital.com', DomainName::expectedTxt($domain->verification_token));
        app(VerifyCustomDomain::class)->handle($domain);
        app(SetPrimaryDomain::class)->handle($domain->refresh());

        $this->assertTrue($domain->refresh()->is_primary);
        $this->assertSame(1, Domain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->count(), 'one primary per tenant');
    }
}
