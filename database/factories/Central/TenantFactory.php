<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Enums\TenantStatus;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates the public row only (no schema). Use ProvisionTenant for a usable tenant.
 *
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->unique()->company().' Clinic';
        $slug = Str::slug($name).'-'.$this->faker->unique()->numerify('###');

        return [
            'name' => $name,
            'slug' => $slug,
            'schema_name' => 'tenant_'.str_replace('-', '_', $slug),
            'status' => TenantStatus::Trial,
            'timezone' => 'Asia/Dhaka',
            'locale' => 'bn',
            'currency' => 'BDT',
            'owner_name' => $this->faker->name(),
            'owner_email' => $this->faker->unique()->safeEmail(),
            'owner_mobile' => '+88017'.$this->faker->numerify('########'),
            'trial_ends_at' => now()->addDays(14),
            'onboarding' => ['step' => 'branches', 'demo_seeded' => false, 'completed_at' => null],
            'branding' => ['primary_color' => '#0f766e', 'logo_path' => null, 'favicon_path' => null, 'tagline_bn' => null, 'tagline_en' => null],
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Active, 'trial_ends_at' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Suspended, 'suspended_at' => now(), 'suspension_reason' => 'Unpaid invoice']);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Cancelled]);
    }
}
