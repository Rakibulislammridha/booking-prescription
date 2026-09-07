<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\SerialPool as Pool;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SerialPool;
use App\Models\Tenant\SessionInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A device lease carved from the counter pool of a fresh instance: [1, 5] leased to device #1 with the pool cursor
 * advanced past it, so the CHECKs and the I-OWNER invariant hold. Use LeaseBlock for realistic flows.
 *
 * @extends Factory<SerialBlock>
 */
final class SerialBlockFactory extends Factory
{
    protected $model = SerialBlock::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_instance_id' => SessionInstance::factory(),
            'serial_pool_id' => function (array $attributes): int {
                /** @var SerialPool $pool */
                $pool = SerialPool::query()->where('session_instance_id', $attributes['session_instance_id'])->where('pool', Pool::Counter->value)->firstOrFail();
                $end = min($pool->range_end, $pool->next_number + 4);
                $pool->forceFill(['next_number' => $end + 1, 'issued_count' => $pool->issued_count + ($end - $pool->next_number + 1)])->save();

                return $pool->id;
            },
            'reception_device_id' => 1,
            'range_start' => 1,
            'range_end' => 5,
            'next_number' => 1,
            'status' => BlockStatus::Active,
            'leased_at' => now(),
            'leased_by_user_id' => null,
            'expires_at' => now()->addHours(6),
            'returned_count' => 0,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (array $attributes) => [
            'reception_device_id' => null,
            'status' => BlockStatus::Released,
            'released_at' => now(),
            'returned_count' => (int) $attributes['range_end'] - (int) $attributes['next_number'] + 1,
        ]);
    }
}
