<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Patients\Enums\ConditionStatus;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientCondition> */
final class PatientConditionFactory extends Factory
{
    protected $model = PatientCondition::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        [$code, $name] = $this->faker->randomElement([['I10', 'Essential hypertension'], ['E11.9', 'Type 2 diabetes mellitus'], ['J45.9', 'Asthma'], [null, 'Chronic back pain']]);

        return [
            'patient_id' => Patient::factory(),
            'icd10_code' => $code,
            'condition_name' => $name,
            'status' => $this->faker->randomElement([ConditionStatus::Active, ConditionStatus::Chronic]),
            'onset_date' => $this->faker->boolean(60) ? $this->faker->dateTimeBetween('-10 years', '-1 month')->format('Y-m-d') : null,
            'resolved_date' => null,
            'notes' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['status' => ConditionStatus::Resolved, 'resolved_date' => now()->subDays(7)->format('Y-m-d')]);
    }
}
