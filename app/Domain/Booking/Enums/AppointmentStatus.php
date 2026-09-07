<?php

declare(strict_types=1);

namespace App\Domain\Booking\Enums;

use App\Domain\Serials\Enums\SerialStatus;

/** appointments.status — mirrors the serial state machine plus draft/pending (SCHEMA §3.3). */
enum AppointmentStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InConsultation = 'in_consultation';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Postponed = 'postponed';

    /** The appointment status that mirrors a serial status (BookingServiceProvider listens to SerialStatusChanged). */
    public static function fromSerial(SerialStatus $status): self
    {
        return match ($status) {
            SerialStatus::Booked => self::Confirmed,
            SerialStatus::CheckedIn => self::CheckedIn,
            SerialStatus::InConsultation => self::InConsultation,
            SerialStatus::Completed => self::Completed,
            SerialStatus::NoShow => self::NoShow,
            SerialStatus::Cancelled => self::Cancelled,
            SerialStatus::Postponed => self::Postponed,
        };
    }

    public function isLive(): bool
    {
        return ! in_array($this, [self::Cancelled, self::NoShow], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
