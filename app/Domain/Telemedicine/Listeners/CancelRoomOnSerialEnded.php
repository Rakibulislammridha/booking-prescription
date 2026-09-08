<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Listeners;

use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Events\SerialNoShow;
use App\Domain\Telemedicine\Actions\CancelRoom;
use App\Models\Tenant\TelemedicineRoom;
use App\Tenancy\Facades\Tenancy;

/**
 * The serial went away, so the room must too: a cancelled or no-showed appointment leaves a signed link in a
 * patient's SMS inbox, and that link must stop working the moment the appointment does.
 */
final class CancelRoomOnSerialEnded
{
    public function __construct(private readonly CancelRoom $cancelRoom) {}

    public function handle(SerialCancelled|SerialNoShow $event): void
    {
        $appointmentId = $event->serial['appointment_id'] ?? null;

        if (! Tenancy::check() || ! is_int($appointmentId)) {
            return;
        }

        $room = TelemedicineRoom::query()->where('appointment_id', $appointmentId)->first();

        if ($room !== null) {
            $this->cancelRoom->handle($room);
        }
    }
}
