<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Role as RoleRecord;
use App\Models\Tenant\User;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The tenant SerialPolicy::checkIn's transitional fallback was written for: migrations run, seeder not.
 * `tenants:migrate --seed` skips suspended tenants (DEPLOYMENT §3.3 / OPERATIONS §2.2), so a clinic reactivated
 * across a release has the new tables and none of the new role and permission rows.
 *
 * On that tenant the check-in path degrades gracefully — and the staff Create screen did not: it offered
 * "Compounder" straight out of the ENUM and CreateStaffUser's `syncRoles(['compounder'])` then threw Spatie's
 * RoleDoesNotExist with nothing to catch it. An unhandled 500 on the screen an admin opens while repairing exactly
 * that tenant.
 *
 * Deleting the `compounder` row is the whole simulation — it is precisely the state a missed seeder leaves — and
 * both halves of the fix are proved against it: the form no longer offers the role, and if anything reaches
 * syncRoles() anyway (another screen, an API client, the next role we add) the renderable answers with the command
 * that fixes it instead of a stack trace.
 */
final class UnseededRoleScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** The tenant as a missed seeder leaves it: the newest role has no row (Spatie's cache is flushed on delete). */
    private function forgetTheNewestRole(): void
    {
        RoleRecord::query()->where('name', Role::Compounder->value)->delete();
    }

    public function test_the_create_screen_offers_only_roles_the_tenant_actually_has(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->forgetTheNewestRole();

        $this->get(route('panel.clinic.staff.create', [], false))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('roles', array_values(array_diff(Role::values(), [Role::Compounder->value]))));
    }

    /** A seeded tenant is untouched: the picker is still the whole enum, in enum order. */
    public function test_a_seeded_tenant_offers_every_role_in_enum_order(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->get(route('panel.clinic.staff.create', [], false))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('roles', Role::values()));
    }

    public function test_assigning_a_role_the_tenant_never_seeded_names_the_remedy_instead_of_500ing(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->forgetTheNewestRole();

        $response = $this->from(route('panel.clinic.staff.create', [], false))->post(route('panel.clinic.staff.store', [], false), [
            'name' => 'Compounder Candidate',
            'email' => 'compounder.candidate@example.test',
            'role' => Role::Compounder->value,
        ]);

        $response->assertRedirect(route('panel.clinic.staff.create', [], false))->assertSessionHasErrors('domain');

        /** @var array<string, array<int, string>> $errors */
        $errors = session('errors')?->getBag('default')->getMessages() ?? [];
        $this->assertStringContainsString('tenants:migrate --seed', $errors['domain'][0]);
        $this->assertStringContainsString('--tenant='.$this->tenant('a')->slug, $errors['domain'][0]);

        $this->assertFalse(User::query()->where('email', 'compounder.candidate@example.test')->exists(), 'the half-made account was rolled back');
    }
}
