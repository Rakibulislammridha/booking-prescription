<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Domain\Clinic\Enums\Gender;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\DoctorProfile;
use App\Models\Tenant\DoctorSpecialty;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\Specialty;
use App\Models\Tenant\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Bangla-first demo clinic (tenants:create --demo): branches, departments, specialties, doctors with profiles,
 * pad settings and specialties, staff users with roles, holidays. Schedules/patients are seeded by their modules.
 * Idempotent: upserts by natural keys.
 */
final class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $main = Branch::query()->where('is_main', true)->first()
            ?? Branch::query()->create(['name' => 'ধানমন্ডি শাখা', 'code' => 'DHK', 'slug' => 'dhanmondi', 'is_main' => true, 'settings' => []]);

        $mirpur = Branch::query()->updateOrCreate(
            ['code' => 'MIR'],
            ['name' => 'মিরপুর শাখা', 'slug' => 'mirpur', 'address' => 'বাড়ি ৭, রোড ২, মিরপুর-১০, ঢাকা', 'phone' => '+8801711000002', 'is_main' => false, 'is_active' => true, 'settings' => ['token_slip_width_mm' => 80, 'display_mode' => ['voice' => true, 'languages' => ['bn']]]],
        );

        $medicine = Department::query()->updateOrCreate(['slug' => 'medicine'], ['name' => 'Medicine', 'name_bn' => 'মেডিসিন', 'sort_order' => 1]);
        $paediatrics = Department::query()->updateOrCreate(['slug' => 'paediatrics'], ['name' => 'Paediatrics', 'name_bn' => 'শিশু বিভাগ', 'sort_order' => 2]);

        $specialties = [];
        foreach ([['cardiology', 'Cardiology', 'হৃদরোগ', 'Favorite'], ['medicine', 'Medicine', 'মেডিসিন', 'MedicalServices'], ['paediatrics', 'Paediatrics', 'শিশুরোগ', 'ChildCare']] as $i => [$slug, $en, $bn, $icon]) {
            $specialties[$slug] = Specialty::query()->updateOrCreate(['slug' => $slug], ['name' => $en, 'name_bn' => $bn, 'icon' => $icon, 'sort_order' => $i + 1]);
        }

        $doctors = [
            ['slug' => 'dr-rahman', 'code' => 'RAH', 'name' => 'Dr. Md. Abdur Rahman', 'name_bn' => 'ডা. মোঃ আব্দুর রহমান', 'gender' => Gender::Male, 'department' => $medicine, 'specialties' => ['medicine', 'cardiology'], 'room' => 'Room 1',
                'profile' => ['degrees' => 'MBBS, FCPS (Medicine)', 'degrees_bn' => 'এমবিবিএস, এফসিপিএস (মেডিসিন)', 'bmdc_reg_no' => 'A-45210', 'designation' => 'Consultant Physician', 'new_fee_paisa' => 80000, 'followup_fee_paisa' => 50000, 'free_followup_within_days' => 15],
                'user' => ['email' => 'rahman@demo.test', 'mobile' => '+8801711000011']],
            ['slug' => 'dr-sultana', 'code' => 'SUL', 'name' => 'Dr. Nasrin Sultana', 'name_bn' => 'ডা. নাসরিন সুলতানা', 'gender' => Gender::Female, 'department' => $paediatrics, 'specialties' => ['paediatrics'], 'room' => 'Room 2',
                'profile' => ['degrees' => 'MBBS, DCH', 'degrees_bn' => 'এমবিবিএস, ডিসিএইচ', 'bmdc_reg_no' => 'A-58731', 'designation' => 'Child Specialist', 'new_fee_paisa' => 60000, 'followup_fee_paisa' => 40000, 'free_followup_within_days' => 7],
                'user' => ['email' => 'sultana@demo.test', 'mobile' => '+8801711000012']],
        ];

        foreach ($doctors as $d) {
            $user = User::query()->updateOrCreate(
                ['email' => $d['user']['email']],
                ['name' => $d['name_bn'], 'mobile' => $d['user']['mobile'], 'password' => Hash::make('password'), 'default_branch_id' => $main->id, 'locale' => 'bn', 'is_active' => true, 'email_verified_at' => now()],
            );
            $user->syncRoles([Role::Doctor->value]);

            $doctor = Doctor::query()->updateOrCreate(
                ['slug' => $d['slug']],
                ['user_id' => $user->id, 'name' => $d['name'], 'name_bn' => $d['name_bn'], 'code' => $d['code'], 'gender' => $d['gender'], 'mobile' => $d['user']['mobile'], 'email' => $d['user']['email'], 'department_id' => $d['department']->id, 'is_active' => true, 'accepts_online_booking' => true, 'room_label' => $d['room']],
            );

            DoctorProfile::query()->updateOrCreate(['doctor_id' => $doctor->id], $d['profile'] + ['languages' => ['bn', 'en'], 'followup_within_days' => 30, 'chamber_notes' => 'পুরাতন রিপোর্ট সাথে আনুন']);
            DoctorPadSetting::query()->firstOrCreate(['doctor_id' => $doctor->id], DoctorPadSetting::defaults());

            foreach ($d['specialties'] as $i => $slug) {
                DoctorSpecialty::query()->updateOrCreate(['doctor_id' => $doctor->id, 'specialty_id' => $specialties[$slug]->id], ['is_primary' => $i === 0]);
            }
        }

        // The compounder is a Receptionist by role — that is the role RoleMatrix gives `prescriptions.vitals.record`
        // — but a separate person, because BRIEF §5.G.2's handoff is only legible in the demo if the vitals were
        // taken by someone other than the doctor and the front desk (VitalsDemoSeeder records as this user).
        foreach ([['reception@demo.test', 'রিসেপশন ডেস্ক', Role::Receptionist, $main], ['reception-mirpur@demo.test', 'মিরপুর রিসেপশন', Role::Receptionist, $mirpur], ['compounder@demo.test', 'কম্পাউন্ডার', Role::Receptionist, $main], ['accounts@demo.test', 'হিসাব বিভাগ', Role::Accountant, $main]] as [$email, $name, $role, $branch]) {
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'default_branch_id' => $branch->id, 'locale' => 'bn', 'is_active' => true, 'email_verified_at' => now()],
            );
            $user->syncRoles([$role->value]);
        }

        // A clinic that already has visits (a demo tenant re-seeded, or `tenants:seed --class=DemoDataSeeder` on a
        // running one) gets its vitals history too; on a brand-new tenant there are no visits yet and this is a
        // no-op. Idempotent either way.
        (new VitalsDemoSeeder)->run();

        foreach ([['2026-12-16', 'Victory Day', 'বিজয় দিবস'], ['2026-03-26', 'Independence Day', 'স্বাধীনতা দিবস'], ['2026-02-21', 'International Mother Language Day', 'আন্তর্জাতিক মাতৃভাষা দিবস']] as [$date, $en, $bn]) {
            Holiday::query()->updateOrCreate(['holiday_date' => $date, 'branch_id' => null], ['name' => $en, 'name_bn' => $bn]);
        }
    }
}
