<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorDrugUsage;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorDrugUsage> */
final class DoctorDrugUsageFactory extends Factory
{
    protected $model = DoctorDrugUsage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory()->complete(),
            'icd10_code' => null,
            'generic_id' => $this->faker->unique()->numberBetween(100000, 999999),
            'brand_id' => null,
            'custom_brand_id' => null,
            'strength_id' => null,
            'period_month' => Clock::today()->startOfMonth()->toDateString(),
            'use_count' => 1,
            'last_dose' => ['dose_schedule' => '1+0+1', 'duration_days' => 5, 'timing' => 'after', 'instruction' => null, 'shorthand' => '1+0+1 5d af'],
            'last_used_at' => now(),
        ];
    }
}
