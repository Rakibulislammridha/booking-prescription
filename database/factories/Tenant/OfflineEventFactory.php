<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Reception\Enums\OfflineEventStatus;
use App\Domain\Reception\Enums\OfflineEventType;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\ReceptionDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OfflineEvent> */
final class OfflineEventFactory extends Factory
{
    protected $model = OfflineEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reception_device_id' => ReceptionDevice::factory(),
            'client_event_id' => (string) Str::ulid(),
            'sequence_no' => $this->faker->unique()->numberBetween(1, 100000),
            'type' => OfflineEventType::PrintToken,
            'payload' => ['serialRef' => 'local:x', 'format' => '58', 'copies' => 1],
            'status' => OfflineEventStatus::Pending,
            'attempts' => 0,
            'client_occurred_at' => now(),
        ];
    }
}
