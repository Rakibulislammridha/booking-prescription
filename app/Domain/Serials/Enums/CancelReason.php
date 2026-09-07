<?php

declare(strict_types=1);

namespace App\Domain\Serials\Enums;

enum CancelReason: string
{
    case PatientRequest = 'patient_request';
    case DoctorUnavailable = 'doctor_unavailable';
    case Duplicate = 'duplicate';
    case Transferred = 'transferred';
    case NoPayment = 'no_payment';
    case SessionCancelled = 'session_cancelled';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
