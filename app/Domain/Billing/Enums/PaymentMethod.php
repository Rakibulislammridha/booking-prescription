<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** payments.method and refunds.method (SCHEMA §3.5). */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Sslcommerz = 'sslcommerz';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** The three online methods, which must carry a `gateway` (the payments CHECK). */
    public function gateway(): ?PaymentGateway
    {
        return match ($this) {
            self::Bkash => PaymentGateway::Bkash,
            self::Nagad => PaymentGateway::Nagad,
            self::Sslcommerz => PaymentGateway::Sslcommerz,
            default => null,
        };
    }

    public function isOnline(): bool
    {
        return $this->gateway() !== null;
    }

    /** Cash moves the drawer; every other method is only informational on the shift close. */
    public function movesCashDrawer(): bool
    {
        return $this === self::Cash;
    }
}
