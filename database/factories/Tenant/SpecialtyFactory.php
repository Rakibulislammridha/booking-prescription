<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Specialty> */
final class SpecialtyFactory extends Factory
{
    protected $model = Specialty::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $n = $this->faker->unique()->numberBetween(1, 9999);
        [$en, $bn] = $this->faker->randomElement([['Cardiology', 'হৃদরোগ'], ['ENT', 'নাক কান গলা'], ['Medicine', 'মেডিসিন'], ['Paediatrics', 'শিশুরোগ'], ['Dermatology', 'চর্মরোগ']]);

        return [
            'name' => $en,
            'name_bn' => $bn,
            'slug' => 'specialty-'.$n,
            'icon' => 'MedicalServices',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
