<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\Visit;

/**
 * The one place this module reads the Prescription module's `visits` table. It reads only — the visit is created
 * by Prescription's own `StartVisit` (BRIEF §5.K: no separate prescription path), and everything downstream of
 * it (the draft, the safety pipeline, issuing, the PDF) is the ordinary writer's.
 */
final class VisitLink
{
    public function idForSerial(?int $serialId): ?int
    {
        if ($serialId === null) {
            return null;
        }

        $id = Visit::query()->where('serial_id', $serialId)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function forRoom(TelemedicineRoom $room): ?Visit
    {
        $appointment = $room->appointment;

        return Visit::query()
            ->where(fn ($q) => $q->where('appointment_id', $appointment->id)->orWhere('serial_id', $appointment->serial_id))
            ->orderByDesc('id')
            ->first();
    }
}
