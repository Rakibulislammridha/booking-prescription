<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** discounts.reason_code (SCHEMA §3.5) — BRIEF §5.I "discount with reason". */
enum DiscountReason: string
{
    case Staff = 'staff';
    case PoorFund = 'poor_fund';
    case Followup = 'followup';
    case DoctorWaiver = 'doctor_waiver';
    case Promo = 'promo';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
