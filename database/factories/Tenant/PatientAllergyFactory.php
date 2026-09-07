<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Patients\Enums\AllergenType;
use App\Domain\Patients\Enums\AllergySeverity;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientAllergy> */
final class PatientAllergyFactory extends Factory
{
    protected $model = PatientAllergy::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'allergen_type' => AllergenType::Food,
            'generic_id' => null,
            'allergy_class_id' => null,
            'allergen_name' => $this->faker->randomElement(['Shrimp', 'Peanut', 'Egg', 'Dust', 'Pollen']),
            'reaction' => $this->faker->optional()->randomElement(['Rash', 'Swelling', 'Breathing difficulty']),
            'severity' => $this->faker->randomElement(AllergySeverity::cases()),
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function generic(int $genericId, string $name = 'Amoxicillin'): static
    {
        return $this->state(fn () => ['allergen_type' => AllergenType::Generic, 'generic_id' => $genericId, 'allergen_name' => $name]);
    }

    public function allergyClass(int $classId, string $name = 'Penicillins'): static
    {
        return $this->state(fn () => ['allergen_type' => AllergenType::AllergyClass, 'allergy_class_id' => $classId, 'allergen_name' => $name]);
    }
}
