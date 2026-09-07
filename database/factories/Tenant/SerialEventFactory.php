<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Serials\Enums\ActorType;
use App\Domain\Serials\Enums\SerialEventType;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SerialEvent> */
final class SerialEventFactory extends Factory
{
    protected $model = SerialEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'serial_id' => Serial::factory(),
            'session_instance_id' => fn (array $attributes) => (int) Serial::query()->whereKey($attributes['serial_id'])->value('session_instance_id'),
            'type' => SerialEventType::Booked,
            'from_status' => null,
            'to_status' => 'booked',
            'actor_type' => ActorType::System,
            'meta' => ['source' => 'system'],
            'occurred_at' => now(),
        ];
    }

    public function sessionLevel(SerialEventType $type = SerialEventType::CapacityExtended): static
    {
        return $this->state(fn () => ['serial_id' => null, 'type' => $type, 'to_status' => null, 'meta' => ['by' => 5, 'reason' => null, 'source' => 'system']]);
    }
}
