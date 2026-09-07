<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Clinic\Enums\LeaveType;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorLeave;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorLeave> */
final class DoctorLeaveFactory extends Factory
{
    protected $model = DoctorLeave::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = now()->addDays($this->faker->numberBetween(1, 60));

        return [
            'doctor_id' => Doctor::factory(),
            'branch_id' => null,
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->addDays(2)->toDateString(),
            'type' => LeaveType::Planned,
            'reason' => 'ছুটি',
            'notify_patients' => true,
            'is_cancelled' => false,
        ];
    }

    public function emergency(): static
    {
        return $this->state(fn () => ['type' => LeaveType::Emergency, 'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString()]);
    }
}
