<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Specialty;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BRIEF §5.N role-scoped access, asserted at the HTTP edge for all four roles: a permission that exists in
 * RoleMatrix but is not enforced by a route is worth nothing.
 *
 * Read access is deliberately wide (an active staff member may LOOK at the clinic's structure — the reception desk
 * needs the branch and doctor lists to do its job); writing is what the permissions gate. The two exceptions are
 * the staff list (`clinic.users.manage`, because it exposes every colleague's e-mail and last login) and the pad
 * designer, which a doctor may open only for their own pad.
 */
final class SetupPolicyMatrixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /**
     * @return array<string, array{0: string, 1: array<int, string>, 2: array<int, string>}>
     */
    public static function roleMatrix(): array
    {
        $readable = [
            '/panel/clinic/branches',
            '/panel/clinic/departments',
            '/panel/clinic/specialties',
            '/panel/clinic/doctors',
            '/panel/clinic/holidays',
            '/panel/clinic/leaves',
            '/panel/clinic/settings',
        ];

        return [
            'hospital admin sees everything' => ['hospital_admin', [...$readable, '/panel/clinic/staff'], []],
            'doctor sees the clinic but not the staff list' => ['doctor', $readable, ['/panel/clinic/staff']],
            'receptionist sees the clinic but not the staff list' => ['receptionist', $readable, ['/panel/clinic/staff']],
            'accountant sees the clinic but not the staff list' => ['accountant', $readable, ['/panel/clinic/staff']],
            'compounder sees the clinic but not the staff list' => ['compounder', $readable, ['/panel/clinic/staff']],
        ];
    }

    /**
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $forbidden
     */
    #[DataProvider('roleMatrix')]
    public function test_read_access_by_role(string $role, array $allowed, array $forbidden): void
    {
        $this->actingAsStaff(Role::from($role));

        foreach ($allowed as $path) {
            $this->assertSame(200, $this->get($path)->getStatusCode(), "{$role} should be able to open {$path}");
        }

        foreach ($forbidden as $path) {
            $this->assertSame(403, $this->get($path)->getStatusCode(), "{$role} must not be able to open {$path}");
        }
    }

    public function test_only_a_hospital_admin_may_write_the_clinic_structure(): void
    {
        $branch = Branch::query()->where('is_main', true)->firstOrFail();
        $department = Department::factory()->create();
        $specialty = Specialty::factory()->create();
        $doctor = Doctor::factory()->complete()->create(['code' => 'PLC', 'slug' => 'dr-policy']);

        foreach ([Role::Doctor, Role::Receptionist, Role::Accountant] as $role) {
            $this->actingAsStaff($role);

            $this->post('/panel/clinic/branches', ['name' => 'X', 'code' => 'XX1', 'slug' => 'x1'])->assertForbidden();
            $this->put('/panel/clinic/branches/'.$branch->public_id, ['name' => 'X', 'code' => 'XX2', 'slug' => 'x2'])->assertForbidden();
            $this->patch('/panel/clinic/branches/'.$branch->public_id.'/status', ['is_active' => false])->assertForbidden();
            $this->post('/panel/clinic/departments', ['name' => 'X'])->assertForbidden();
            $this->put('/panel/clinic/departments/'.$department->id, ['name' => 'X'])->assertForbidden();
            $this->delete('/panel/clinic/departments/'.$department->id)->assertForbidden();
            $this->post('/panel/clinic/specialties', ['name' => 'X'])->assertForbidden();
            $this->delete('/panel/clinic/specialties/'.$specialty->id)->assertForbidden();
            $this->post('/panel/clinic/doctors', ['name' => 'X', 'code' => 'XX3'])->assertForbidden();
            $this->put('/panel/clinic/doctors/'.$doctor->public_id, ['name' => 'X', 'code' => 'PLC'])->assertForbidden();
            $this->post('/panel/clinic/staff', ['name' => 'X', 'email' => 'x@test-a.test', 'role' => 'receptionist'])->assertForbidden();
            $this->post('/panel/clinic/holidays', ['holiday_date' => '2026-05-01', 'name' => 'X'])->assertForbidden();
            $this->put('/panel/clinic/settings', ['values' => ['queue.notify_ahead' => 1]])->assertForbidden();
            $this->put('/panel/clinic/branding', ['name' => 'X', 'locale' => 'bn'])->assertForbidden();
        }

        $this->actingAsStaff(Role::HospitalAdmin);
        $this->post('/panel/clinic/departments', ['name' => 'Allowed'])->assertRedirect();
        $this->post('/panel/clinic/holidays', ['holiday_date' => '2026-05-01', 'name' => 'May Day'])->assertRedirect();
        $this->put('/panel/clinic/settings', ['values' => ['queue.notify_ahead' => 1]])->assertRedirect();
    }

    public function test_the_pad_designer_is_the_doctors_own_and_the_admins_any(): void
    {
        $doctor = Doctor::factory()->complete()->create(['code' => 'PAD', 'slug' => 'dr-pad']);

        $this->actingAsStaff(Role::HospitalAdmin);
        $this->get('/panel/clinic/doctors/'.$doctor->public_id.'/pad')->assertOk();
        $this->get('/panel/clinic/doctors/'.$doctor->public_id.'/pad/test-print')->assertOk();

        foreach ([Role::Receptionist, Role::Accountant] as $role) {
            $this->actingAsStaff($role);
            $this->get('/panel/clinic/doctors/'.$doctor->public_id.'/pad')->assertForbidden();
            $this->get('/panel/clinic/doctors/'.$doctor->public_id.'/pad/test-print')->assertForbidden();
        }

        $doctorUser = $this->actingAsDoctor();
        $own = $doctorUser->doctor()->firstOrFail();
        $this->get('/panel/clinic/doctors/'.$own->public_id.'/pad')->assertOk();
        $this->get('/panel/clinic/doctors/'.$doctor->public_id.'/pad')->assertForbidden();
    }

    public function test_leave_is_managed_by_admins_and_by_a_doctor_for_themselves(): void
    {
        $other = Doctor::factory()->complete()->create(['code' => 'LV1', 'slug' => 'dr-lv1']);

        $this->actingAsStaff(Role::Receptionist);
        $this->post('/panel/clinic/leaves', ['doctor_id' => $other->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-02'])->assertForbidden();

        $doctorUser = $this->actingAsDoctor();
        $own = $doctorUser->doctor()->firstOrFail();
        $this->post('/panel/clinic/leaves', ['doctor_id' => $own->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-02'])->assertRedirect();
        $this->post('/panel/clinic/leaves', ['doctor_id' => $other->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-02'])->assertForbidden();

        $this->actingAsStaff(Role::HospitalAdmin);
        $this->post('/panel/clinic/leaves', ['doctor_id' => $other->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-02'])->assertRedirect();
    }

    public function test_an_inactive_staff_member_reaches_nothing(): void
    {
        $user = $this->actingAsStaff(Role::HospitalAdmin);
        $user->forceFill(['is_active' => false])->save();

        // An account deactivated mid-session is signed out on its very next request by EnsureStaffIsActive (B1),
        // so it never even reaches the setup policies — a redirect to login, not a 403.
        $this->get('/panel/clinic/branches')->assertRedirect(route('panel.login', absolute: false));
        $this->get('/panel/clinic/doctors')->assertRedirect(route('panel.login', absolute: false));
        $this->assertGuest('web');
    }

    public function test_the_setup_screens_write_only_inside_the_acting_tenant(): void
    {
        $this->assertTenantIsolated('departments', function (): void {
            $this->actingAsStaff(Role::HospitalAdmin);
            $this->post('/panel/clinic/departments', ['name' => 'Isolation', 'slug' => 'isolation'])->assertRedirect();
        });
    }
}
