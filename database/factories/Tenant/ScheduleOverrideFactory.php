<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Scheduling\Enums\OverrideType;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\ScheduleOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduleOverride> */
final class ScheduleOverrideFactory extends Factory
{
    protected $model = ScheduleOverride::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'branch_id' => fn () => Branch::query()->where('is_main', true)->value('id') ?? Branch::factory()->main()->create()->id,
            'override_date' => now()->addDays($this->faker->numberBetween(1, 30))->toDateString(),
            'session_code' => null,
            'type' => OverrideType::LateStart,
            'delay_minutes' => 30,
            'reason' => 'ডাক্তার দেরিতে আসবেন',
            'notify_patients' => true,
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['type' => OverrideType::Cancelled, 'delay_minutes' => null]);
    }

    public function extraSession(string $code = 'C', string $start = '15:00:00', string $end = '17:00:00', int $counter = 10, int $online = 10, int $buffer = 5): static
    {
        return $this->state(fn () => [
            'type' => OverrideType::ExtraSession, 'session_code' => $code, 'delay_minutes' => null,
            'new_start_time' => $start, 'new_end_time' => $end,
            'new_counter_quota' => $counter, 'new_online_quota' => $online, 'new_buffer_quota' => $buffer, 'new_max_serials' => $counter + $online + $buffer,
        ]);
    }

    public function capacityChange(int $counter, int $online, int $buffer): static
    {
        return $this->state(fn () => [
            'type' => OverrideType::CapacityChange, 'delay_minutes' => null,
            'new_counter_quota' => $counter, 'new_online_quota' => $online, 'new_buffer_quota' => $buffer, 'new_max_serials' => $counter + $online + $buffer,
        ]);
    }

    public function timeChange(string $start, string $end): static
    {
        return $this->state(fn () => ['type' => OverrideType::TimeChange, 'delay_minutes' => null, 'new_start_time' => $start, 'new_end_time' => $end]);
    }
}
