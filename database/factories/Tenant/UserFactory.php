<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['মোঃ রহিম উদ্দিন', 'ফাতেমা খাতুন', 'আব্দুল করিম', 'নাসরিন সুলতানা', 'জাহিদ হাসান']),
            'email' => $this->faker->unique()->safeEmail(),
            'mobile' => '+88017'.$this->faker->unique()->numerify('########'),
            'password' => 'password',
            'email_verified_at' => now(),
            'locale' => 'bn',
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    public function withRole(Role|string $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role instanceof Role ? $role->value : $role));
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
