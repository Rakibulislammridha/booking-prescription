<?php

declare(strict_types=1);

namespace Tests\Feature\Panel;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Clinic\Enums\Role;
use App\Http\Middleware\SetActiveBranch;
use App\Models\Tenant\Branch;
use App\Tenancy\Facades\Tenancy;
use Tests\TestCase;

/** PATCH /panel/locale (panel.locale) and PATCH /panel/branch (panel.branch.switch) — C9. */
final class LocaleAndBranchSwitchTest extends TestCase
{
    public function test_staff_can_switch_the_locale_and_it_is_remembered_in_the_session_and_on_the_user(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsStaff(Role::Receptionist);
        $this->assertSame(Locale::Bn, $user->locale);

        $this->from('/panel')->patch('/panel/locale', ['locale' => 'en'])->assertRedirect('http://test-a.bp.test/panel');

        $this->assertSame('en', session('locale'));
        $this->assertSame(Locale::En, $user->fresh()?->locale);

        $this->withHeaders($this->inertiaHeaders())->get('/panel')->assertOk()->assertJsonPath('props.locale', 'en');
    }

    public function test_guests_can_switch_the_locale_on_the_login_page_and_invalid_values_are_rejected(): void
    {
        $this->asTenant('a');

        $this->from('/panel/login')->patch('/panel/locale', ['locale' => 'en'])->assertRedirect('http://test-a.bp.test/panel/login');
        $this->assertSame('en', session('locale'));

        $this->from('/panel/login')->patch('/panel/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->assertSame('en', session('locale'));
    }

    public function test_site_visitors_can_switch_the_locale(): void
    {
        $this->asTenant('a');

        $this->from('/')->patch('/locale', ['locale' => 'en'])->assertRedirect('http://test-a.bp.test');
        $this->assertSame('en', session('locale'));
        $this->withHeaders($this->inertiaHeaders())->get('/')->assertOk()->assertJsonPath('props.locale', 'en');
    }

    public function test_staff_can_switch_to_another_active_branch_of_their_tenant(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
        $other = Branch::factory()->create(['name' => 'Uttara']);

        $this->from('/panel')->patch('/panel/branch', ['branch_id' => $other->id])->assertRedirect('http://test-a.bp.test/panel');

        $this->assertSame($other->id, session(SetActiveBranch::SESSION_KEY));
        $this->withHeaders($this->inertiaHeaders())->get('/panel')->assertOk()->assertJsonPath('props.branch.id', $other->id);
    }

    public function test_inactive_missing_or_foreign_branches_are_rejected(): void
    {
        $this->asTenant('b');
        $foreign = Branch::factory()->create();          // exists only in tenant B's schema

        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
        $inactive = Branch::factory()->create(['is_active' => false]);
        $before = session(SetActiveBranch::SESSION_KEY);

        $this->from('/panel')->patch('/panel/branch', ['branch_id' => $inactive->id])->assertSessionHasErrors('branch_id');
        $this->from('/panel')->patch('/panel/branch', ['branch_id' => 999999])->assertSessionHasErrors('branch_id');
        $this->from('/panel')->patch('/panel/branch', ['branch_id' => $foreign->id])->assertSessionHasErrors('branch_id');
        $this->from('/panel')->patch('/panel/branch', [])->assertSessionHasErrors('branch_id');

        $this->assertSame($before, session(SetActiveBranch::SESSION_KEY));
        $this->assertSame(9001, Tenancy::id());
    }

    public function test_guests_cannot_switch_branches(): void
    {
        $this->asTenant('a');

        $this->patch('/panel/branch', ['branch_id' => 1])->assertRedirect('http://test-a.bp.test/panel/login');
    }
}
