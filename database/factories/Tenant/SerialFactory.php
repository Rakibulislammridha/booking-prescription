<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Serials\Enums\SerialPool as Pool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Services\DisplayCode;
use App\Domain\Serials\Services\PositionService;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialPool;
use App\Models\Tenant\SessionInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A serial written straight into the table (bypassing AllocateSerial): takes the next counter number of the instance and
 * advances the pool cursor so the I-OWNER invariant stays intact. Prefer AllocateSerial in engine tests.
 *
 * @extends Factory<Serial>
 */
final class SerialFactory extends Factory
{
    protected $model = Serial::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'session_instance_id' => SessionInstance::factory(),
            'number' => function (array $attributes): int {
                /** @var SerialPool $pool */
                $pool = SerialPool::query()->where('session_instance_id', $attributes['session_instance_id'])->where('pool', Pool::Counter->value)->firstOrFail();
                $number = $pool->next_number;
                $pool->forceFill(['next_number' => $number + 1, 'issued_count' => $pool->issued_count + 1])->save();

                return $number;
            },
            'display_code' => fn (array $attributes) => DisplayCode::format((string) SessionInstance::query()->whereKey($attributes['session_instance_id'])->value('session_code'), (int) $attributes['number']),
            'position' => fn (array $attributes) => (int) $attributes['number'] * PositionService::GAP,
            'pool' => Pool::Counter,
            'status' => SerialStatus::Booked,
            'priority' => SerialPriority::Normal,
            'source' => SerialSource::Counter,
            'patient_id' => null,
            'appointment_id' => null,
            'booked_at' => now(),
            'passed_count' => 0,
            'skip_count' => 0,
        ];
    }

    public function checkedIn(): static
    {
        return $this->state(fn () => ['status' => SerialStatus::CheckedIn, 'checked_in_at' => now()]);
    }
}
