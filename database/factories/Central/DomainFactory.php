<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Enums\DomainType;
use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Enums\SslStatus;
use App\Models\Central\Domain;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Domain> */
final class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'domain' => strtolower($this->faker->unique()->domainWord().'.example.com'),
            'type' => DomainType::Custom,
            'is_primary' => false,
            'verification_status' => DomainVerificationStatus::Pending,
            'verification_token' => Str::random(40),
            'ssl_status' => SslStatus::None,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['verification_status' => DomainVerificationStatus::Verified, 'verified_at' => now()]);
    }

    public function subdomain(): static
    {
        return $this->state(fn () => ['type' => DomainType::Subdomain, 'is_primary' => true, 'verification_status' => DomainVerificationStatus::Verified, 'verified_at' => now()]);
    }
}
