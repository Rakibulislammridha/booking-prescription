<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Scheduling\Enums\ScheduleMode;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorSchedule> */
final class DoctorScheduleFactory extends Factory
{
    protected $model = DoctorSchedule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'branch_id' => fn () => Branch::query()->where('is_main', true)->value('id') ?? Branch::factory()->main()->create()->id,
            'weekday' => $this->faker->numberBetween(0, 6),
            'session_code' => 'A',
            'session_label' => 'Morning',
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
            'mode' => ScheduleMode::Serial,
            'slot_minutes' => null,
            'max_serials' => 25,
            'online_quota' => 10,
            'counter_quota' => 10,
            'buffer_quota' => 5,
            'avg_consult_minutes' => 6,
            'fee_new_paisa' => null,
            'fee_followup_paisa' => null,
            'auto_noshow_after' => null,
            'works_on_holidays' => false,
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ];
    }

    /** Quotas C/O/B (max_serials follows the CHECK). */
    public function quotas(int $counter, int $online, int $buffer = 5): static
    {
        return $this->state(fn () => ['counter_quota' => $counter, 'online_quota' => $online, 'buffer_quota' => $buffer, 'max_serials' => $counter + $online + $buffer]);
    }

    public function evening(): static
    {
        return $this->state(fn () => ['session_code' => 'B', 'session_label' => 'Evening', 'start_time' => '17:00:00', 'end_time' => '21:00:00']);
    }

    public function slotMode(int $slotMinutes = 15): static
    {
        return $this->state(fn () => ['mode' => ScheduleMode::Slot, 'slot_minutes' => $slotMinutes]);
    }

    public function everyDay(): static
    {
        return $this->sequence(...array_map(fn (int $d) => ['weekday' => $d], range(0, 6)));
    }
}
