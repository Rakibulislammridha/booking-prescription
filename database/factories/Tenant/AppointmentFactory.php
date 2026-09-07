<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\SessionInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A confirmed counter booking written straight into the table (no serial). Prefer BookAppointment in booking tests.
 *
 * @extends Factory<Appointment>
 */
final class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'session_instance_id' => SessionInstance::factory(),
            'doctor_id' => fn (array $a) => (int) SessionInstance::query()->whereKey($a['session_instance_id'])->value('doctor_id'),
            'branch_id' => fn (array $a) => (int) SessionInstance::query()->whereKey($a['session_instance_id'])->value('branch_id'),
            'scheduled_date' => fn (array $a) => SessionInstance::query()->whereKey($a['session_instance_id'])->value('session_date'),
            'serial_id' => null,
            'type' => AppointmentType::New,
            'channel' => BookingChannel::Counter,
            'status' => AppointmentStatus::Confirmed,
            'list_fee_paisa' => 80000,
            'fee_paisa' => 80000,
            'fee_rule' => FeeRule::New,
            'fee_rule_reason' => null,
            'payment_status' => PaymentStatus::Unpaid,
            'booked_by_patient' => false,
            'is_telemedicine' => false,
            'confirmed_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => AppointmentStatus::Completed]);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['payment_status' => PaymentStatus::Paid]);
    }
}
