<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** payments.gateway (SCHEMA §3.5) — the three drivers of BRIEF §5.I. */
enum PaymentGateway: string
{
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Sslcommerz = 'sslcommerz';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function method(): PaymentMethod
    {
        return match ($this) {
            self::Bkash => PaymentMethod::Bkash,
            self::Nagad => PaymentMethod::Nagad,
            self::Sslcommerz => PaymentMethod::Sslcommerz,
        };
    }
}
