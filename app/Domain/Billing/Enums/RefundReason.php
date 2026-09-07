<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

use App\Domain\Serials\Enums\CancelReason;

/** refunds.reason_code (SCHEMA §3.5) — BRIEF §5.F "refund and cancellation with reason codes". */
enum RefundReason: string
{
    case DoctorAbsent = 'doctor_absent';
    case PatientCancelled = 'patient_cancelled';
    case Duplicate = 'duplicate';
    case ServiceNotRendered = 'service_not_rendered';
    case Goodwill = 'goodwill';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** The serial-engine cancellation reason mapped onto a refund reason code. */
    public static function fromCancelReason(CancelReason $reason): self
    {
        return match ($reason) {
            CancelReason::DoctorUnavailable, CancelReason::SessionCancelled => self::DoctorAbsent,
            CancelReason::PatientRequest => self::PatientCancelled,
            CancelReason::Duplicate => self::Duplicate,
            CancelReason::Transferred, CancelReason::NoPayment => self::ServiceNotRendered,
            CancelReason::Other => self::Other,
        };
    }
}
