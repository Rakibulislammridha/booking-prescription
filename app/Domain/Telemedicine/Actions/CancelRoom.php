<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Actions;

use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Services\VideoProviderManager;
use App\Models\Tenant\TelemedicineRoom;

/**
 * The appointment fell away (cancelled serial, no-show, cancelled session): the room stops accepting joins and
 * the link in the patient's SMS becomes inert. Idempotent — a terminal room is left alone.
 */
final class CancelRoom
{
    public function __construct(private readonly VideoProviderManager $providers) {}

    public function handle(TelemedicineRoom $room): TelemedicineRoom
    {
        if ($room->status->isTerminal()) {
            return $room;
        }

        $wasOpen = $room->status === RoomStatus::Open;
        $room->forceFill(['status' => RoomStatus::Cancelled, 'ended_at' => now()])->save();

        if ($wasOpen) {
            $this->providers->driver()->closeRoom($room->room_name);
        }

        return $room;
    }
}
