<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A live call attempt with the doctor already connected.
 *
 * @extends Factory<TelemedicineSession>
 */
final class TelemedicineSessionFactory extends Factory
{
    protected $model = TelemedicineSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telemedicine_room_id' => TelemedicineRoom::factory()->open(),
            'visit_id' => null,
            'started_at' => now(),
            'participants' => [
                ['role' => ParticipantRole::Doctor->value, 'joined_at' => now()->toIso8601String(), 'left_at' => null, 'device' => 'panel'],
            ],
        ];
    }

    public function completed(int $seconds = 600): static
    {
        return $this->state(fn (): array => [
            'started_at' => now()->subSeconds($seconds),
            'ended_at' => now(),
            'duration_seconds' => $seconds,
            'end_reason' => SessionEndReason::Completed,
        ]);
    }

    public function dropped(): static
    {
        return $this->state(fn (): array => ['ended_at' => now(), 'duration_seconds' => 30, 'end_reason' => SessionEndReason::Dropped]);
    }
}
