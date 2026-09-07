<?php

declare(strict_types=1);

namespace App\Domain\Booking\Enums;

/** appointments.payment_status — written by Billing (and by the null CashCollector until Billing ships). */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Refunded = 'refunded';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
