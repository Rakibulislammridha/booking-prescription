<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Patients\Enums\MedicationSource;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientMedication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientMedication> */
final class PatientMedicationFactory extends Factory
{
    protected $model = PatientMedication::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        [$generic, $brand] = $this->faker->randomElement([['Metformin', 'Comet'], ['Amlodipine', 'Amdocal'], ['Atorvastatin', 'Atova'], ['Losartan', 'Losartan']]);

        return [
            'patient_id' => Patient::factory(),
            'generic_id' => null,
            'brand_id' => null,
            'custom_brand_id' => null,
            'generic_name' => $generic,
            'brand_name' => $brand,
            'dose_text' => $this->faker->randomElement(['1+0+1', '0+0+1', '1+1+1']),
            'source' => MedicationSource::Reported,
            'prescription_item_id' => null,
            'started_on' => $this->faker->boolean(60) ? $this->faker->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d') : null,
            'ended_on' => null,
            'is_active' => true,
            'notes' => null,
        ];
    }

    public function stopped(): static
    {
        return $this->state(fn () => ['is_active' => false, 'ended_on' => now()->subDay()->format('Y-m-d')]);
    }
}
