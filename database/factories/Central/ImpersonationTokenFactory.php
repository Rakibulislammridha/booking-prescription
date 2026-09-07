<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Models\Central\ImpersonationToken;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ImpersonationToken> */
final class ImpersonationTokenFactory extends Factory
{
    protected $model = ImpersonationToken::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'super_admin_id' => SuperAdmin::factory(),
            'tenant_id' => Tenant::factory(),
            'user_id' => 1,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addSeconds(60),
        ];
    }

    public function consumed(): static
    {
        return $this->state(fn () => ['consumed_at' => now()]);
    }
}
