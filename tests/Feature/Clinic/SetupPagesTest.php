<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\Specialty;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * BRIEF §5.A — every setup screen renders its page component with the props the page destructures, and every
 * create/edit round trip goes through the existing Clinic actions.
 */
final class SetupPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
    }

    // ---- branches ------------------------------------------------------------------------------------------

    public function test_branch_index_create_edit_render_and_round_trip(): void
    {
        Branch::factory()->create(['name' => 'Mirpur Chamber', 'code' => 'MIR', 'slug' => 'mirpur', 'is_main' => false]);

        $this->get('/panel/clinic/branches')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Branches/Index')
            ->has('branches.data', 2)
            // The pager reads meta/links, so the page must carry the resource envelope, not a flat paginator.
            ->has('branches.meta.last_page')
            ->has('branches.meta.total')
            ->has('branches.links.next')
            ->where('filters.q', '')
            ->where('can.manage', true)
            ->has('timezone'));

        $this->get('/panel/clinic/branches?q=mirpur')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('branches.data', 1)->where('branches.data.0.code', 'MIR'));

        $this->get('/panel/clinic/branches/create')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Branches/Create')->where('has_branches', true)->has('timezone'));

        $this->post('/panel/clinic/branches', [
            'name' => 'উত্তরা শাখা', 'code' => 'UTR', 'slug' => 'uttara', 'address' => 'Sector 7', 'phone' => '+8802...',
            'is_main' => false, 'is_active' => true,
        ])->assertRedirect('/panel/clinic/branches');

        $branch = Branch::query()->where('code', 'UTR')->firstOrFail();
        $this->assertSame('উত্তরা শাখা', $branch->name);

        $this->get('/panel/clinic/branches/'.$branch->public_id.'/edit')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Clinic/Branches/Edit')->where('branch.code', 'UTR'));

        $this->put('/panel/clinic/branches/'.$branch->public_id, ['name' => 'Uttara', 'code' => 'UTR', 'slug' => 'uttara', 'is_active' => true])
            ->assertRedirect('/panel/clinic/branches');
        $this->assertSame('Uttara', $branch->fresh()?->name);
    }

    public function test_branch_status_and_main_flags_go_through_the_action(): void
    {
        $branch = Branch::factory()->create(['is_main' => false, 'is_active' => true]);

        $this->patch('/panel/clinic/branches/'.$branch->public_id.'/status', ['is_active' => false])->assertRedirect();
        $this->assertFalse($branch->fresh()?->is_active);

        $this->post('/panel/clinic/branches/'.$branch->public_id.'/main')->assertRedirect();
        $this->assertTrue($branch->fresh()->is_main);
        $this->assertSame(1, Branch::query()->where('is_main', true)->count(), 'exactly one main branch survives');
    }

    // ---- departments and specialties ----------------------------------------------------------------------

    public function test_department_index_and_crud_keeps_both_names(): void
    {
        Department::factory()->create(['name' => 'Medicine', 'name_bn' => 'মেডিসিন']);

        $this->get('/panel/clinic/departments')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Departments/Index')
            ->has('departments', 1)
            ->where('departments.0.name_bn', 'মেডিসিন')
            ->has('branch_options')
            ->where('can.manage', true));

        $this->post('/panel/clinic/departments', ['name' => 'Gynaecology', 'name_bn' => 'গাইনি', 'slug' => 'gynae', 'sort_order' => 2, 'is_active' => true])
            ->assertRedirect('/panel/clinic/departments');

        $department = Department::query()->where('slug', 'gynae')->firstOrFail();
        $this->assertSame('Gynaecology', $department->name);
        $this->assertSame('গাইনি', $department->name_bn);

        $this->put('/panel/clinic/departments/'.$department->id, ['name' => 'Gynaecology', 'name_bn' => 'গাইনি ও প্রসূতি', 'slug' => 'gynae', 'is_active' => false])->assertRedirect();
        $this->assertSame('গাইনি ও প্রসূতি', $department->fresh()?->name_bn);
        $this->assertFalse($department->fresh()->is_active);

        // The Bangla name is optional: blanking it stores NULL rather than an empty string.
        $this->put('/panel/clinic/departments/'.$department->id, ['name' => 'Gynaecology', 'name_bn' => '', 'slug' => 'gynae'])->assertRedirect();
        $this->assertNull($department->fresh()->name_bn);

        $this->put('/panel/clinic/departments/'.$department->id, ['name' => 'Gynaecology', 'name_bn' => str_repeat('অ', 161), 'slug' => 'gynae'])
            ->assertSessionHasErrors('name_bn');

        $this->delete('/panel/clinic/departments/'.$department->id)->assertRedirect();
        $this->assertNull(Department::query()->find($department->id));
    }

    public function test_specialty_index_and_crud_keeps_both_names(): void
    {
        $this->get('/panel/clinic/specialties')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Specialties/Index')->has('specialties')->where('can.manage', true));

        $this->post('/panel/clinic/specialties', ['name' => 'Cardiology', 'name_bn' => 'হৃদরোগ', 'slug' => 'cardiology'])
            ->assertRedirect('/panel/clinic/specialties');

        $specialty = Specialty::query()->where('slug', 'cardiology')->firstOrFail();
        $this->assertSame('হৃদরোগ', $specialty->name_bn);

        $this->put('/panel/clinic/specialties/'.$specialty->id, ['name' => 'Cardiology', 'name_bn' => 'হৃদ্‌রোগ', 'slug' => 'cardiology'])->assertRedirect();
        $this->assertSame('হৃদ্‌রোগ', $specialty->fresh()?->name_bn);

        $this->get('/panel/clinic/specialties')->assertInertia(fn (AssertableInertia $p) => $p->where('specialties.0.doctors_count', 0));

        $this->delete('/panel/clinic/specialties/'.$specialty->id)->assertRedirect();
        $this->assertNull(Specialty::query()->find($specialty->id));
    }

    // ---- doctors -------------------------------------------------------------------------------------------

    public function test_doctor_index_create_edit_render_and_round_trip(): void
    {
        $specialty = Specialty::factory()->create(['name' => 'Cardiology']);
        Doctor::factory()->complete()->create(['name' => 'Dr. Existing', 'code' => 'EXS']);

        $this->get('/panel/clinic/doctors')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Doctors/Index')
            ->has('doctors.data', 1)
            ->has('doctors.meta.total')
            ->has('departments')
            ->has('specialties')
            ->where('can.manage', true)
            ->where('can.schedule', true));

        $this->get('/panel/clinic/doctors/create')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Doctors/Create')->has('users')->has('specialties')->has('genders')->has('branch_options'));

        $this->post('/panel/clinic/doctors', [
            'name' => 'Dr. Rahman', 'name_bn' => 'ডা. রহমান', 'code' => 'RAH', 'slug' => 'dr-rahman', 'room_label' => 'Room 3',
            'specialty_ids' => [$specialty->id], 'primary_specialty_id' => $specialty->id,
            'profile' => ['degrees' => 'MBBS, FCPS', 'bmdc_reg_no' => 'A-12345', 'new_fee_paisa' => 80000, 'followup_fee_paisa' => 50000, 'free_followup_within_days' => 15, 'followup_within_days' => 30],
        ])->assertRedirect();

        $doctor = Doctor::query()->where('code', 'RAH')->firstOrFail();
        $this->assertSame(15, $doctor->profile?->free_followup_within_days, 'the billing engine reads this window');
        $this->assertNotNull($doctor->padSetting, 'CreateDoctor seeds the pad defaults');

        $this->get('/panel/clinic/doctors/'.$doctor->public_id.'/edit')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Doctors/Edit')
            ->where('doctor.code', 'RAH')
            ->where('doctor.profile.free_followup_within_days', 15)
            ->where('doctor.specialty_ids.0', $specialty->id)
            ->has('leaves', 0)
            ->where('can.design_pad', true));

        $this->put('/panel/clinic/doctors/'.$doctor->public_id, [
            'name' => 'Dr. Rahman', 'code' => 'RAH', 'slug' => 'dr-rahman',
            'specialty_ids' => [$specialty->id],
            'profile' => ['new_fee_paisa' => 90000, 'free_followup_within_days' => 7, 'followup_within_days' => 30],
        ])->assertRedirect();
        $this->assertSame(7, $doctor->fresh()?->profile?->free_followup_within_days);
    }

    public function test_the_doctor_form_rejects_a_free_window_longer_than_the_followup_window(): void
    {
        $this->post('/panel/clinic/doctors', [
            'name' => 'Dr. Bad', 'code' => 'BAD', 'slug' => 'dr-bad',
            'profile' => ['free_followup_within_days' => 40, 'followup_within_days' => 30],
        ])->assertSessionHasErrors('profile.free_followup_within_days');
    }

    public function test_creating_a_doctor_can_open_the_staff_login_in_the_same_submit(): void
    {
        $this->post('/panel/clinic/doctors', [
            'name' => 'Dr. New', 'code' => 'NEW', 'slug' => 'dr-new',
            'new_user' => ['name' => 'Dr. New', 'email' => 'new.doctor@test-a.test', 'mobile' => '+8801711111199'],
        ])->assertRedirect();

        $doctor = Doctor::query()->where('code', 'NEW')->firstOrFail();
        $this->assertNotNull($doctor->user_id);
        $this->assertTrue($doctor->user?->hasRole(Role::Doctor->value));
        $this->assertTrue($doctor->user->must_change_password, 'the doctor sets their own password from the reset link');
    }

    // ---- holidays ------------------------------------------------------------------------------------------

    public function test_holiday_year_view_and_crud(): void
    {
        Holiday::factory()->create(['holiday_date' => '2026-12-16', 'name' => 'Victory Day', 'name_bn' => 'বিজয় দিবস', 'branch_id' => null]);

        $this->get('/panel/clinic/holidays?year=2026')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Holidays/Index')
            ->has('holidays', 1)
            ->where('holidays.0.name_bn', 'বিজয় দিবস')
            ->where('filters.year', 2026)
            ->has('branch_options')
            ->has('today')
            ->where('can.manage', true));

        $this->post('/panel/clinic/holidays', ['holiday_date' => '2026-03-26', 'name' => 'Independence Day', 'name_bn' => 'স্বাধীনতা দিবস'])->assertRedirect();
        $this->assertSame(2, Holiday::query()->count());

        // The same date twice is a domain conflict, not a silent duplicate (HolidayAlreadyExists).
        $this->post('/panel/clinic/holidays', ['holiday_date' => '2026-03-26', 'name' => 'Again'])->assertSessionHasErrors('domain');

        $holiday = Holiday::query()->where('holiday_date', '2026-03-26')->firstOrFail();
        $this->delete('/panel/clinic/holidays/'.$holiday->id)->assertRedirect();
        $this->assertSame(1, Holiday::query()->count());
    }

    public function test_a_year_outside_the_calendar_range_falls_back_to_this_year(): void
    {
        $this->get('/panel/clinic/holidays?year=nonsense')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('filters.year', (int) now()->format('Y')));
    }
}
