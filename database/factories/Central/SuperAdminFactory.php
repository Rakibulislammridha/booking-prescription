<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Models\Central\SuperAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SuperAdmin> */
final class SuperAdminFactory extends Factory
{
    protected $model = SuperAdmin::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
