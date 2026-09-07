<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSpecialty;
use App\Models\Tenant\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorSpecialty> */
final class DoctorSpecialtyFactory extends Factory
{
    protected $model = DoctorSpecialty::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'specialty_id' => Specialty::factory(),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}
