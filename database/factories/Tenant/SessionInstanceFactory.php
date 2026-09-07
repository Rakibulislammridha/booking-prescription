<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Scheduling\Enums\ScheduleMode;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Services\PoolLayout;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A session instance WITH its three pools (afterCreating), laid out per SERIAL_ENGINE §3.1 from the quotas.
 *
 * @extends Factory<SessionInstance>
 */
final class SessionInstanceFactory extends Factory
{
    protected $model = SessionInstance::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $date = Clock::today()->addDays($this->faker->numberBetween(0, 10));

        return [
            'branch_id' => fn () => Branch::query()->where('is_main', true)->value('id') ?? Branch::factory()->main()->create()->id,
            'doctor_id' => Doctor::factory(),
            'session_date' => $date->toDateString(),
            'session_code' => 'A',
            'doctor_schedule_id' => null,
            'mode' => ScheduleMode::Serial,
            'slot_minutes' => null,
            'status' => SessionStatus::Scheduled,
            'planned_start_at' => $date->setTime(9, 0)->utc(),
            'planned_end_at' => $date->setTime(13, 0)->utc(),
            'delay_minutes' => 0,
            'pause_seconds' => 0,
            'max_serials' => 25,
            'online_quota' => 10,
            'counter_quota' => 10,
            'buffer_quota' => 5,
            'avg_consult_seconds' => 360,
            'consult_samples' => 0,
            'auto_noshow_after' => 3,
            'fee_new_paisa' => 80000,
            'fee_followup_paisa' => 50000,
            'version' => 1,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (SessionInstance $instance): void {
            if ($instance->pools()->count() === 0) {
                PoolLayout::createFor($instance->id, $instance->counter_quota, $instance->online_quota, $instance->buffer_quota);
            }
        });
    }

    /** Today's instance, open for allocation (the concurrency fixture of CONVENTIONS §6.5). */
    public function openToday(): static
    {
        $today = Clock::today();

        return $this->state(fn () => [
            'session_date' => $today->toDateString(),
            'planned_start_at' => $today->setTime(9, 0)->utc(),
            'planned_end_at' => $today->setTime(13, 0)->utc(),
            'status' => SessionStatus::Scheduled,
        ]);
    }

    public function on(CarbonImmutable $date, string $code = 'A', string $start = '09:00', string $end = '13:00'): static
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        $local = $date->setTimezone(Clock::timezone())->startOfDay();

        return $this->state(fn () => [
            'session_date' => $local->toDateString(),
            'session_code' => $code,
            'planned_start_at' => $local->setTime($sh, $sm)->utc(),
            'planned_end_at' => $local->setTime($eh, $em)->utc(),
        ]);
    }

    /** Quotas C/O/B (max_serials follows the CHECK); pools are laid out from these. */
    public function quotas(int $counter, int $online, int $buffer = 5): static
    {
        return $this->state(fn () => ['counter_quota' => $counter, 'online_quota' => $online, 'buffer_quota' => $buffer, 'max_serials' => $counter + $online + $buffer]);
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => SessionStatus::Running, 'actual_start_at' => now()]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => SessionStatus::Closed, 'actual_end_at' => now()]);
    }

    public function slotMode(int $slotMinutes = 15): static
    {
        return $this->state(fn () => ['mode' => ScheduleMode::Slot, 'slot_minutes' => $slotMinutes]);
    }
}
