<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** discounts.type and coupons.type (SCHEMA §3.5). */
enum DiscountType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
