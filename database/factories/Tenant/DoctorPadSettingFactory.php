<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorPadSetting> */
final class DoctorPadSettingFactory extends Factory
{
    protected $model = DoctorPadSetting::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['doctor_id' => Doctor::factory()] + DoctorPadSetting::defaults();
    }

    public function preprinted(): static
    {
        return $this->state(fn () => ['preprinted_mode' => true, 'letterhead_enabled' => false]);
    }
}
