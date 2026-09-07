<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientRelation> */
final class PatientRelationFactory extends Factory
{
    protected $model = PatientRelation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'primary_patient_id' => Patient::factory(),
            'dependent_patient_id' => Patient::factory()->state(['is_mobile_owner' => false]),
            'relation' => $this->faker->randomElement(RelationType::cases()),
        ];
    }
}
