<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Domain\Telemedicine\Services\RoomName;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\TelemedicineRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A scheduled room for a telemedicine appointment. Prefer `OpenRoom` in flow tests — this exists for the read
 * screens and for the isolation/records assertions.
 *
 * @extends Factory<TelemedicineRoom>
 */
final class TelemedicineRoomFactory extends Factory
{
    protected $model = TelemedicineRoom::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory()->state(['channel' => BookingChannel::Telemedicine, 'is_telemedicine' => true]),
            'provider' => TelemedicineProvider::Jitsi,
            'room_name' => RoomName::generate(),
            'status' => RoomStatus::Scheduled,
            'scheduled_at' => now()->addHour(),
            'settings' => ['recording' => false, 'max_minutes' => 45],
        ];
    }

    public function open(): static
    {
        return $this->state(fn (): array => ['status' => RoomStatus::Open, 'opened_at' => now(), 'scheduled_at' => now()]);
    }

    public function ended(): static
    {
        return $this->state(fn (): array => ['status' => RoomStatus::Ended, 'opened_at' => now()->subMinutes(20), 'ended_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => RoomStatus::Cancelled, 'ended_at' => now()]);
    }
}
