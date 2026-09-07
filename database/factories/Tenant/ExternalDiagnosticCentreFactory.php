<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\ExternalDiagnosticCentre;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExternalDiagnosticCentre> */
final class ExternalDiagnosticCentreFactory extends Factory
{
    protected $model = ExternalDiagnosticCentre::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Popular Diagnostic Centre '.$this->faker->unique()->numberBetween(1, 9999),
            'address' => 'Dhanmondi, Dhaka',
            'phone' => '+88017'.$this->faker->numerify('########'),
            'contact_person' => null,
            'notes' => null,
            'is_active' => true,
        ];
    }
}
