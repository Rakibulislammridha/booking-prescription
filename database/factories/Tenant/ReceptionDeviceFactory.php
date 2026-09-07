<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Reception\Enums\DeviceKind;
use App\Domain\Reception\Enums\DeviceStatus;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ReceptionDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReceptionDevice> */
final class ReceptionDeviceFactory extends Factory
{
    protected $model = ReceptionDevice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => fn () => Branch::query()->where('is_main', true)->value('id') ?? Branch::factory()->main()->create()->id,
            // unique per process (not max+1: Factory::count() builds every instance before the first insert); registration numbers from 1 never reach 100
            'number' => $this->faker->unique()->numberBetween(100, 9000),
            'name' => 'Front desk '.$this->faker->numberBetween(1, 9),
            'kind' => DeviceKind::Reception,
            'device_fingerprint' => hash('sha256', $this->faker->unique()->uuid()),
            'app_version' => '1.0.0',
            'status' => DeviceStatus::Active,
            'block_size' => 5,
        ];
    }

    public function display(): static
    {
        return $this->state(fn () => ['kind' => DeviceKind::Display, 'name' => 'Waiting room TV']);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => DeviceStatus::Revoked, 'revoked_at' => now()]);
    }
}
