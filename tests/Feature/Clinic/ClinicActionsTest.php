<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Actions\CancelDoctorLeave;
use App\Domain\Clinic\Actions\CreateBranch;
use App\Domain\Clinic\Actions\CreateDepartment;
use App\Domain\Clinic\Actions\CreateDoctor;
use App\Domain\Clinic\Actions\CreateDoctorLeave;
use App\Domain\Clinic\Actions\CreateHoliday;
use App\Domain\Clinic\Actions\CreateSpecialty;
use App\Domain\Clinic\Actions\CreateStaffUser;
use App\Domain\Clinic\Actions\UpdateDoctorPadSettings;
use App\Domain\Clinic\Actions\UpdateStaffUser;
use App\Domain\Clinic\Data\BranchData;
use App\Domain\Clinic\Data\DepartmentData;
use App\Domain\Clinic\Data\DoctorData;
use App\Domain\Clinic\Data\DoctorLeaveData;
use App\Domain\Clinic\Data\DoctorProfileData;
use App\Domain\Clinic\Data\HolidayData;
use App\Domain\Clinic\Data\PadSettingsData;
use App\Domain\Clinic\Data\SpecialtyData;
use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Enums\LeaveType;
use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Events\DoctorLeaveCreated;
use App\Domain\Clinic\Exceptions\CannotDeactivateSelf;
use App\Domain\Clinic\Exceptions\HolidayAlreadyExists;
use App\Domain\Clinic\Exceptions\LeaveOverlaps;
use App\Domain\Clinic\Support\RoleMatrix;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DoctorLeave;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\Role as RoleModel;
use App\Models\Tenant\Specialty;
use App\Models\Tenant\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ClinicActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_roles_and_permissions_are_seeded_per_the_matrix(): void
    {
        $this->assertSame(Role::values(), RoleModel::query()->orderBy('id')->pluck('name')->all());

        foreach (Role::cases() as $role) {
            $expected = array_map(fn (Permission $p) => $p->value, RoleMatrix::permissionsFor($role));
            sort($expected);
            $actual = RoleModel::findByName($role->value)->permissions->pluck('name')->sort()->values()->all();
            $this->assertSame($expected, $actual, "matrix drift for {$role->value}");
        }

        $this->assertCount(count(Permission::cases()), RoleModel::findByName('hospital_admin')->permissions);
    }

    public function test_create_branch_makes_the_first_branch_main_and_keeps_a_single_main(): void
    {
        Branch::query()->delete();
        $first = app(CreateBranch::class)->handle(new BranchData(name: 'Dhanmondi', code: 'DHK', slug: 'dhanmondi'), Actor::system());
        $second = app(CreateBranch::class)->handle(new BranchData(name: 'Mirpur', code: 'MIR', slug: 'mirpur', isMain: true), Actor::system());

        $this->assertTrue($first->is_main);
        $this->assertTrue($second->is_main);
        $this->assertFalse($first->fresh()?->is_main);
        $this->assertSame(1, Branch::query()->where('is_main', true)->count());
        $this->assertSame(26, strlen($second->public_id));
    }

    public function test_create_staff_user_assigns_the_role_and_audits(): void
    {
        $user = app(CreateStaffUser::class)->handle(new StaffUserData(name: 'রিসেপশন', email: 'Desk@Test-A.test', role: Role::Receptionist, password: 'secret-123', mobile: '+8801711111111'), Actor::system());

        $this->assertTrue($user->hasRole('receptionist'));
        $this->assertTrue($user->can(Permission::SerialsIssueCounter->value));
        $this->assertFalse($user->can(Permission::ClinicUsersManage->value));
        $this->assertTrue(Hash::check('secret-123', $user->password));
        $this->assertAudited(AuditAction::Create, $user);

        $updated = app(UpdateStaffUser::class)->handle($user, new StaffUserData(name: 'রিসেপশন', email: 'desk@test-a.test', role: Role::Accountant), Actor::user(999));
        $this->assertSame(['accountant'], $updated->getRoleNames()->all());

        $this->assertThrows(fn () => app(UpdateStaffUser::class)->handle($user, new StaffUserData(name: 'x', email: 'desk@test-a.test', role: Role::Accountant, isActive: false), Actor::user($user->id)), CannotDeactivateSelf::class);
    }

    public function test_create_doctor_creates_profile_pad_defaults_and_specialties(): void
    {
        $user = User::factory()->create();
        $dept = app(CreateDepartment::class)->handle(new DepartmentData(name: 'মেডিসিন', slug: 'medicine'), Actor::system());
        $cardio = app(CreateSpecialty::class)->handle(new SpecialtyData(name: 'Cardiology', slug: 'cardiology', nameBn: 'হৃদরোগ'), Actor::system());
        $medicine = Specialty::factory()->create();

        $doctor = app(CreateDoctor::class)->handle(new DoctorData(
            name: 'Dr. Rahman', slug: 'dr-rahman', code: 'RAH',
            profile: new DoctorProfileData(degrees: 'MBBS, FCPS', bmdcRegNo: 'A-1', newFeePaisa: 80000, followupFeePaisa: 50000, freeFollowupWithinDays: 15),
            departmentId: $dept->id, userId: $user->id, specialtyIds: [$medicine->id, $cardio->id], primarySpecialtyId: $cardio->id,
        ), Actor::system());

        $this->assertSame(80000, $doctor->profile?->new_fee_paisa);
        $this->assertSame(15, $doctor->profile->free_followup_within_days);
        $this->assertSame('A5', $doctor->padSetting?->paper_size->value);
        $this->assertEqualsCanonicalizing(['top' => 20, 'right' => 15, 'bottom' => 20, 'left' => 15], $doctor->padSetting->margins);
        $this->assertSame('thermal_58', $doctor->padSetting->token_slip_template->value);
        $this->assertSame([$cardio->id], $doctor->doctorSpecialties()->where('is_primary', true)->pluck('specialty_id')->all());
        $this->assertCount(2, $doctor->specialties);
        $this->assertTrue($user->fresh()?->hasRole('doctor'));
        $this->assertSame($doctor->id, $user->doctor()->value('id'));
    }

    public function test_pad_designer_updates_partially_and_preprinted_mode_blanks_the_letterhead(): void
    {
        $doctor = app(CreateDoctor::class)->handle(new DoctorData(name: 'Dr. Pad', slug: 'dr-pad', code: 'PAD', profile: new DoctorProfileData), Actor::system());

        $setting = app(UpdateDoctorPadSettings::class)->handle($doctor, PadSettingsData::fromArray(['paper_size' => 'A4', 'preprinted_mode' => true, 'header_height_mm' => 40, 'unknown' => 'ignored']), Actor::system());

        $this->assertSame('A4', $setting->paper_size->value);
        $this->assertTrue($setting->preprinted_mode);
        $this->assertFalse($setting->letterhead_enabled);
        $this->assertSame(40, $setting->header_height_mm);
        $this->assertSame('portrait', $setting->orientation->value);
    }

    public function test_holidays_are_unique_per_date_and_branch(): void
    {
        $date = CarbonImmutable::parse('2026-12-16');
        $holiday = app(CreateHoliday::class)->handle(new HolidayData($date, 'Victory Day', 'বিজয় দিবস'), Actor::user(1));

        $this->assertSame('2026-12-16', $holiday->holiday_date->toDateString());
        $this->assertSame(1, $holiday->created_by_user_id);

        $this->assertThrows(fn () => app(CreateHoliday::class)->handle(new HolidayData($date, 'Again'), Actor::system()), HolidayAlreadyExists::class);

        $branch = Branch::query()->firstOrFail();
        app(CreateHoliday::class)->handle(new HolidayData($date, 'Branch only', branchId: $branch->id), Actor::system());
        $this->assertSame(2, Holiday::query()->count());
    }

    public function test_doctor_leave_rejects_overlaps_dispatches_after_commit_and_can_be_cancelled(): void
    {
        Event::fake([DoctorLeaveCreated::class]);
        $doctor = app(CreateDoctor::class)->handle(new DoctorData(name: 'Dr. Leave', slug: 'dr-leave', code: 'LEV', profile: new DoctorProfileData), Actor::system());

        $leave = app(CreateDoctorLeave::class)->handle(new DoctorLeaveData($doctor->id, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-03'), LeaveType::Planned, reason: 'ছুটি'), Actor::user(1));

        $this->assertSame('planned', $leave->type->value);
        Event::assertDispatched(DoctorLeaveCreated::class, fn (DoctorLeaveCreated $e) => $e->leave->is($leave));

        $this->assertThrows(fn () => app(CreateDoctorLeave::class)->handle(new DoctorLeaveData($doctor->id, CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2026-10-05'), LeaveType::Emergency), Actor::system()), LeaveOverlaps::class);

        $cancelled = app(CancelDoctorLeave::class)->handle($leave, Actor::system());
        $this->assertTrue($cancelled->is_cancelled);

        app(CreateDoctorLeave::class)->handle(new DoctorLeaveData($doctor->id, CarbonImmutable::parse('2026-10-03'), CarbonImmutable::parse('2026-10-05'), LeaveType::Emergency), Actor::system());
        $this->assertSame(2, $doctor->leaves()->count());
    }

    public function test_policies_follow_the_permission_matrix(): void
    {
        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $this->assertTrue($admin->can('create', Branch::class));
        $this->assertTrue($admin->can('create', User::class));

        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $this->assertFalse($receptionist->can('create', Branch::class));
        $this->assertFalse($receptionist->can('create', User::class));
        $this->assertTrue($receptionist->can('viewAny', Branch::class));

        $doctorUser = $this->actingAsDoctor();
        $doctor = $doctorUser->doctor()->firstOrFail();
        $this->assertTrue($doctorUser->can('designPad', $doctor));
        $this->assertFalse($receptionist->can('designPad', $doctor));
        $this->assertTrue($doctorUser->can('create', [DoctorLeave::class, $doctor->id]));
        $this->assertFalse($doctorUser->can('create', [DoctorLeave::class, $doctor->id + 1]));
    }
}
