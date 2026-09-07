<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\ActiveBranch;
use App\Http\Middleware\SetActiveBranch;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * HandleInertiaRequests::share() runs before SetTenantLocale / auth / SetActiveBranch: `locale`, `branch`,
 * `branches` and `auth` must be lazy so the rendered props reflect what those middleware resolved (C5).
 */
final class SharedPropsTest extends TestCase
{
    public function test_dashboard_props_carry_the_active_branch_resolved_by_set_active_branch(): void
    {
        $this->asTenant('a');
        $main = Branch::query()->where('is_main', true)->firstOrFail();
        $other = Branch::factory()->create(['name' => 'Second branch']);
        $user = User::factory()->withRole(Role::Receptionist)->create(['default_branch_id' => $main->id]);

        // Only the session key — no pre-set ActiveBranch, no actingAsStaff() shortcut: the middleware must resolve it.
        app(ActiveBranch::class)->flush();
        $this->actingAs($user, 'web')->withSession([SetActiveBranch::SESSION_KEY => $other->id]);

        $this->get('/panel')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard/Index')
                ->where('branch.id', $other->id)
                ->where('branch.name', 'Second branch')
                ->where('auth.guard', 'web')
                ->where('auth.user.id', $user->id)
                ->has('branches', 2));
    }

    public function test_dashboard_props_carry_the_tenant_locale_set_by_set_tenant_locale(): void
    {
        $this->tenant('a')->forceFill(['locale' => Locale::En])->save();
        $this->asTenant('a');
        $this->actingAsStaff(Role::Doctor);

        $this->get('/panel')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard/Index')
                ->where('locale', 'en')
                ->where('tenant.locale', 'en'));

        $this->assertSame('en', app()->getLocale());

        // A session choice wins over the tenant default.
        $this->withSession(['locale' => 'bn'])->get('/panel')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'bn')->where('tenant.locale', 'en'));
    }

    public function test_theme_and_features_serialise_as_objects_and_logo_url_is_a_url(): void
    {
        $tenant = $this->tenant('a');
        $tenant->forceFill(['branding' => ['primary_color' => '#112233', 'accent_color' => '#abcdef', 'on_primary_color' => '#ffffff', 'logo_path' => 'tenants/9001/branding/logo.png']])->save();
        $this->asTenant('a');

        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Home/Index'));
        $response = $this->withHeaders($this->inertiaHeaders())->get('/')->assertOk();

        $this->assertSame(Storage::disk('public')->url('tenants/9001/branding/logo.png'), $response->json('props.tenant.logo_url'));
        $this->assertStringStartsWith('http', (string) $response->json('props.tenant.logo_url'));
        $this->assertStringEndsWith('/storage/tenants/9001/branding/logo.png', (string) $response->json('props.tenant.logo_url'));
        $this->assertSame(['primary' => '#112233', 'accent' => '#abcdef', 'on-primary' => '#ffffff'], $response->json('props.tenant.theme'));

        $tenant->forceFill(['branding' => ['primary_color' => null, 'logo_path' => null]])->save();
        $this->asTenant('a');
        $raw = $this->withHeaders($this->inertiaHeaders())->get('/')->getContent();

        $this->assertStringContainsString('"theme":{}', $raw);
        $this->assertStringContainsString('"features":{}', $raw);
        $this->assertStringContainsString('"logo_url":null', $raw);
    }

    public function test_flash_props_come_from_the_session_and_are_null_when_unset(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::Accountant);

        $this->withSession(['flash.success' => 'Saved.'])->get('/panel')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flash.success', 'Saved.')
                ->where('flash.error', null)
                ->where('flash.warning', null)
                ->where('flash.info', null));
    }
}
