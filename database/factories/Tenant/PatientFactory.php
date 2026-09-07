<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Clinic\Enums\Gender;
use App\Domain\Patients\Enums\BloodGroup;
use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Domain\Patients\Enums\PatientSource;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Patient> */
final class PatientFactory extends Factory
{
    protected $model = Patient::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['মোঃ রহিম উদ্দিন', 'ফাতেমা খাতুন', 'আব্দুল করিম', 'নাসরিন সুলতানা', 'Jahid Hasan', 'Sumaiya Akter', 'Rafiq Islam']),
            'mobile' => '+88017'.$this->faker->unique()->numerify('########'),
            'is_mobile_owner' => true,
            'gender' => $this->faker->randomElement(Gender::cases()),
            'dob' => $this->faker->dateTimeBetween('-70 years', '-1 year')->format('Y-m-d'),
            'dob_is_estimated' => false,
            'blood_group' => $this->faker->optional(0.6)->randomElement(BloodGroup::cases()),
            'address' => $this->faker->optional()->streetAddress(),
            'district' => $this->faker->randomElement(['Dhaka', 'Chattogram', 'Sylhet', 'Rajshahi', 'Khulna']),
            'preferred_language' => 'bn',
            'tags' => [],
            'source' => PatientSource::Counter,
            'is_active' => true,
            'visit_count' => 0,
        ];
    }

    public function withEncryptedFields(): static
    {
        return $this->state(fn () => ['national_id' => (string) $this->faker->numerify('##########'), 'notes' => 'Prefers morning appointments.']);
    }

    public function estimatedAge(int $years): static
    {
        return $this->state(fn () => ['dob' => now()->subYears($years)->startOfYear()->format('Y-m-d'), 'dob_is_estimated' => true]);
    }

    /** A dependent registered on $primary's mobile, linked through patient_relations. */
    public function dependentOf(Patient $primary, RelationType $relation = RelationType::Child): static
    {
        return $this
            ->state(fn () => ['mobile' => $primary->mobile, 'is_mobile_owner' => false])
            ->afterCreating(fn (Patient $dependent) => PatientRelation::query()->create([
                'primary_patient_id' => $primary->id,
                'dependent_patient_id' => $dependent->id,
                'relation' => $relation,
            ]));
    }
}
