<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorProfile> */
final class DoctorProfileFactory extends Factory
{
    protected $model = DoctorProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'degrees' => 'MBBS, FCPS (Medicine)',
            'degrees_bn' => 'এমবিবিএস, এফসিপিএস (মেডিসিন)',
            'bmdc_reg_no' => 'A-'.$this->faker->numerify('#####'),
            'designation' => 'Consultant',
            'experience_years' => $this->faker->numberBetween(2, 30),
            'languages' => ['bn', 'en'],
            'new_fee_paisa' => 80000,
            'followup_fee_paisa' => 50000,
            'free_followup_within_days' => 15,
            'followup_within_days' => 30,
            'report_visit_free' => true,
            'online_booking_fee_delta_paisa' => 0,
            'advance_payment_required' => false,
            'prefs' => [],
        ];
    }
}
