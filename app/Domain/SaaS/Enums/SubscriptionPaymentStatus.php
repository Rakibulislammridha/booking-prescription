<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum SubscriptionPaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
