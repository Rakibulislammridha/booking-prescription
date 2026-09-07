<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vital> */
final class VitalFactory extends Factory
{
    protected $model = Vital::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $weight = $this->faker->randomFloat(1, 45, 90);
        $height = $this->faker->numberBetween(150, 180);

        return [
            'visit_id' => Visit::factory(),
            'patient_id' => fn (array $attributes) => Visit::query()->whereKey($attributes['visit_id'])->value('patient_id'),
            'recorded_by_user_id' => null,
            'recorded_at' => now(),
            'bp_systolic' => $this->faker->numberBetween(100, 140),
            'bp_diastolic' => $this->faker->numberBetween(60, 90),
            'pulse_bpm' => $this->faker->numberBetween(60, 100),
            'temperature_c' => $this->faker->randomFloat(1, 36.2, 38.5),
            'spo2_percent' => $this->faker->numberBetween(94, 99),
            'respiratory_rate' => $this->faker->numberBetween(12, 20),
            'weight_kg' => $weight,
            'height_cm' => $height,
            'bmi' => fn (array $a) => Vital::computeBmi(isset($a['weight_kg']) ? (float) $a['weight_kg'] : null, isset($a['height_cm']) ? (float) $a['height_cm'] : null),
            'blood_glucose_mgdl' => null,
            'notes' => null,
            'edited_by_doctor' => false,
            'reviewed_by_doctor_at' => null,
        ];
    }

    public function pediatric(float $weightKg = 12.0): static
    {
        return $this->state(fn () => ['weight_kg' => $weightKg, 'height_cm' => 85]);
    }

    public function reviewed(): static
    {
        return $this->state(fn () => ['reviewed_by_doctor_at' => now()]);
    }
}
