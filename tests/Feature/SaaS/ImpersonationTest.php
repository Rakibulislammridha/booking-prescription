<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Actions\Impersonation\ConsumeImpersonationToken;
use App\Domain\SaaS\Actions\Impersonation\IssueImpersonationToken;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Exceptions\ImpersonationTokenInvalid;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\ImpersonationToken;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Impersonation is the most dangerous button in the product: it puts a platform operator inside a clinic's
 * records. Every property that keeps it safe is asserted here — single use, short lived, bound to one tenant,
 * bound to one user, and audited on the way in AND on the way out.
 */
final class ImpersonationTest extends TestCase
{
    public function test_a_token_is_minted_hashed_short_lived_and_audited(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');

        $ticket = app(IssueImpersonationToken::class)->handle($admin, $tenant, null, '203.0.113.7', 'http');

        $row = ImpersonationToken::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
        $plain = $this->tokenFromUrl($ticket->url);

        $this->assertStringContainsString('://test-a.bp.test/panel/impersonate/', $ticket->url);
        $this->assertSame(hash('sha256', $plain), $row->token_hash, 'only the hash may be stored');
        $this->assertNotSame($plain, $row->token_hash);
        // `expires_at` is timestamp(0), so the stored value truncates sub-second precision and the
        // elapsed test time shifts the diff: an exact comparison reads 59 about half the time. The
        // property worth asserting is that the token is short-lived, not that it is 60.000s.
        $this->assertEqualsWithDelta(IssueImpersonationToken::TTL_SECONDS, $row->expires_at->diffInSeconds(CarbonImmutable::now(), true), 2, 'the token must expire about TTL seconds out');
        $this->assertTrue($row->isUsable());

        $this->assertTrue(AuditLogCentral::query()
            ->where('tenant_id', $tenant->id)
            ->where('super_admin_id', $admin->id)
            ->where('action', CentralAuditAction::Create->value)
            ->exists(), 'minting is audited even if the token is never used');
    }

    public function test_the_default_target_is_the_clinics_hospital_admin_and_inactive_users_are_refused(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');

        $ticket = app(IssueImpersonationToken::class)->handle($admin, $tenant);

        $roles = Tenancy::run($tenant, fn (): array => User::query()->findOrFail($ticket->userId)->getRoleNames()->all());
        $this->assertContains(Role::HospitalAdmin->value, $roles);

        $inactive = Tenancy::run($tenant, function (): int {
            $branch = Branch::query()->where('is_main', true)->firstOrFail();
            $user = User::factory()->create(['default_branch_id' => $branch->id, 'is_active' => false]);

            return $user->id;
        });

        $this->assertThrows(fn () => app(IssueImpersonationToken::class)->handle($admin, $tenant, $inactive), ImpersonationTokenInvalid::class);
    }

    public function test_entering_signs_the_operator_in_marks_the_session_and_audits_both_sides(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $ticket = app(IssueImpersonationToken::class)->handle($admin, $tenant);
        $plain = $this->tokenFromUrl($ticket->url);

        $this->clearAuth();
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test']);
        $response = $this->get("/panel/impersonate/{$plain}");

        $response->assertRedirect(route('panel.impersonate.started', absolute: false));
        $this->assertAuthenticatedAs(Tenancy::run($tenant, fn () => User::query()->findOrFail($ticket->userId)), 'web');
        $response->assertSessionHas('impersonated_by', $admin->id);
        $response->assertSessionHas('impersonation_user_id', $ticket->userId);

        $this->assertTrue(AuditLogCentral::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', CentralAuditAction::Impersonate->value)
            ->exists(), 'entry is audited centrally');

        // The landing page names who, where and since when, and it is unmistakable by construction.
        $this->get('/panel/impersonate/started')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Impersonating')
                ->where('tenant.slug', 'test-a')
                ->has('user.name')
                ->has('started_at')
                ->has('console_url'));

        // Every panel page carries the banner flag while the session lasts.
        $this->get('/panel')->assertOk()->assertInertia(fn ($page) => $page->where('auth.impersonating', true));
    }

    public function test_a_token_is_single_use_and_a_replay_is_refused(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $plain = $this->tokenFromUrl(app(IssueImpersonationToken::class)->handle($admin, $tenant)->url);

        $this->clearAuth();
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test']);
        $this->get("/panel/impersonate/{$plain}")->assertRedirect();

        $this->clearAuth();
        $this->get("/panel/impersonate/{$plain}")->assertForbidden();

        $this->assertNotNull(ImpersonationToken::query()->where('token_hash', hash('sha256', $plain))->value('consumed_at'));
        $this->assertSame(1, AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Impersonate->value)->count());
    }

    public function test_an_expired_token_is_refused(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $ticket = app(IssueImpersonationToken::class)->handle($admin, $tenant);
        $plain = $this->tokenFromUrl($ticket->url);

        $this->travelTo(CarbonImmutable::now()->addSeconds(IssueImpersonationToken::TTL_SECONDS + 1));

        $this->assertThrows(fn () => app(ConsumeImpersonationToken::class)->handle($plain, $tenant), ImpersonationTokenInvalid::class);
        $this->assertNull(ImpersonationToken::query()->where('token_hash', hash('sha256', $plain))->value('consumed_at'));
        $this->travelBack();
    }

    public function test_a_token_minted_for_one_clinic_cannot_be_spent_on_another(): void
    {
        $admin = $this->actingAsSuper();
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        $plain = $this->tokenFromUrl(app(IssueImpersonationToken::class)->handle($admin, $tenantA)->url);

        $this->assertThrows(fn () => app(ConsumeImpersonationToken::class)->handle($plain, $tenantB), ImpersonationTokenInvalid::class);

        $this->clearAuth();
        $this->withServerVariables(['HTTP_HOST' => 'test-b.bp.test']);
        $this->get("/panel/impersonate/{$plain}")->assertForbidden();

        // Still usable on the clinic it was minted for.
        $this->clearAuth();
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test']);
        $this->get("/panel/impersonate/{$plain}")->assertRedirect();
    }

    public function test_an_unknown_token_is_refused_without_saying_why(): void
    {
        $this->asTenant('a');
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test']);

        $this->get('/panel/impersonate/'.str_repeat('a', 64))->assertForbidden();
    }

    public function test_every_tenant_audit_row_written_during_the_session_names_the_impersonator(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $plain = $this->tokenFromUrl(app(IssueImpersonationToken::class)->handle($admin, $tenant)->url);

        $this->clearAuth();
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test']);
        $this->get("/panel/impersonate/{$plain}")->assertRedirect();

        // A write made while impersonating carries the marker SCHEMA §3.7 defines.
        $this->post(route('panel.patients.store', absolute: false), [
            'name' => 'ইমপারসনেটেড রোগী', 'mobile' => '01711000111', 'age_years' => 30,
        ])->assertSessionHasNoErrors();

        $marked = Tenancy::run($tenant, fn () => AuditLog::query()
            ->where('impersonator_super_admin_id', $admin->id)
            ->where('action', AuditAction::Create->value)
            ->count());

        $this->assertGreaterThan(0, $marked, 'audit rows written during an impersonated session must name the operator');
    }

    public function test_leaving_logs_out_invalidates_the_session_and_audits_the_exit(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $ticket = app(IssueImpersonationToken::class)->handle($admin, $tenant);
        $plain = $this->tokenFromUrl($ticket->url);

        $this->clearAuth();
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test']);
        $this->get("/panel/impersonate/{$plain}")->assertRedirect();

        $response = $this->post('/panel/impersonate/leave');

        $response->assertRedirect();
        $this->assertStringContainsString('super.bp.test', (string) $response->headers->get('Location'));
        $this->assertGuest('web');
        $this->assertFalse(session()->has('impersonated_by'));

        $exit = AuditLogCentral::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', CentralAuditAction::ImpersonateEnd->value)
            ->firstOrFail();
        $this->assertSame($admin->id, $exit->super_admin_id);
        $this->assertSame($ticket->userId, (int) data_get($exit->getAttribute('after'), 'user_id'));
    }

    public function test_a_cancelled_clinic_cannot_be_impersonated(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $tenant->forceFill(['status' => TenantStatus::Cancelled])->save();

        $this->post(route('super.tenants.impersonate', ['tenant' => $tenant->public_id], false))
            ->assertSessionHasErrors('tenant');
    }

    public function test_the_console_mints_a_token_and_redirects_to_the_clinic_host(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');

        $response = $this->post(route('super.tenants.impersonate', ['tenant' => $tenant->public_id], false));

        $response->assertRedirect();
        $this->assertMatchesRegularExpression('#^http://test-a\.bp\.test/panel/impersonate/[A-Za-z0-9]{64}$#', (string) $response->headers->get('Location'));
    }

    public function test_expired_and_consumed_tokens_are_pruned_after_a_day(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        app(IssueImpersonationToken::class)->handle($admin, $tenant);

        $this->artisan('saas:prune-impersonation-tokens')->assertSuccessful();
        $this->assertSame(1, ImpersonationToken::query()->where('tenant_id', $tenant->id)->count(), 'a fresh token is kept');

        $this->travelTo(CarbonImmutable::now()->addHours(25));
        $this->artisan('saas:prune-impersonation-tokens')->assertSuccessful();
        $this->assertSame(0, ImpersonationToken::query()->where('tenant_id', $tenant->id)->count());
        $this->travelBack();
    }

    private function tokenFromUrl(string $url): string
    {
        return (string) preg_replace('#^.*/panel/impersonate/#', '', $url);
    }

    private function clearAuth(): void
    {
        Auth::guard('web')->logout();
        Auth::guard('super')->logout();
        session()->flush();
    }
}
