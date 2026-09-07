<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Branch> */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // One unique counter drives name, code and slug: the six area names repeat freely, `unique()` never exhausts.
        $n = $this->faker->unique()->numberBetween(1, 99999);
        $name = $this->faker->randomElement(['ধানমন্ডি শাখা', 'মিরপুর শাখা', 'উত্তরা শাখা', 'গুলশান শাখা', 'মতিঝিল শাখা', 'বনানী শাখা']).' '.$n;

        return [
            'name' => $name,
            'code' => 'B'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'slug' => 'branch-'.$n,
            'address' => 'বাড়ি ১২, রোড ৫, ঢাকা',
            'phone' => '+88017'.$this->faker->numerify('########'),
            'is_main' => false,
            'is_active' => true,
            'settings' => ['token_slip_width_mm' => 58, 'display_mode' => ['voice' => true, 'languages' => ['bn', 'en']]],
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => ['is_main' => true, 'slug' => 'main-'.Str::lower(Str::random(4))]);
    }
}
