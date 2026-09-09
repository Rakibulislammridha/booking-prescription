<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Services\Totp;
use App\Models\Central\SuperAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

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

    /**
     * An operator who has been through enrolment — which, with `saas.two_factor.required` on (the default), is
     * what every real super admin looks like. `$secret` is accepted so a test can compute codes for the account.
     */
    public function withTwoFactor(?string $secret = null, int $recoveryCodes = 8): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => $secret ?? Totp::generateSecret(),
            'two_factor_recovery_codes' => array_map(fn () => hash('sha256', fake()->uuid()), range(1, $recoveryCodes)),
            'two_factor_confirmed_at' => Carbon::now(),
        ]);
    }

    /** Mid-enrolment: a secret exists, nothing has confirmed it, so the account is NOT protected yet. */
    public function enrolling(?string $secret = null): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => $secret ?? Totp::generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }
}
