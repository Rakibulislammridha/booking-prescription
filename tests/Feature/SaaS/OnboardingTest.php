<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Events\TenantOnboarded;
use App\Models\Central\Domain;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The sign-up wizard, end to end: a real Postgres schema, a real admin who can really log in, and a panel that
 * really renders — because "provisioning succeeded" is worth nothing if the clinic cannot get in afterwards.
 */
final class OnboardingTest extends TestCase
{
    private const SLUG = 'shefa-clinic';

    protected function tearDown(): void
    {
        $tenant = Tenant::withTrashed()->where('slug', self::SLUG)->first();

        if ($tenant !== null) {
            Tenancy::check() && Tenancy::end();
            DB::connection('pgsql')->statement('drop schema if exists "'.$tenant->schema_name.'" cascade');
        }

        parent::tearDown();
    }

    public function test_the_marketing_surface_serves_the_wizard_with_the_real_plan_rows(): void
    {
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);

        $this->get('/signup')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Central/Onboarding/Signup')
            ->has('plans.0.code')
            ->has('plans.0.price_monthly_paisa')
            ->has('plans.0.trial_days')
            ->has('plans.0.limits')
            ->has('plans.0.toggles')
            ->where('central_domain', 'bp.test'));

        // The wizard's strings are SERVER-rendered (App\Domain\SaaS\Support\CentralCopy) rather than shipped in
        // the site's shared locale chunk, which is what keeps the marketing copy out of every tenant page's
        // 95 KB first-load budget. The map is flat, keyed without the `saas.` prefix.
        $copy = (array) $this->withHeaders($this->inertiaHeaders())->get('/signup')->json('props.copy');
        $this->assertNotSame([], $copy);
        $this->assertSame(__('saas.onboarding.title'), $copy['onboarding.title'] ?? null);
        $this->assertSame(__('saas.nav.pricing'), $copy['nav.pricing'] ?? null);
        $this->assertArrayNotHasKey('mail.welcome.subject', $copy, 'server-only copy must not travel to the browser');
    }

    public function test_signing_up_provisions_a_working_clinic_and_hands_it_its_own_panel(): void
    {
        Event::fake([TenantOnboarded::class]);
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);

        $response = $this->post('/signup', $this->payload());

        $tenant = Tenant::query()->where('slug', self::SLUG)->firstOrFail();
        $response->assertRedirect(route('central.onboarding.done', ['tenant' => $tenant->public_id], false));

        // 1. The control-plane rows.
        $this->assertSame(TenantStatus::Trial, $tenant->status);
        $this->assertSame('শেফা ক্লিনিক', $tenant->name);
        $this->assertNotNull($tenant->provisioned_at);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertSame('done', $tenant->onboarding['step']);
        $this->assertSame("tenant_{$tenant->id}", $tenant->schema_name);

        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);

        $domain = Domain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->firstOrFail();
        $this->assertSame(self::SLUG.'.bp.test', $domain->domain);

        // 2. The schema really exists, with the tenant's tables in it and nothing in public.
        $tables = (int) DB::connection('pgsql')->scalar(
            "select count(*) from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'",
            [$tenant->schema_name],
        );
        $this->assertGreaterThan(30, $tables, 'every tenant migration must have run');

        // 3. The first branch and the hospital admin exist inside it.
        [$branch, $admin] = Tenancy::run($tenant, fn (): array => [
            Branch::query()->where('is_main', true)->first(),
            User::query()->where('email', 'owner@shefa.test')->first(),
        ]);
        $this->assertNotNull($branch);
        $this->assertNotNull($admin);
        $this->assertContains(Role::HospitalAdmin->value, Tenancy::run($tenant, fn () => $admin->getRoleNames()->all()));
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->password));

        Event::assertDispatched(TenantOnboarded::class, fn (TenantOnboarded $e) => $e->tenantId === $tenant->id);

        // 4. Central code did not return with a tenant search path still set.
        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));

        // 5. The welcome page names the panel URL on the clinic's own host.
        $this->get(route('central.onboarding.done', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Central/Onboarding/Done')
                ->where('tenant.slug', self::SLUG)
                ->where('tenant.panel_url', 'http://'.self::SLUG.'.bp.test/panel'));

        // 6. And the clinic can actually be used: the panel resolves on its host and the admin logs in.
        $this->withServerVariables(['HTTP_HOST' => self::SLUG.'.bp.test']);
        $this->get('/panel')->assertRedirect(route('panel.login', absolute: false));

        $this->post(route('panel.login.store', absolute: false), ['email' => 'owner@shefa.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('panel.dashboard', absolute: false));

        $this->get('/panel')->assertOk()->assertInertia(fn ($page) => $page
            ->where('tenant.slug', self::SLUG)
            ->where('auth.guard', 'web'));
    }

    public function test_a_taken_or_reserved_address_is_a_validation_error_and_provisions_nothing(): void
    {
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);
        $before = Tenant::query()->count();

        $this->post('/signup', $this->payload(['slug' => 'test-a']))->assertSessionHasErrors('slug');
        $this->post('/signup', $this->payload(['slug' => 'super']))->assertSessionHasErrors('slug');
        $this->post('/signup', $this->payload(['slug' => 'Not A Slug']))->assertSessionHasErrors('slug');

        $this->assertSame($before, Tenant::query()->count());
    }

    public function test_the_wizard_validates_the_owner_the_password_and_the_plan(): void
    {
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);

        $this->post('/signup', $this->payload(['owner_mobile' => '12345']))->assertSessionHasErrors('owner_mobile');
        $this->post('/signup', $this->payload(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
        $this->post('/signup', $this->payload(['plan' => 'no-such-plan']))->assertSessionHasErrors('plan');
        $this->post('/signup', $this->payload(['owner_email' => 'not-an-email']))->assertSessionHasErrors('owner_email');
    }

    public function test_the_wizard_is_not_reachable_on_a_tenant_host(): void
    {
        $this->asTenant('a');
        $this->get('/signup')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'clinic_name' => 'শেফা ক্লিনিক',
            'slug' => self::SLUG,
            'owner_name' => 'ডা. রফিকুল ইসলাম',
            'owner_email' => 'owner@shefa.test',
            'owner_mobile' => '+8801712345678',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'plan' => 'starter',
            'branch_name' => 'প্রধান শাখা',
            'locale' => 'bn',
            'timezone' => 'Asia/Dhaka',
            'demo' => false,
        ], $overrides);
    }
}
