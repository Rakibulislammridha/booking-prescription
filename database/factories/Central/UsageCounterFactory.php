<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Models\Central\Tenant;
use App\Models\Central\UsageCounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UsageCounter> */
final class UsageCounterFactory extends Factory
{
    protected $model = UsageCounter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'metric' => UsageMetric::Appointments->value,
            'period' => now()->format('Y-m'),
            'value' => 0,
        ];
    }

    public function gauge(UsageMetric $metric): static
    {
        return $this->state(fn () => ['metric' => $metric->value, 'period' => 'current']);
    }
}
