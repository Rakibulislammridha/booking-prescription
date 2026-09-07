<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Serials\Enums\SerialPool as Pool;
use App\Models\Tenant\SerialPool;
use App\Models\Tenant\SessionInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A single pool row. SessionInstanceFactory already creates the three pools of an instance; use this factory only to
 * build an instance without pools first (`SessionInstance::factory()->create()` then `->pools()->delete()`) or in
 * constraint tests. Defaults to a counter pool [1, 10] on a fresh instance whose own pools are removed.
 *
 * @extends Factory<SerialPool>
 */
final class SerialPoolFactory extends Factory
{
    protected $model = SerialPool::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_instance_id' => function (): int {
                $instance = SessionInstance::factory()->create();
                $instance->pools()->delete();

                return $instance->id;
            },
            'pool' => Pool::Counter,
            'range_start' => 1,
            'range_end' => 10,
            'next_number' => 1,
            'issued_count' => 0,
            'lock_version' => 0,
        ];
    }

    public function range(Pool $pool, int $start, int $end): static
    {
        return $this->state(fn () => ['pool' => $pool, 'range_start' => $start, 'range_end' => $end, 'next_number' => $start]);
    }
}
