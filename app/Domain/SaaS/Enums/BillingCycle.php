<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
