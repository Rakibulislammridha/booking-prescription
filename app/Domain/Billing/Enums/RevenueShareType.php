<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** doctor_revenue_shares.share_type (SCHEMA §3.5). */
enum RevenueShareType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
