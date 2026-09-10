<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Domain\SaaS\Enums\DomainType;
use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Jobs\SendStaffSetPasswordLinkJob;
use App\Domain\Tenancy\Actions\ProvisionTenant;
use App\Domain\Tenancy\Data\ProvisionTenantData;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Domain;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\TenantResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Onboarding, editing and deleting a clinic from the super console — an operator must be able to do all of it
 * without a terminal (BRIEF §5.M). Creation is proved the way `OnboardingTest` proves the wizard: a real schema, a
 * real admin, a panel that really answers on the clinic's own host.
 */
final class TenantConsoleTest extends TestCase
{
    private const SLUG = 'sunrise-console';

    protected function tearDown(): void
    {
        Tenancy::check() && Tenancy::end();

        foreach (Tenant::withTrashed()->whereIn('slug', [self::SLUG, 'sunrise-moved', 'doomed-clinic'])->get() as $tenant) {
            DB::connection('pgsql')->statement('drop schema if exists "'.$tenant->schema_name.'" cascade');
        }

        parent::tearDown();
    }

    public function test_creating_a_clinic_from_the_console_provisions_a_working_tenant(): void
    {
        $admin = $this->actingAsSuper();

        $this->get(route('super.tenants.create', absolute: false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Tenants/Create')
                ->has('plans.0.code')
                ->has('plans.0.trial_days')
                ->where('central_domain', 'bp.test')
                ->has('timezones'));

        $response = $this->post(route('super.tenants.store', absolute: false), $this->payload(['trial_days' => 5, 'custom_domain' => 'Booking.Sunrise-Hospital.com']));

        $tenant = Tenant::query()->where('slug', self::SLUG)->firstOrFail();
        $response->assertRedirect(route('super.tenants.show', ['tenant' => $tenant->public_id], false));

        // 1. Central rows: the same shape the wizard leaves, plus what the operator typed.
        $this->assertSame(TenantStatus::Trial, $tenant->status);
        $this->assertSame('Sunrise Hospital', $tenant->name);
        $this->assertSame('সানরাইজ হাসপাতাল', $tenant->branding['name_bn']);
        $this->assertSame('Negotiated by phone.', $tenant->platform_notes);
        $this->assertSame('done', $tenant->onboarding['step']);
        $this->assertSame("tenant_{$tenant->id}", $tenant->schema_name);
        $this->assertNotNull($tenant->provisioned_at);
        $this->assertEqualsWithDelta(5, (int) CarbonImmutable::now()->diffInDays($tenant->trial_ends_at, true), 1, 'the hand-negotiated trial overrides the plan');

        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertEqualsWithDelta(5, (int) CarbonImmutable::now()->diffInDays($subscription->current_period_end, true), 1);

        $this->assertSame(self::SLUG.'.bp.test', Domain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->value('domain'));
        $custom = Domain::query()->where('tenant_id', $tenant->id)->where('type', DomainType::Custom->value)->firstOrFail();
        $this->assertSame('booking.sunrise-hospital.com', $custom->domain, 'lower-cased, and pending until its TXT is proved');
        $this->assertSame(DomainVerificationStatus::Pending, $custom->verification_status);

        // 2. The schema exists and is migrated.
        $tables = (int) DB::connection('pgsql')->scalar("select count(*) from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'", [$tenant->schema_name]);
        $this->assertGreaterThan(30, $tables);

        // 3. The owner's admin exists inside it, must change the password the operator typed, and can sign in with it.
        $owner = Tenancy::run($tenant, fn () => User::query()->where('email', 'owner@sunrise.test')->firstOrFail());
        $this->assertTrue($owner->must_change_password);
        $this->assertTrue(Hash::check('first-light-2026', $owner->password));
        $this->assertContains(Role::HospitalAdmin->value, Tenancy::run($tenant, fn () => $owner->getRoleNames()->all()));

        // 4. Audited, and central code came back on `public`.
        $row = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Create->value)->where('auditable_type', $tenant->getMorphClass())->firstOrFail();
        $this->assertSame($admin->id, $row->super_admin_id);
        $this->assertSame('console', $row->after['source'] ?? null);
        $this->assertSame('password', $row->after['credential'] ?? null);
        $this->assertArrayNotHasKey('password', (array) $row->after, 'the secret is never audited');
        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));

        // 5. The Show page lands with the one-time reveal, the staff list and the owner shortcut — once.
        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Tenants/Show')
                ->where('reveal.kind', 'password')
                ->where('reveal.value', 'first-light-2026')
                ->where('reveal.email', 'owner@sunrise.test')
                ->where('staff.0.email', 'owner@sunrise.test')
                ->where('staff.0.role', Role::HospitalAdmin->value)
                ->where('staff.0.must_change_password', true)
                ->where('tenant.platform_notes', 'Negotiated by phone.')
                ->where('links.panel', 'http://'.self::SLUG.'.bp.test/panel'));
        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page->where('reveal', null));

        // 6. And the clinic works: its panel answers on its host and the owner logs in.
        $this->clearSuper();
        $this->withServerVariables(['HTTP_HOST' => self::SLUG.'.bp.test']);
        $this->get('/panel')->assertRedirect(route('panel.login', absolute: false));
        $this->post(route('panel.login.store', absolute: false), ['email' => 'owner@sunrise.test', 'password' => 'first-light-2026'])->assertRedirect();
        $this->assertAuthenticated('web');
    }

    public function test_a_set_password_link_is_minted_on_the_clinics_own_host_and_shown_once(): void
    {
        Bus::fake([SendStaffSetPasswordLinkJob::class]);
        $this->actingAsSuper();

        $this->post(route('super.tenants.store', absolute: false), $this->payload(['credential' => 'link', 'password' => null]))->assertRedirect();
        $tenant = Tenant::query()->where('slug', self::SLUG)->firstOrFail();

        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('reveal.kind', 'link')
                ->where('reveal.value', fn ($url) => str_starts_with((string) $url, 'http://'.self::SLUG.'.bp.test/panel/reset-password/'))
                ->has('reveal.expires_at'));

        $owner = Tenancy::run($tenant, fn () => User::query()->where('email', 'owner@sunrise.test')->firstOrFail());
        $this->assertTrue($owner->must_change_password);
        Bus::assertDispatched(SendStaffSetPasswordLinkJob::class, fn (SendStaffSetPasswordLinkJob $job) => $job->address === 'owner@sunrise.test'
            && str_starts_with($job->url, 'http://'.self::SLUG.'.bp.test/panel/reset-password/')
            && $job->queue === 'notifications');

        $tokens = Tenancy::run($tenant, fn (): int => DB::table('password_reset_tokens')->where('email', 'owner@sunrise.test')->count());
        $this->assertSame(1, $tokens, 'the link is a real broker token in the clinic schema');
    }

    public function test_slug_validation_refuses_reserved_taken_and_malformed_addresses(): void
    {
        $this->actingAsSuper();
        $before = Tenant::query()->count();

        $this->post(route('super.tenants.store', absolute: false), $this->payload(['slug' => 'super']))->assertSessionHasErrors('slug');
        $this->post(route('super.tenants.store', absolute: false), $this->payload(['slug' => 'queue']))->assertSessionHasErrors('slug');
        $this->post(route('super.tenants.store', absolute: false), $this->payload(['slug' => 'test-a']))->assertSessionHasErrors('slug');
        $this->post(route('super.tenants.store', absolute: false), $this->payload(['slug' => 'Not A Slug']))->assertSessionHasErrors('slug');
        $this->post(route('super.tenants.store', absolute: false), $this->payload(['credential' => 'password', 'password' => null]))->assertSessionHasErrors('password');
        $this->post(route('super.tenants.store', absolute: false), $this->payload(['custom_domain' => 'other.bp.test']))->assertSessionHasErrors('custom_domain');

        $this->assertSame($before, Tenant::query()->count());

        // The live check the form uses answers the same three questions.
        $this->getJson(route('super.tenants.slug-check', ['slug' => 'super'], false))->assertOk()->assertJson(['available' => false, 'reason' => 'reserved']);
        $this->getJson(route('super.tenants.slug-check', ['slug' => 'test-a'], false))->assertOk()->assertJson(['available' => false, 'reason' => 'taken']);
        $this->getJson(route('super.tenants.slug-check', ['slug' => 'x'], false))->assertOk()->assertJson(['available' => false, 'reason' => 'invalid']);
        $this->getJson(route('super.tenants.slug-check', ['slug' => 'Free-Slug-XYZ'], false))->assertOk()->assertJson(['available' => true, 'reason' => null, 'host' => 'free-slug-xyz.bp.test']);
        $this->getJson(route('super.tenants.slug-check', ['slug' => 'test-a', 'ignore' => $this->tenant('a')->public_id], false))->assertOk()->assertJson(['available' => true]);
    }

    public function test_editing_a_clinic_round_trips_including_the_logo_under_the_tenant_path(): void
    {
        Storage::fake('public');
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');

        $this->get(route('super.tenants.edit', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Tenants/Edit')
                ->where('tenant.slug', 'test-a')
                ->has('tenant.branding.primary_color')
                ->has('timezones'));

        $this->post(route('super.tenants.update', ['tenant' => $tenant->public_id], false), [
            '_method' => 'put',
            'name' => 'Test Clinic A (renamed)',
            'name_bn' => 'টেস্ট ক্লিনিক এ',
            'owner_name' => 'নতুন মালিক',
            'owner_email' => 'New-Owner@test-a.test',
            'owner_mobile' => '01812345678',
            'locale' => 'en',
            'timezone' => 'Asia/Kolkata',
            'primary_color' => '#123456',
            'accent_color' => '#abcdef',
            'notes' => 'VIP account.',
            'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
        ])->assertRedirect(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertSame('Test Clinic A (renamed)', $tenant->name);
        $this->assertSame('টেস্ট ক্লিনিক এ', $tenant->branding['name_bn']);
        $this->assertSame('নতুন মালিক', $tenant->owner_name);
        $this->assertSame('new-owner@test-a.test', $tenant->owner_email);
        $this->assertSame('+8801812345678', $tenant->owner_mobile);
        $this->assertSame('en', $tenant->locale->value);
        $this->assertSame('Asia/Kolkata', $tenant->timezone);
        $this->assertSame('#123456', $tenant->branding['primary_color']);
        $this->assertSame('#abcdef', $tenant->branding['accent_color']);
        $this->assertSame('VIP account.', $tenant->platform_notes);
        $logo = (string) $tenant->branding['logo_path'];
        $this->assertStringStartsWith("tenants/{$tenant->id}/branding/logo-", $logo, 'ARCHITECTURE §8.7: every tenant object lives under tenants/{id}/');
        $this->assertStringEndsWith('.png', $logo);
        Storage::disk('public')->assertExists($logo);

        $row = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Update->value)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $row->super_admin_id);
        $this->assertSame('Test Clinic A', $row->before['name'] ?? null);
        $this->assertSame('Test Clinic A (renamed)', $row->after['name'] ?? null);
        $this->assertArrayHasKey('branding.logo_path', (array) $row->after);
        $this->assertArrayNotHasKey('timezone', array_filter((array) $row->after, fn ($v, $k) => $k === 'timezone' && $v === 'Asia/Dhaka', ARRAY_FILTER_USE_BOTH), 'only changed attributes are audited');

        // Clearing the logo removes the file and nulls the path; the slug never moves through this form.
        $this->post(route('super.tenants.update', ['tenant' => $tenant->public_id], false), [
            '_method' => 'put', 'name' => 'Test Clinic A', 'owner_name' => 'নতুন মালিক', 'owner_email' => 'new-owner@test-a.test', 'owner_mobile' => '+8801812345678',
            'locale' => 'bn', 'timezone' => 'Asia/Dhaka', 'clear_logo' => true, 'slug' => 'ignored-here',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertNull($tenant->branding['logo_path']);
        $this->assertSame('test-a', $tenant->slug);
        Storage::disk('public')->assertMissing($logo);
        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    public function test_changing_the_subdomain_is_an_explicit_confirmed_and_audited_action(): void
    {
        $admin = $this->actingAsSuper();
        $this->post(route('super.tenants.store', absolute: false), $this->payload())->assertRedirect();
        $tenant = Tenant::query()->where('slug', self::SLUG)->firstOrFail();
        $resolver = app(TenantResolver::class);
        $this->assertSame($tenant->id, $resolver->resolve(self::SLUG.'.bp.test')?->id);

        $url = route('super.tenants.slug', ['tenant' => $tenant->public_id], false);
        $this->post($url, ['slug' => 'sunrise-moved', 'confirm_slug' => 'wrong'])->assertSessionHasErrors('confirm_slug');
        $this->post($url, ['slug' => 'super', 'confirm_slug' => self::SLUG])->assertSessionHasErrors('slug');
        $this->post($url, ['slug' => 'test-a', 'confirm_slug' => self::SLUG])->assertSessionHasErrors('slug');
        $this->post($url, ['slug' => self::SLUG, 'confirm_slug' => self::SLUG])->assertSessionHasErrors('slug');
        $this->assertSame(self::SLUG, $tenant->refresh()->slug);

        $this->post($url, ['slug' => 'Sunrise-Moved', 'confirm_slug' => self::SLUG])
            ->assertRedirect(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertSame('sunrise-moved', $tenant->slug);
        $this->assertSame("tenant_{$tenant->id}", $tenant->schema_name, 'the schema is named after the id and never moves');
        $this->assertSame('sunrise-moved.bp.test', Domain::query()->where('tenant_id', $tenant->id)->where('type', DomainType::Subdomain->value)->value('domain'));
        $this->assertSame($tenant->id, $resolver->resolve('sunrise-moved.bp.test')?->id);
        $this->assertNull($resolver->resolve(self::SLUG.'.bp.test'), 'the old address stops resolving at once');

        $row = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Update->value)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $row->super_admin_id);
        $this->assertSame(self::SLUG, $row->before['slug'] ?? null);
        $this->assertSame('sunrise-moved', $row->after['slug'] ?? null);

        // The clinic still works on the new host.
        $this->withServerVariables(['HTTP_HOST' => 'sunrise-moved.bp.test']);
        $this->get('/panel')->assertRedirect(route('panel.login', absolute: false));
    }

    public function test_the_list_searches_filters_sorts_and_lists_deleted_clinics_with_their_export(): void
    {
        $this->actingAsSuper();
        $a = $this->tenant('a');
        Tenant::factory()->create(['name' => 'Aardvark Clinic', 'slug' => 'aardvark', 'status' => TenantStatus::Trial, 'trial_ends_at' => now()->addDays(3)]);
        Tenant::factory()->create(['name' => 'Zebra Clinic', 'slug' => 'zebra', 'status' => TenantStatus::Trial, 'trial_ends_at' => now()->addDays(40)]);
        Tenant::factory()->suspended()->create(['name' => 'Yak Clinic', 'slug' => 'yak']);
        SubscriptionInvoice::factory()->issued()->create(['tenant_id' => $a->id, 'total_paisa' => 150000, 'paid_paisa' => 0]);
        $gone = Tenant::factory()->create(['name' => 'Gone Clinic', 'slug' => 'gone']);
        $export = TenantBackup::factory()->completed()->create(['tenant_id' => $gone->id, 'type' => BackupType::Export, 'storage_path' => 'tenants/x/exports/gone.zip']);
        $gone->delete();

        $index = route('super.tenants.index', absolute: false);

        $this->get($index)->assertOk()->assertInertia(fn ($page) => $page->component('Super/Tenants/Index')
            ->has('plans.0.code')->has('attention')->has('sorts')->where('filters.sort', 'created')
            ->where('tenants', fn ($rows) => ! in_array('gone', self::slugs($rows), true)));

        $this->get($index.'?sort=name&dir=asc')->assertOk()->assertInertia(fn ($page) => $page->where('tenants.0.slug', 'aardvark'));
        $this->get($index.'?sort=name&dir=desc')->assertOk()->assertInertia(fn ($page) => $page->where('tenants.0.slug', 'zebra'));
        $this->get($index.'?attention=trial_ending')->assertOk()->assertInertia(fn ($page) => $page->where('tenants', fn ($rows) => self::slugs($rows) === ['aardvark']));
        $this->get($index.'?attention=arrears')->assertOk()->assertInertia(fn ($page) => $page->where('tenants', fn ($rows) => self::slugs($rows) === ['test-a'])->where('tenants.0.arrears_invoices', 1));
        $this->get($index.'?sort=arrears')->assertOk()->assertInertia(fn ($page) => $page->where('tenants.0.slug', 'test-a'));
        $this->get($index.'?plan=starter')->assertOk()->assertInertia(fn ($page) => $page->where('tenants', fn ($rows) => in_array('test-a', self::slugs($rows), true) && ! in_array('aardvark', self::slugs($rows), true)));
        $this->get($index.'?status=suspended')->assertOk()->assertInertia(fn ($page) => $page->where('tenants', fn ($rows) => self::slugs($rows) === ['yak']));
        $this->get($index.'?q=zebra')->assertOk()->assertInertia(fn ($page) => $page->where('tenants', fn ($rows) => self::slugs($rows) === ['zebra']));

        $this->get($index.'?status=deleted')->assertOk()->assertInertia(fn ($page) => $page
            ->where('tenants', fn ($rows) => self::slugs($rows) === ['gone'])
            ->has('tenants.0.deleted_at')
            ->where('tenants.0.last_export_id', $export->id));

        $this->get($index.'?sort=nope')->assertSessionHasErrors('sort');
    }

    public function test_deleting_a_clinic_is_two_steps_export_first_and_refused_while_an_invoice_is_unsettled(): void
    {
        Storage::fake('backups');
        Storage::fake('uploads');
        $admin = $this->actingAsSuper();
        $tenant = app(ProvisionTenant::class)->handle(new ProvisionTenantData(
            name: 'Doomed Clinic', slug: 'doomed-clinic', planCode: 'starter', ownerName: 'Owner', ownerEmail: 'owner@doomed.test',
            ownerMobile: '+8801700000009', adminEmail: 'owner@doomed.test', adminPassword: 'password',
        ));
        Tenancy::check() && Tenancy::end();
        $schema = $tenant->schema_name;
        $destroy = route('super.tenants.destroy', ['tenant' => $tenant->public_id], false);
        $prepare = route('super.tenants.deletion.prepare', ['tenant' => $tenant->public_id], false);

        // The typed slug and the acknowledgement are checked before anything else.
        $this->delete($destroy, ['slug' => 'wrong', 'acknowledge' => true])->assertSessionHasErrors('slug');
        $this->delete($destroy, ['slug' => 'doomed-clinic'])->assertSessionHasErrors('acknowledge');

        // No export yet → refused, nothing dropped.
        $this->delete($destroy, ['slug' => 'doomed-clinic', 'acknowledge' => true])->assertSessionHasErrors('domain');
        $this->assertNotNull(Tenant::query()->find($tenant->id));

        // Money on the table → step one itself is refused.
        $invoice = SubscriptionInvoice::factory()->issued()->create(['tenant_id' => $tenant->id, 'total_paisa' => 150000, 'paid_paisa' => 0]);
        $this->post($prepare)->assertSessionHasErrors('domain');
        $this->assertSame(0, TenantBackup::query()->where('tenant_id', $tenant->id)->count());
        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page->where('deletion.unsettled_invoices', 1)->where('deletion.export', null));

        $invoice->forceFill(['status' => 'void'])->save();

        // Step one: the export (queued; sync in tests) — the same ExportTenantData path as the Data tab.
        $this->post($prepare)->assertRedirect()->assertSessionHasNoErrors();
        $export = TenantBackup::query()->where('tenant_id', $tenant->id)->where('type', BackupType::Export->value)->firstOrFail();
        $this->assertSame(BackupStatus::Completed, $export->status);
        Storage::disk('backups')->assertExists((string) $export->storage_path);
        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page->where('deletion.export.id', $export->id));

        // Step two: the typed slug, and the schema goes.
        $this->delete($destroy, ['slug' => 'doomed-clinic', 'acknowledge' => true])
            ->assertRedirect(route('super.tenants.index', absolute: false))->assertSessionHasNoErrors();

        $this->assertNull(Tenant::query()->find($tenant->id), 'soft-deleted: out of every live query');
        $trashed = Tenant::withTrashed()->findOrFail($tenant->id);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(TenantStatus::Cancelled, $trashed->status);
        $this->assertSame(0, (int) DB::connection('pgsql')->scalar('select count(*) from information_schema.schemata where schema_name = ?', [$schema]), 'the schema is dropped');
        $this->assertSame(0, Domain::query()->where('tenant_id', $tenant->id)->count(), 'the hostnames are released');
        $this->assertSame(SubscriptionStatus::Cancelled, Subscription::query()->findOrFail($tenant->current_subscription_id)->status);
        $this->assertNull(app(TenantResolver::class)->resolve('doomed-clinic.bp.test'));
        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));

        $row = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Delete->value)->firstOrFail();
        $this->assertSame($admin->id, $row->super_admin_id);
        $this->assertSame($schema, $row->after['schema_dropped'] ?? null);
        $this->assertSame($export->storage_path, $row->after['export_path'] ?? null);

        // The slug stays claimed, the export stays downloadable from the deleted list, and the page itself is gone.
        $this->getJson(route('super.tenants.slug-check', ['slug' => 'doomed-clinic'], false))->assertJson(['available' => false, 'reason' => 'taken']);
        $this->get(route('super.tenants.index', absolute: false).'?status=deleted')->assertOk()
            ->assertInertia(fn ($page) => $page->where('tenants.0.slug', 'doomed-clinic')->where('tenants.0.last_export_id', $export->id));
        $this->get(route('super.tenants.archives.download', ['tenant' => $tenant->public_id, 'backup' => $export->id], false))->assertOk();
        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertNotFound();
    }

    public function test_reactivating_with_unpaid_invoices_says_so_instead_of_claiming_success(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $this->post(route('super.tenants.suspend', ['tenant' => $tenant->public_id], false), ['reason' => 'unpaid']);
        SubscriptionInvoice::factory()->issued()->create(['tenant_id' => $tenant->id, 'total_paisa' => 150000, 'paid_paisa' => 0, 'due_at' => now()->subDay()]);

        $this->post(route('super.tenants.reactivate', ['tenant' => $tenant->public_id], false), ['reason' => 'please'])
            ->assertRedirect()->assertSessionHas('flash.warning');
        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status);

        $this->post(route('super.tenants.reactivate', ['tenant' => $tenant->public_id], false), ['reason' => 'goodwill', 'force' => true])
            ->assertRedirect()->assertSessionHas('flash.success');
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
    }

    public function test_the_tenant_screens_are_closed_to_guests_and_tenant_staff(): void
    {
        $tenant = $this->tenant('a');
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'super.bp.test']);

        $this->get(route('super.tenants.create', absolute: false))->assertRedirect(route('super.login', absolute: false));
        $this->post(route('super.tenants.store', absolute: false), $this->payload())->assertRedirect(route('super.login', absolute: false));
        $this->delete(route('super.tenants.destroy', ['tenant' => $tenant->public_id], false), ['slug' => 'test-a', 'acknowledge' => true])->assertRedirect(route('super.login', absolute: false));
        $this->post(route('super.tenants.staff.store', ['tenant' => $tenant->public_id], false), [])->assertRedirect(route('super.login', absolute: false));

        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->withServerVariables(['HTTP_HOST' => 'super.bp.test']);
        $this->get(route('super.tenants.create', absolute: false))->assertRedirect(route('super.login', absolute: false));

        $this->assertNull(Tenant::query()->where('slug', self::SLUG)->first());
        $this->assertNotNull(Tenant::query()->find($tenant->id));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sunrise Hospital',
            'name_bn' => 'সানরাইজ হাসপাতাল',
            'slug' => self::SLUG,
            'plan' => 'starter',
            'trial_days' => null,
            'locale' => 'bn',
            'timezone' => 'Asia/Dhaka',
            'branch_name' => 'প্রধান শাখা',
            'owner_name' => 'ডা. সূর্য রহমান',
            'owner_email' => 'Owner@sunrise.test',
            'owner_mobile' => '01712345678',
            'admin_name' => '',
            'admin_email' => '',
            'credential' => 'password',
            'password' => 'first-light-2026',
            'demo' => false,
            'custom_domain' => '',
            'notes' => 'Negotiated by phone.',
        ], $overrides);
    }

    /**
     * The `tenants` prop of the list page (a Collection under Inertia's `where`), reduced to its slugs in order.
     *
     * @return array<int, string>
     */
    private static function slugs(mixed $rows): array
    {
        $out = [];

        foreach ($rows instanceof Collection ? $rows->all() : (is_array($rows) ? $rows : []) as $row) {
            $out[] = is_array($row) ? (string) ($row['slug'] ?? '') : '';
        }

        return $out;
    }

    private function clearSuper(): void
    {
        Auth::guard('super')->logout();
        $this->flushSession();
    }
}
