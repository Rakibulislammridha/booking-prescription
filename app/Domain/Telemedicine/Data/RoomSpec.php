<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Data;

/** What a room needs to exist at the provider. Everything here is derived from the appointment, never from input. */
final readonly class RoomSpec
{
    public function __construct(
        public string $roomName,
        public int $maxParticipants = 4,
        public int $emptyTimeoutSeconds = 900,
        public int $maxMinutes = 45,
        public bool $recording = false,
    ) {}

    /** @return array<string, mixed> the `settings` jsonb SCHEMA §3.8 stores on the row */
    public function toSettings(): array
    {
        return ['recording' => $this->recording, 'max_minutes' => $this->maxMinutes];
    }
}
